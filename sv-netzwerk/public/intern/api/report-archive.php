<?php
/**
 * Zentrale Gutachtenablage – SV-Netzwerk Prüfportal
 *
 * GET  ?project_id=1                 – archivierte Gutachten auflisten
 * GET  ?download={id}                – archiviertes Gutachten herunterladen
 * POST multipart/form-data report    – Gutachten dauerhaft in Google Drive archivieren
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
commonHeaders();
$user = requireAuth();

function reportsDir(): string
{
    $configured = env('REPORTS_DIR', '');
    $dir = $configured !== '' ? rtrim($configured, '/') : dirname(photosDir()) . '/reports';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

function reportSettingGet(string $key, string $default=''): string
{
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS app_settings (
            setting_key VARCHAR(190) PRIMARY KEY,
            setting_value MEDIUMTEXT NULL,
            updated_at DATETIME NOT NULL,
            updated_by VARCHAR(190) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $stmt = db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key=:k LIMIT 1');
        $stmt->execute([':k'=>$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : (string)$value;
    } catch (Throwable $e) {
        error_log('[report-archive] setting read failed: '.$e->getMessage());
        return $default;
    }
}

function reportSettingSet(string $key, string $value, array $user): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS app_settings (
        setting_key VARCHAR(190) PRIMARY KEY,
        setting_value MEDIUMTEXT NULL,
        updated_at DATETIME NOT NULL,
        updated_by VARCHAR(190) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $stmt = db()->prepare('INSERT INTO app_settings(setting_key,setting_value,updated_at,updated_by)
        VALUES(:k,:v,:u,:b)
        ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=VALUES(updated_at),updated_by=VALUES(updated_by)');
    $stmt->execute([
        ':k'=>$key,
        ':v'=>$value,
        ':u'=>nowUtc(),
        ':b'=>(string)($user['email'] ?? $user['full_name'] ?? ''),
    ]);
}

function reportB64url(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function reportDriveHttp(string $method, string $url, array $headers = [], ?string $body = null, bool $auth = true): array
{
    if ($auth) $headers[] = 'Authorization: Bearer ' . reportDriveAccessToken();
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HEADER => true,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($response === false || $err !== '') apiError(503, 'Google-Drive-Verbindung fehlgeschlagen: ' . ($err ?: 'unbekannter Fehler'));
    return [
        'status'=>$status,
        'headers'=>substr((string)$response, 0, $headerSize),
        'body'=>substr((string)$response, $headerSize),
    ];
}

function reportDriveAccessToken(): string
{
    static $token = null;
    if ($token !== null) return $token;

    $serviceJson = trim(env('GOOGLE_DRIVE_SERVICE_ACCOUNT_JSON', ''));
    if ($serviceJson !== '') {
        if (!str_starts_with($serviceJson, '{')) {
            $decoded = base64_decode($serviceJson, true);
            if ($decoded !== false) $serviceJson = $decoded;
        }
        $svc = json_decode($serviceJson, true);
        if (is_array($svc) && !empty($svc['client_email']) && !empty($svc['private_key'])) {
            $now = time();
            $header = reportB64url(json_encode(['alg'=>'RS256','typ'=>'JWT'], JSON_UNESCAPED_SLASHES));
            $claims = reportB64url(json_encode([
                'iss'=>$svc['client_email'],
                'scope'=>'https://www.googleapis.com/auth/drive',
                'aud'=>'https://oauth2.googleapis.com/token',
                'iat'=>$now,
                'exp'=>$now + 3500,
            ], JSON_UNESCAPED_SLASHES));
            $input = $header . '.' . $claims;
            $signature = '';
            if (openssl_sign($input, $signature, $svc['private_key'], OPENSSL_ALGO_SHA256)) {
                $jwt = $input . '.' . reportB64url($signature);
                $resp = reportDriveHttp('POST', 'https://oauth2.googleapis.com/token', ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
                    'grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion'=>$jwt,
                ]), false);
                $data = json_decode($resp['body'], true);
                if ($resp['status'] === 200 && !empty($data['access_token'])) return $token = (string)$data['access_token'];
            }
        }
    }

    $clientId = env('GOOGLE_DRIVE_CLIENT_ID', reportSettingGet('google_drive_client_id'));
    $clientSecret = env('GOOGLE_DRIVE_CLIENT_SECRET', reportSettingGet('google_drive_client_secret'));
    $refreshToken = env('GOOGLE_DRIVE_REFRESH_TOKEN', reportSettingGet('google_drive_refresh_token'));
    if ($clientId !== '' && $clientSecret !== '' && $refreshToken !== '') {
        $resp = reportDriveHttp('POST', 'https://oauth2.googleapis.com/token', ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
            'client_id'=>$clientId,
            'client_secret'=>$clientSecret,
            'refresh_token'=>$refreshToken,
            'grant_type'=>'refresh_token',
        ]), false);
        $data = json_decode($resp['body'], true);
        if ($resp['status'] === 200 && !empty($data['access_token'])) return $token = (string)$data['access_token'];
        error_log('[report-archive] refresh token error: '.substr($resp['body'], 0, 800));
    }

    apiError(503, 'Google Drive ist für die dauerhafte Gutachtenablage noch nicht verbunden.');
}

function reportDriveApi(string $method, string $path, array $query = [], ?array $json = null): array
{
    $url = 'https://www.googleapis.com/drive/v3/' . ltrim($path, '/');
    $query += ['supportsAllDrives'=>'true'];
    if ($query) $url .= '?' . http_build_query($query);
    $headers = [];
    $body = null;
    if ($json !== null) {
        $headers[] = 'Content-Type: application/json; charset=utf-8';
        $body = json_encode($json, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }
    $resp = reportDriveHttp($method, $url, $headers, $body, true);
    $data = json_decode($resp['body'], true);
    if ($resp['status'] < 200 || $resp['status'] >= 300) {
        error_log('[report-archive] Drive API '.$resp['status'].' '.$path.' '.substr($resp['body'], 0, 1200));
        apiError(503, 'Google-Drive-API-Fehler (' . $resp['status'] . ').');
    }
    return is_array($data) ? $data : [];
}

function reportDriveEscape(string $value): string
{
    return str_replace("'", "\\'", $value);
}

function reportDriveFindFolder(string $parentId, string $name): ?array
{
    $q = "'".reportDriveEscape($parentId)."' in parents and trashed=false and mimeType='application/vnd.google-apps.folder' and name='".reportDriveEscape($name)."'";
    $data = reportDriveApi('GET', 'files', [
        'q'=>$q,
        'fields'=>'files(id,name,webViewLink)',
        'pageSize'=>10,
    ]);
    return !empty($data['files'][0]) ? $data['files'][0] : null;
}

function reportDriveCreateFolder(string $parentId, string $name): array
{
    return reportDriveApi('POST', 'files', ['fields'=>'id,name,webViewLink'], [
        'name'=>$name,
        'mimeType'=>'application/vnd.google-apps.folder',
        'parents'=>[$parentId],
    ]);
}

function reportDriveArchiveRoot(array $user): string
{
    $configured = trim(env('REPORT_ARCHIVE_DRIVE_FOLDER_ID', reportSettingGet('report_archive_drive_folder_id')));
    if ($configured !== '') return $configured;

    $root = reportDriveApi('GET', 'files/root', ['fields'=>'id']);
    $rootId = trim((string)($root['id'] ?? ''));
    if ($rootId === '') apiError(503, 'Google-Drive-Stammordner konnte nicht ermittelt werden.');

    $folderName = trim(env('REPORT_ARCHIVE_DRIVE_FOLDER_NAME', 'SV-Netzwerk Prüfportal - Gutachtenarchiv'));
    $folder = reportDriveFindFolder($rootId, $folderName) ?? reportDriveCreateFolder($rootId, $folderName);
    $id = trim((string)($folder['id'] ?? ''));
    if ($id === '') apiError(503, 'Dauerhafter Gutachtenordner konnte nicht angelegt werden.');
    reportSettingSet('report_archive_drive_folder_id', $id, $user);
    return $id;
}

function reportDriveProjectFolder(int $projectId, array $user): string
{
    $root = reportDriveArchiveRoot($user);
    $name = 'Projekt-' . $projectId;
    $folder = reportDriveFindFolder($root, $name) ?? reportDriveCreateFolder($root, $name);
    $id = trim((string)($folder['id'] ?? ''));
    if ($id === '') apiError(503, 'Projektordner für Gutachten konnte nicht angelegt werden.');
    return $id;
}

function reportDriveUploadFile(string $localPath, string $fileName, string $mimeType, int $projectId, array $user): array
{
    $parentId = reportDriveProjectFolder($projectId, $user);
    $size = filesize($localPath);
    if ($size === false || $size <= 0) apiError(400, 'Gutachtendatei ist leer.');

    $metadata = json_encode([
        'name'=>$fileName,
        'parents'=>[$parentId],
        'appProperties'=>[
            'svnet_type'=>'gutachtenarchiv',
            'project_id'=>(string)$projectId,
        ],
    ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

    $init = reportDriveHttp(
        'POST',
        'https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable&supportsAllDrives=true&fields=id,name,size,mimeType,webViewLink,modifiedTime',
        [
            'Content-Type: application/json; charset=utf-8',
            'X-Upload-Content-Type: '.$mimeType,
            'X-Upload-Content-Length: '.(string)$size,
        ],
        $metadata,
        true
    );
    if ($init['status'] < 200 || $init['status'] >= 300) {
        error_log('[report-archive] Drive resumable init failed '.$init['status'].' '.substr($init['body'],0,1000));
        apiError(503, 'Dauerhafte Google-Drive-Ablage konnte nicht gestartet werden.');
    }
    if (!preg_match('/^Location:\s*(.+)$/mi', $init['headers'], $m)) apiError(503, 'Google Drive hat keine Upload-Adresse geliefert.');
    $uploadUrl = trim($m[1]);

    $fh = fopen($localPath, 'rb');
    if ($fh === false) apiError(503, 'Gutachtendatei konnte für die Ablage nicht gelesen werden.');
    $ch = curl_init($uploadUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CUSTOMREQUEST=>'PUT',
        CURLOPT_UPLOAD=>true,
        CURLOPT_INFILE=>$fh,
        CURLOPT_INFILESIZE=>$size,
        CURLOPT_HTTPHEADER=>['Content-Type: '.$mimeType, 'Content-Length: '.(string)$size],
        CURLOPT_CONNECTTIMEOUT=>12,
        CURLOPT_TIMEOUT=>300,
        CURLOPT_FOLLOWLOCATION=>true,
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    fclose($fh);
    if ($body === false || $err !== '' || $status < 200 || $status >= 300) {
        error_log('[report-archive] Drive upload failed '.$status.' '.($err ?: substr((string)$body,0,1000)));
        apiError(503, 'Gutachten konnte nicht dauerhaft in Google Drive gespeichert werden.');
    }
    $data = json_decode((string)$body, true);
    if (!is_array($data) || empty($data['id'])) apiError(503, 'Google Drive hat die dauerhafte Speicherung nicht bestätigt.');
    return $data;
}

function reportDriveVerifyFile(string $fileId): array
{
    return reportDriveApi('GET', 'files/'.rawurlencode($fileId), [
        'fields'=>'id,name,size,mimeType,trashed,webViewLink,modifiedTime',
    ]);
}

function reportDriveStreamFile(string $fileId, string $downloadName, string $mimeType, int $fallbackSize=0): void
{
    $meta = reportDriveVerifyFile($fileId);
    if (!empty($meta['trashed']) || empty($meta['id'])) apiError(404, 'Archivdatei wurde in Google Drive nicht gefunden.');
    $size = max(0, (int)($meta['size'] ?? $fallbackSize));
    $mime = trim((string)($meta['mimeType'] ?? $mimeType)) ?: 'application/octet-stream';

    header('Content-Type: '.$mime);
    if ($size > 0) header('Content-Length: '.(string)$size);
    header('Content-Disposition: attachment; filename="'.addcslashes(basename($downloadName), '"\\').'"');
    header('Cache-Control: private, no-store');

    $url = 'https://www.googleapis.com/drive/v3/files/'.rawurlencode($fileId).'?alt=media&supportsAllDrives=true';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>false,
        CURLOPT_HTTPHEADER=>['Authorization: Bearer '.reportDriveAccessToken()],
        CURLOPT_CONNECTTIMEOUT=>12,
        CURLOPT_TIMEOUT=>300,
        CURLOPT_FOLLOWLOCATION=>true,
        CURLOPT_WRITEFUNCTION=>static function($ch, string $chunk): int { echo $chunk; return strlen($chunk); },
    ]);
    $ok = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($ok === false || $status < 200 || $status >= 300) error_log('[report-archive] Drive stream failed '.$status.' for '.$fileId);
    exit;
}

function ensureReportArchiveTable(): void
{
    db()->exec(
        'CREATE TABLE IF NOT EXISTS report_archive (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id INT UNSIGNED NOT NULL DEFAULT 1,
            file_name VARCHAR(255) NOT NULL,
            storage_name VARCHAR(255) NOT NULL,
            drive_file_id VARCHAR(128) NULL,
            drive_web_view_link TEXT NULL,
            storage_backend VARCHAR(32) NOT NULL DEFAULT "google_drive",
            mime_type VARCHAR(128) NOT NULL DEFAULT "application/msword",
            file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
            sha256 CHAR(64) NOT NULL,
            window_count INT UNSIGNED NOT NULL DEFAULT 0,
            photo_count INT UNSIGNED NOT NULL DEFAULT 0,
            attachment_count INT UNSIGNED NOT NULL DEFAULT 0,
            created_by INT UNSIGNED NULL,
            created_by_name VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_report_archive_project (project_id),
            KEY idx_report_archive_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
    foreach ([
        'ALTER TABLE report_archive ADD COLUMN drive_file_id VARCHAR(128) NULL AFTER storage_name',
        'ALTER TABLE report_archive ADD COLUMN drive_web_view_link TEXT NULL AFTER drive_file_id',
        'ALTER TABLE report_archive ADD COLUMN storage_backend VARCHAR(32) NOT NULL DEFAULT "local_legacy" AFTER drive_web_view_link',
    ] as $sql) {
        try { db()->exec($sql); } catch (Throwable $e) { /* column already exists */ }
    }
}

