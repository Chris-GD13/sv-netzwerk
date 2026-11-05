<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
require_once __DIR__.'/ionos-storage.php';
require_once __DIR__.'/case-rules.php';
require_once __DIR__.'/case-rules-cleanup-core.php';
require_once __DIR__.'/administration-access.php';
commonHeaders();
$user = requireAuth();

function crcRequireAdmin(array $user): void
{
    if (($user['role'] ?? '') !== 'administrator' || !svnetCanManageWorkRules($user)) {
        apiError(403, 'Der IONOS-Regelbestand darf ausschließlich von Administratoren bereinigt werden.');
    }
}

crcRequireAdmin($user);
if (!ionosStorageEnabled()) apiError(503, 'Der geprüfte IONOS-Dateibestand ist nicht aktiv.');

function crcChildFolder(string $parent, string $name, bool $create = false): array
{
    $escaped = str_replace("'", "\\'", $name);
    $items = caDriveList("'".str_replace("'", "\\'", $parent)."' in parents and trashed=false and mimeType='application/vnd.google-apps.folder' and name='".$escaped."'");
    if (count($items) > 1) throw new RuntimeException('Mehrdeutiger IONOS-Ordner: '.$name);
    if ($items) return $items[0];
    if (!$create) throw new RuntimeException('IONOS-Ordner fehlt: '.$name);
    return ionosWrite(['name'=>$name, 'mimeType'=>'application/vnd.google-apps.folder', 'parents'=>[$parent]], null);
}

function crcRoots(): array
{
    $knowledge = caKnowledgeRoot();
    return [
        '00_Standards_Regeln'=>crcChildFolder((string)$knowledge['id'], '00_Standards_Regeln'),
        '00_KI-Wissensbasis'=>$knowledge,
        'SV-Netzwerk-Projekt'=>crcChildFolder((string)$knowledge['id'], 'SV-Netzwerk-Projekt'),
    ];
}

function crcInventory(): array
{
    $roots = crcRoots();
    $out = [];
    foreach ($roots as $root) {
        $queue = [[(string)$root['id'], (string)$root['name'], 0]];
        $seen = [];
        while ($queue) {
            [$parent, $path, $depth] = array_shift($queue);
            if (isset($seen[$parent])) continue;
            $seen[$parent] = true;
            if ($depth > 20) throw new RuntimeException('IONOS-Regelbestand zu tief verschachtelt.');
            foreach (caDriveList("'".str_replace("'", "\\'", $parent)."' in parents and trashed=false") as $file) {
                $filePath = $path.'/'.(string)$file['name'];
                if (($file['mimeType'] ?? '') === 'application/vnd.google-apps.folder') {
                    $queue[] = [(string)$file['id'], $filePath, $depth + 1];
                    continue;
                }
                if (strcasecmp((string)($file['name'] ?? ''), CRC_MASTER_NAME) !== 0) continue;
                $bytes = ionosBytes((string)$file['id']);
                $out[] = crcClassify($filePath, (string)$file['name'], strlen($bytes), hash('sha256', $bytes), CRC_MASTER_CANONICAL_PATH);
                $out[array_key_last($out)]['id'] = (string)$file['id'];
                $out[array_key_last($out)]['parent_id'] = $parent;
            }
        }
    }
    return $out;
}

const CRC_MASTER_CANONICAL_PATH = '00_Standards_Regeln/ab sofort immer gültig/MASTER-ARBEITSSTANDARD.md';
const CRC_ARCHIVE_PATH = '00_Standards_Regeln/ARCHIV – NICHT AKTIV/2026-11-05';

function crcRunDry(): array
{
    $files = crcInventory();
    $plan = crcBuildPlan($files, CRC_MASTER_CANONICAL_PATH);
    return ['ok'=>true, 'canonical_path'=>CRC_MASTER_CANONICAL_PATH, 'archive_path'=>CRC_ARCHIVE_PATH, 'files'=>$files, 'plan'=>$plan];
}

function crcMoveToArchive(array $file, string $archiveFolder): array
{
    $id = (string)($file['id'] ?? '');
    $bytes = ionosBytes($id);
    $archiveName = crcArchiveName((string)($file['path'] ?? ''), $id);
    $moved = ionosWrite(['name'=>$archiveName, 'parents'=>[$archiveFolder], 'appProperties'=>array_replace((array)($file['appProperties'] ?? []), ['svArchivedFrom'=>(string)($file['path'] ?? ''), 'svArchivedAt'=>gmdate('c')])], null, $id);
    $after = ionosItem($id);
    if ((string)($after['parents'][0] ?? '') !== $archiveFolder || (string)$after['name'] !== $archiveName || hash('sha256', ionosBytes($id)) !== hash('sha256', $bytes)) {
        throw new RuntimeException('Archivierung konnte nicht unabhängig rückgelesen werden: '.$id);
    }
    return ['id'=>$id, 'from'=>$file['path'] ?? '', 'to'=>CRC_ARCHIVE_PATH.'/'.$archiveName, 'sha256'=>hash('sha256', $bytes)];
}

