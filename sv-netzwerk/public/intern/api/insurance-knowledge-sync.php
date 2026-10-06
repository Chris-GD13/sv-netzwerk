<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
commonHeaders();
$user = requireAuth();
if (!in_array($user['role'] ?? '', ['administrator', 'projektleiter'], true)) apiError(403, 'Keine Berechtigung für die Bestandsaktualisierung.');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') apiError(405, 'Nur POST ist zulässig.');

$payload = json_decode((string)file_get_contents('php://input'), true);
$password = is_array($payload) ? trim((string)($payload['password'] ?? '')) : '';
if ($password === '') apiError(422, 'Passwort für die Originalfreigabe fehlt.');

$token = 'BBCwxbB4tyRdi6M';
$source = 'https://cloud.allriskportal.de/public.php/dav/files/' . $token . '/?accept=zip';
$tmp = tempnam(sys_get_temp_dir(), 'sv-knowledge-');
if ($tmp === false) apiError(500, 'Temporäre Importdatei konnte nicht angelegt werden.');
$fp = fopen($tmp, 'wb');
$curl = curl_init($source);
curl_setopt_array($curl, [
    CURLOPT_FILE => $fp,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
    CURLOPT_USERPWD => $token . ':' . $password,
    CURLOPT_FAILONERROR => false,
    CURLOPT_TIMEOUT => 300,
    CURLOPT_CONNECTTIMEOUT => 20,
]);
$ok = curl_exec($curl);
$status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
$error = curl_error($curl);
curl_close($curl);
fclose($fp);
if ($ok === false || $status !== 200) { @unlink($tmp); apiError(502, 'Originalfreigabe konnte nicht gelesen werden.' . ($status ? ' HTTP ' . $status . '.' : '')); }

$zip = new ZipArchive();
if ($zip->open($tmp) !== true) { @unlink($tmp); apiError(502, 'Originalfreigabe lieferte kein gültiges ZIP.'); }
$files = [];
for ($i = 0; $i < $zip->numFiles; $i++) {
    $entry = $zip->statIndex($i);
    $name = str_replace('\\', '/', (string)($entry['name'] ?? ''));
    if ($name === '' || str_ends_with($name, '/') || !str_starts_with($name, 'SR-Netzwerk/')) continue;
    $path = substr($name, strlen('SR-Netzwerk/'));
    if ($path === '' || str_contains($path, '../')) continue;
    $parts = explode('/', $path);
    $insurer = (string)($parts[0] ?? '');
    $fileName = (string)end($parts);
    $folder = implode('/', array_slice($parts, 0, -1));
    $stream = $zip->getStream($name);
    if (!is_resource($stream)) continue;
    $hash = hash_init('sha256'); $size = 0;
    while (!feof($stream)) { $chunk = fread($stream, 1024 * 1024); if ($chunk === false || $chunk === '') break; hash_update($hash, $chunk); $size += strlen($chunk); }
    fclose($stream);
    $segments = array_map(static fn(string $s): string => rawurlencode($s), $parts);
    $folderUrl = 'https://cloud.allriskportal.de/index.php/s/' . $token . '?dir=/' . implode('/', array_map(static fn(string $s): string => rawurlencode($s), array_filter(explode('/', $folder), 'strlen')));
    $files[] = ['path' => $path, 'name' => $fileName, 'insurer' => $insurer, 'extension' => strtolower((string)pathinfo($fileName, PATHINFO_EXTENSION)) === '' ? '' : '.' . strtolower((string)pathinfo($fileName, PATHINFO_EXTENSION)), 'size' => $size, 'sha256' => hash_final($hash), 'source_url' => 'https://cloud.allriskportal.de/public.php/dav/files/' . $token . '/' . implode('/', $segments), 'folder_url' => $folderUrl];
}
$zip->close(); @unlink($tmp);
if (!$files) apiError(502, 'Die Originalfreigabe enthält keine Unterlagen.');

$static = __DIR__ . '/insurance-knowledge-index.json';
$old = is_file($static) ? json_decode((string)file_get_contents($static), true) : [];
$data = ['generated_at' => gmdate('c'), 'source' => 'SR-Netzwerk Originalfreigabe', 'file_count' => count($files), 'files' => $files, 'sparkassen_workbook' => is_array($old) ? ($old['sparkassen_workbook'] ?? []) : []];
$storage = __DIR__ . '/storage';
if (!is_dir($storage) && !mkdir($storage, 0750, true) && !is_dir($storage)) apiError(500, 'Importordner konnte nicht angelegt werden.');
if (file_put_contents($storage . '/insurance-knowledge-live.json', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX) === false) apiError(500, 'Aktualisierter Bestand konnte nicht gespeichert werden.');
apiJson(['ok' => true, 'generated_at' => $data['generated_at'], 'file_count' => count($files)]);