ensureReportArchiveTable();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET' && isset($_GET['download'])) {
    $id = max(1, (int)$_GET['download']);
    $stmt = db()->prepare('SELECT * FROM report_archive WHERE id=:id LIMIT 1');
    $stmt->execute([':id'=>$id]);
    $row = $stmt->fetch();
    if (!$row) apiError(404, 'Gutachten nicht gefunden.');

    $driveId = trim((string)($row['drive_file_id'] ?? ''));
    if ($driveId !== '') {
        reportDriveStreamFile($driveId, (string)$row['file_name'], (string)$row['mime_type'], (int)$row['file_size']);
    }

    $base = realpath(reportsDir());
    $file = $base !== false ? realpath($base . '/' . basename((string)$row['storage_name'])) : false;
    if ($base === false || $file === false || !str_starts_with($file, $base . DIRECTORY_SEPARATOR) || !is_file($file)) {
        apiError(404, 'Archivdatei nicht gefunden. Dieser Altbestand wurde noch lokal gespeichert und ist nicht mehr vorhanden.');
    }
    header('Content-Type: '.((string)$row['mime_type'] ?: 'application/octet-stream'));
    header('Content-Length: '.(string)filesize($file));
    header('Content-Disposition: attachment; filename="'.addcslashes(basename((string)$row['file_name']), '"\\').'"');
    header('Cache-Control: private, no-store');
    readfile($file);
    exit;
}

