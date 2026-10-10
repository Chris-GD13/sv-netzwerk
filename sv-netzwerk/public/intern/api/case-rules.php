<?php
declare(strict_types=1);
require_once __DIR__.'/case-analysis-core.php';

function caKnowledgeRoot(): array {
    $id = env('GOOGLE_DRIVE_KNOWLEDGE_FOLDER_ID', '1QeJ4Dz6Upg_W5rahWmKE_7KbC4MMgSGe');
    $root = ionosItem($id);
    if (($root['mimeType'] ?? '') !== 'application/vnd.google-apps.folder') {
        throw new RuntimeException('Die konfigurierte IONOS-Wissensbasis ist kein Ordner.');
    }
    return $root;
}

function caIonosRuleFiles(bool $requireMaster = true): array {
    $root = caKnowledgeRoot();
    $roots = [$root['id']=>$root];
    foreach (CA_RULE_ROOTS as $name) {
        $found = caDriveList("mimeType='application/vnd.google-apps.folder' and trashed=false and name='".$name."'");
        if ($name === '00_KI-Wissensbasis') continue;
        foreach ($found as $folder) $roots[$folder['id']] = $folder;
    }
    $queue = [];
    foreach ($roots as $folder) $queue[] = [$folder['id'], $folder['name'], 0];
    $seen = []; $files = []; $count = 0;
    while ($queue) {
        [$parent, $path, $depth] = array_shift($queue);
        if (isset($seen[$parent])) continue;
        $seen[$parent] = true;
        foreach (caDriveList("'".str_replace("'", "\\'", $parent)."' in parents and trashed=false") as $file) {
            if (++$count > 10000) throw new RuntimeException('IONOS-Regelbestand zu groß; keine gekürzte Ausarbeitung.');
            $file['path'] = $path.'/'.$file['name'];
            if (($file['mimeType'] ?? '') === 'application/vnd.google-apps.folder') {
                if ($depth >= 20) throw new RuntimeException('IONOS-Regelbestand zu tief verschachtelt.');
                $queue[] = [$file['id'], $file['path'], $depth + 1];
            } else {
                $files[$file['id']] = $file;
            }
        }
    }
    if ($requireMaster && !array_filter($files, fn($file)=>strcasecmp($file['name'], 'MASTER-ARBEITSSTANDARD.md') === 0)) {
        throw new RuntimeException('Verbindlicher MASTER-ARBEITSSTANDARD.md fehlt in der IONOS-Wissensbasis. Original im Versicherungswissen importieren.');
    }
    return array_values($files);
}

function caImportRule(string $name, string $bytes, string $target, bool $replace): array {
    if (!in_array($target, ['standards', 'knowledge', 'project', 'templates'], true)) {
        throw new RuntimeException('Ungültiger Regelbereich.');
    }
    if ($name === '' || $name !== basename(str_replace('\\', '/', $name)) || preg_match('/[\x00-\x1f\/\\\\]/', $name)
        || !preg_match('/\.(md|txt|pdf|docx|xlsx|pptx)$/i', $name)) {
        throw new RuntimeException('Nur MD, TXT, PDF, DOCX, XLSX und PPTX mit einfachem Dateinamen sind zulässig.');
    }
    if ($bytes === '' || strlen($bytes) > 30*1024*1024) throw new RuntimeException('Originaldatei leer oder größer als 30 MB.');
    if (preg_match('/\.(md|txt)$/i', $name) && !mb_check_encoding($bytes, 'UTF-8')) {
        throw new RuntimeException('Textvorgaben müssen vollständig als UTF-8 vorliegen.');
    }
    $root = caKnowledgeRoot(); $parent = $root['id'];
    $segments = match($target) {
        'standards'=>['00_Standards_Regeln', 'ab sofort immer gültig'],
        'project'=>['SV-Netzwerk-Projekt'],
        'templates'=>['Originalvorlagen'],
        default=>[],
    };
    foreach ($segments as $segment) {
        $folders = caDriveList("'".str_replace("'", "\\'", $parent)."' in parents and trashed=false and mimeType='application/vnd.google-apps.folder' and name='".$segment."'");
        if (count($folders) > 1) throw new RuntimeException('Mehrdeutiger Import-Zielordner: '.$segment);
        $parent = $folders ? $folders[0]['id'] : ionosWrite(['name'=>$segment, 'mimeType'=>'application/vnd.google-apps.folder', 'parents'=>[$parent]])['id'];
    }
    $existing = caDriveList("'".str_replace("'", "\\'", $parent)."' in parents and trashed=false and name='".str_replace("'", "\\'", $name)."'");
    if (count($existing) > 1) throw new RuntimeException('Mehrere gleichnamige Originale vorhanden: '.$name);
    $hash = hash('sha256', $bytes);
    if ($existing && hash('sha256', ionosBytes($existing[0]['id'])) === $hash) {
        return ['id'=>$existing[0]['id'], 'name'=>$name, 'duplicate'=>true, 'sha256'=>$hash];
    }
    if ($existing && !$replace) throw new RuntimeException('Andere Fassung vorhanden: '.$name.'. Ersetzen ausdrücklich bestätigen.');
    $mime = match(strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
        'md'=>'text/markdown', 'txt'=>'text/plain', 'pdf'=>'application/pdf',
        'docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx'=>'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    };
    $saved = ionosWrite(['name'=>$name, 'mimeType'=>$mime, 'parents'=>[$parent]], $bytes, $existing[0]['id'] ?? null);
    if (hash('sha256', ionosBytes($saved['id'])) !== $hash) throw new RuntimeException('IONOS-Originalprüfung nach Import fehlgeschlagen: '.$name);
    return ['id'=>$saved['id'], 'name'=>$name, 'duplicate'=>false, 'sha256'=>$hash];
}
