<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
require_once __DIR__.'/ionos-storage.php';
require_once __DIR__.'/case-rules.php';
commonHeaders();
$user = requireAuth();
if (($user['role'] ?? '') !== 'administrator') apiError(403, 'Nur Administratoren dürfen verbindliche Vorgaben importieren.');
try {
    if (!ionosStorageEnabled()) throw new RuntimeException('Der geprüfte IONOS-Dateibestand ist nicht aktiv.');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
        $root = caKnowledgeRoot(); $files = []; $ready = false; $message = '';
        try {
            $files = caIonosRuleFiles(false);
            $ready = (bool)array_filter($files, fn($file)=>preg_match('~/MASTER-ARBEITSSTANDARD\.md$~i', $file['path']));
            if (!$ready) $message = 'MASTER-ARBEITSSTANDARD.md fehlt. Bitte das verbindliche Original importieren.';
        }
        catch (RuntimeException $error) { $message = $error->getMessage(); }
        apiJson(['ok'=>true, 'root'=>$root, 'ready'=>$ready, 'message'=>$message,
            'files'=>array_map(fn($file)=>['id'=>$file['id'], 'path'=>$file['path'], 'size'=>$file['size'] ?? null], $files)]);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') apiError(405, 'Nur GET und POST sind zulässig.');
    $origin = parse_url((string)($_SERVER['HTTP_ORIGIN'] ?? ''), PHP_URL_HOST);
    if (!$origin || strcasecmp($origin, explode(':', (string)($_SERVER['HTTP_HOST'] ?? ''))[0]) !== 0) apiError(403, 'Import nur aus dem Portal zulässig.');
    $file = $_FILES['file'] ?? [];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
        apiError(400, 'Originaldatei wurde nicht vollständig hochgeladen.');
    }
    if ((int)($file['size'] ?? 0) > 30*1024*1024) apiError(413, 'Originaldatei größer als 30 MB.');
    $bytes = file_get_contents($file['tmp_name']);
    if ($bytes === false) throw new RuntimeException('Originaldatei konnte nicht gelesen werden.');
    $lockPath = ionosStorageRoot().'/case-rules-import.lock';
    $handle = fopen($lockPath, 'c');
    if ($handle === false) throw new RuntimeException('Importsperre konnte nicht geöffnet werden.');
    try {
        if (!flock($handle, LOCK_EX)) throw new RuntimeException('Importsperre konnte nicht gesetzt werden.');
        $result = caImportRule((string)$file['name'], $bytes, (string)($_POST['target'] ?? ''), ($_POST['replace'] ?? '') === '1');
    } finally { flock($handle, LOCK_UN); fclose($handle); }
    apiJson(['ok'=>true, 'file'=>$result]);
} catch (Throwable $error) {
    error_log('[case-rules-import] '.$error->getMessage());
    apiError(422, $error->getMessage());
}