if ($method === 'GET') {
    $projectId = max(1, (int)($_GET['project_id'] ?? DEFAULT_PROJECT_ID));
    $stmt = db()->prepare('SELECT id,project_id,file_name,storage_name,drive_file_id,drive_web_view_link,storage_backend,mime_type,file_size,sha256,window_count,photo_count,attachment_count,created_by,created_by_name,created_at FROM report_archive WHERE project_id=:pid ORDER BY created_at DESC,id DESC LIMIT 200');
    $stmt->execute([':pid'=>$projectId]);
    $items = $stmt->fetchAll();
    foreach ($items as &$item) {
        $item['id'] = (int)$item['id'];
        $item['project_id'] = (int)$item['project_id'];
        $item['file_size'] = (int)$item['file_size'];
        $item['window_count'] = (int)$item['window_count'];
        $item['photo_count'] = (int)$item['photo_count'];
        $item['attachment_count'] = (int)$item['attachment_count'];
        $item['can_delete'] = canDeleteProject($user, (int)$item['project_id']);
        unset($item['created_by']);
        $driveId = trim((string)($item['drive_file_id'] ?? ''));
        $available = $driveId !== '';
        if (!$available) {
            $candidate = reportsDir().'/'.basename((string)$item['storage_name']);
            $available = is_file($candidate);
        }
        $item['persistent'] = $driveId !== '';
        $item['available'] = $available;
        $item['download_url'] = $available ? '/intern/api/report-archive.php?download='.$item['id'] : null;
    }
    unset($item);
    apiJson(['items'=>$items, 'storage'=>'google_drive']);
}