function crcApply(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) throw new RuntimeException('Die Masterdatei wurde nicht vollständig hochgeladen.');
    $bytes = file_get_contents((string)$file['tmp_name']);
    if (!is_string($bytes) || $bytes === '') throw new RuntimeException('Die Masterdatei ist leer.');
    $hash = strtoupper(hash('sha256', $bytes));
    if (!hash_equals(CRC_MASTER_SHA256, $hash)) throw new RuntimeException('Hash-Abweichung: Die hochgeladene Masterdatei entspricht nicht der erwarteten Drive-Fassung.');
    $roots = crcRoots();
    $standards = $roots['00_Standards_Regeln'];
    $canonicalFolder = crcChildFolder((string)$standards['id'], 'ab sofort immer gültig');
    $archiveRoot = crcChildFolder((string)$standards['id'], 'ARCHIV – NICHT AKTIV', true);
    $archiveFolder = crcChildFolder((string)$archiveRoot['id'], '2026-11-05', true);
    $lockPath = ionosStorageRoot().'/case-rules-cleanup.lock';
    $handle = fopen($lockPath, 'c');
    if ($handle === false || !flock($handle, LOCK_EX)) throw new RuntimeException('Bereinigungssperre konnte nicht gesetzt werden.');
    try {
        $files = crcInventory();
        $keptCanonicalId = null;
        $archived = [];
        foreach ($files as $item) {
            $isCanonical = strcasecmp((string)$item['path'], CRC_MASTER_CANONICAL_PATH) === 0;
            $isExpected = hash_equals(CRC_MASTER_SHA256, strtoupper((string)$item['sha256']));
            if (!$isCanonical || !$isExpected || ($keptCanonicalId !== null && (string)$item['id'] !== $keptCanonicalId)) {
                $archived[] = crcMoveToArchive($item, (string)$archiveFolder['id']);
            } else {
                $keptCanonicalId = (string)$item['id'];
            }
        }
        $remaining = array_values(array_filter(crcInventory(), static fn(array $item): bool => strcasecmp((string)$item['path'], CRC_MASTER_CANONICAL_PATH) === 0));
        if (!$remaining) {
            $saved = ionosWrite(['name'=>CRC_MASTER_NAME, 'mimeType'=>'text/markdown', 'parents'=>[(string)$canonicalFolder['id']]], $bytes);
            $remaining = [['id'=>$saved['id'], 'path'=>CRC_MASTER_CANONICAL_PATH, 'sha256'=>hash('sha256', ionosBytes((string)$saved['id']))]];
        }
        if (count($remaining) !== 1 || !hash_equals(CRC_MASTER_SHA256, strtoupper((string)$remaining[0]['sha256']))) throw new RuntimeException('Nach der Bereinigung blieb nicht genau eine gültige aktive Masterkopie übrig.');
        $releaseText = crcReleaseText();
        $releaseFiles = caDriveList("'".str_replace("'", "\\'", (string)$canonicalFolder['id'])."' in parents and trashed=false and name='SV-Assistent-Regelrelease.txt'");
        if (count($releaseFiles) > 1) foreach (array_slice($releaseFiles, 1) as $release) ionosWrite(['name'=>'Archiv_'.$release['id'].'_SV-Assistent-Regelrelease.txt', 'parents'=>[(string)$archiveFolder['id']]], null, (string)$release['id']);
        $releaseId = $releaseFiles[0]['id'] ?? null;
        $savedRelease = ionosWrite(['name'=>'SV-Assistent-Regelrelease.txt', 'mimeType'=>'text/plain', 'parents'=>[(string)$canonicalFolder['id']]], $releaseText, $releaseId);
        if (ionosBytes((string)$savedRelease['id']) !== $releaseText) throw new RuntimeException('Release-Datei konnte nicht rückgelesen werden.');
        return ['ok'=>true, 'canonical'=>['path'=>CRC_MASTER_CANONICAL_PATH, 'id'=>$remaining[0]['id'], 'sha256'=>$remaining[0]['sha256']], 'archived'=>$archived, 'archive_folder'=>CRC_ARCHIVE_PATH, 'release'=>['id'=>$savedRelease['id'], 'text'=>$releaseText]];
    } finally { flock($handle, LOCK_UN); fclose($handle); }
}

$action = (string)($_GET['action'] ?? 'dry_run');
try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'dry_run') apiJson(crcRunDry());
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $action !== 'apply') apiError(405, 'Nur GET?action=dry_run und POST?action=apply sind zulässig.');
    $origin = parse_url((string)($_SERVER['HTTP_ORIGIN'] ?? ''), PHP_URL_HOST);
    if (!$origin || strcasecmp($origin, explode(':', (string)($_SERVER['HTTP_HOST'] ?? ''))[0]) !== 0) apiError(403, 'Bereinigung nur aus dem Portal zulässig.');
    apiJson(crcApply($_FILES['file'] ?? []));
} catch (Throwable $error) {
    error_log('[case-rules-cleanup] '.$error->getMessage());
    apiError(422, $error->getMessage());
}