if ($method !== 'POST') apiError(405, 'Methode nicht erlaubt.');
if (empty($_FILES['report'])) apiError(400, 'Gutachtendatei fehlt.');

$file = $_FILES['report'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) apiError(400, 'Gutachten konnte nicht hochgeladen werden. Upload-Fehlercode: '.(int)$file['error']);
if ((int)$file['size'] <= 0) apiError(400, 'Gutachtendatei ist leer.');
if ((int)$file['size'] > 200 * 1024 * 1024) apiError(413, 'Gutachtendatei ist größer als 200 MB.');

$projectId = max(1, (int)($_POST['project_id'] ?? DEFAULT_PROJECT_ID));
$originalName = trim((string)($_POST['file_name'] ?? $file['name'] ?? 'Gutachten.doc'));
$originalName = preg_replace('/[^a-zA-Z0-9äöüÄÖÜß._ -]+/u', '_', basename($originalName)) ?: 'Gutachten.doc';
$mime = trim((string)($file['type'] ?? 'application/msword')) ?: 'application/msword';
$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
if (!in_array($ext, ['doc','docx','html','htm'], true)) $ext = 'doc';
$storageName = sprintf('%d-%s-%s.%s', $projectId, gmdate('Ymd-His'), bin2hex(random_bytes(6)), $ext);
$tmpPath = (string)$file['tmp_name'];
$sha = hash_file('sha256', $tmpPath) ?: '';
$windowCount = max(0, (int)($_POST['window_count'] ?? 0));
$photoCount = max(0, (int)($_POST['photo_count'] ?? 0));
$attachmentCount = max(0, (int)($_POST['attachment_count'] ?? 0));

// Erst dauerhaft in Google Drive speichern und dort verifizieren. Erst danach
// wird der Datensatz als archiviertes Gutachten in der Datenbank angelegt.
$drive = reportDriveUploadFile($tmpPath, $originalName, $mime, $projectId, $user);
$driveId = trim((string)($drive['id'] ?? ''));
$verified = $driveId !== '' ? reportDriveVerifyFile($driveId) : [];
if ($driveId === '' || empty($verified['id']) || !empty($verified['trashed'])) apiError(503, 'Google Drive hat die dauerhafte Archivierung nicht bestätigt.');
$driveLink = trim((string)($verified['webViewLink'] ?? $drive['webViewLink'] ?? ''));
$storedSize = max(0, (int)($verified['size'] ?? $file['size'] ?? 0));

try {
    $stmt = db()->prepare('INSERT INTO report_archive (project_id,file_name,storage_name,drive_file_id,drive_web_view_link,storage_backend,mime_type,file_size,sha256,window_count,photo_count,attachment_count,created_by,created_by_name,created_at) VALUES (:pid,:fn,:sn,:df,:dl,:sb,:mt,:fs,:sha,:wc,:pc,:ac,:uid,:un,:now)');
    $stmt->execute([
        ':pid'=>$projectId,
        ':fn'=>$originalName,
        ':sn'=>$storageName,
        ':df'=>$driveId,
        ':dl'=>$driveLink,
        ':sb'=>'google_drive',
        ':mt'=>$mime,
        ':fs'=>$storedSize,
        ':sha'=>$sha,
        ':wc'=>$windowCount,
        ':pc'=>$photoCount,
        ':ac'=>$attachmentCount,
        ':uid'=>$user['id'],
        ':un'=>$user['full_name'] ?: $user['email'],
        ':now'=>nowUtc(),
    ]);
    $id = (int)db()->lastInsertId();
} catch (Throwable $e) {
    // Die Datei bleibt zur Sicherheit in Google Drive erhalten. Kein Datenverlust
    // durch automatisches Löschen bei einem nachgelagerten DB-Fehler.
    error_log('[report-archive] metadata insert failed for Drive file '.$driveId.': '.$e->getMessage());
    apiError(503, 'Gutachten wurde dauerhaft in Google Drive gespeichert, der Archiveintrag konnte jedoch nicht angelegt werden. Drive-Datei-ID: '.$driveId);
}

apiJson([
    'ok'=>true,
    'id'=>$id,
    'storage'=>'google_drive',
    'persistent'=>true,
    'drive_file_id'=>$driveId,
    'drive_web_view_link'=>$driveLink,
    'download_url'=>'/intern/api/report-archive.php?download='.$id,
], 201);
