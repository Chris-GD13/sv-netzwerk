<?php
/**
 * Zentrale Gutachtenablage – SV-Netzwerk Prüfportal
 *
 * GET  ?project_id=1                 – archivierte Gutachten auflisten
 * GET  ?download={id}                – archiviertes Gutachten herunterladen
 * POST multipart/form-data report    – Gutachten zentral archivieren
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
commonHeaders();
$user = requireAuth();

function reportsDir(): string
{
    $configured = env('REPORTS_DIR', '');
    $dir = $configured !== '' ? rtrim($configured, '/') : dirname(photosDir()) . '/reports';
    if ((!is_dir($dir) && !@mkdir($dir, 0775, true)) || !is_writable($dir)) {
        apiError(503, 'Zentrale Gutachtenablage ist nicht verfügbar oder nicht beschreibbar.');
    }
    return $dir;
}

function ensureReportArchiveTable(): void
{
    db()->exec(
        'CREATE TABLE IF NOT EXISTS report_archive (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            project_id INT UNSIGNED NOT NULL DEFAULT 1,
            file_name VARCHAR(255) NOT NULL,
            storage_name VARCHAR(255) NOT NULL,
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
}

ensureReportArchiveTable();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET' && isset($_GET['download'])) {
    $id = max(1, (int) $_GET['download']);
    $stmt = db()->prepare('SELECT * FROM report_archive WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) apiError(404, 'Gutachten nicht gefunden.');

    $base = realpath(reportsDir());
    $file = $base !== false ? realpath($base . '/' . basename((string) $row['storage_name'])) : false;
    if ($base === false || $file === false || !str_starts_with($file, $base . DIRECTORY_SEPARATOR) || !is_file($file)) {
        apiError(404, 'Archivdatei nicht gefunden.');
    }

    header('Content-Type: ' . ((string) $row['mime_type'] ?: 'application/octet-stream'));
    header('Content-Length: ' . (string) filesize($file));
    header('Content-Disposition: attachment; filename="' . addcslashes(basename((string) $row['file_name']), '"\\') . '"');
    header('Cache-Control: private, no-store');
    readfile($file);
    exit;
}

if ($method === 'GET') {
    $projectId = max(1, (int) ($_GET['project_id'] ?? DEFAULT_PROJECT_ID));
    $stmt = db()->prepare('SELECT id, project_id, file_name, mime_type, file_size, sha256, window_count, photo_count, attachment_count, created_by_name, created_at FROM report_archive WHERE project_id = :pid ORDER BY created_at DESC, id DESC LIMIT 200');
    $stmt->execute([':pid' => $projectId]);
    $items = $stmt->fetchAll();
    foreach ($items as &$item) {
        $item['id'] = (int) $item['id'];
        $item['project_id'] = (int) $item['project_id'];
        $item['file_size'] = (int) $item['file_size'];
        $item['window_count'] = (int) $item['window_count'];
        $item['photo_count'] = (int) $item['photo_count'];
        $item['attachment_count'] = (int) $item['attachment_count'];
        $item['download_url'] = '/intern/api/report-archive.php?download=' . $item['id'];
    }
    unset($item);
    apiJson(['items' => $items]);
}

if ($method !== 'POST') apiError(405, 'Methode nicht erlaubt.');
if (empty($_FILES['report'])) apiError(400, 'Gutachtendatei fehlt.');

$file = $_FILES['report'];
if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    apiError(400, 'Gutachten konnte nicht hochgeladen werden. Upload-Fehlercode: ' . (int) $file['error']);
}
if ((int) $file['size'] <= 0) apiError(400, 'Gutachtendatei ist leer.');
if ((int) $file['size'] > 200 * 1024 * 1024) apiError(413, 'Gutachtendatei ist größer als 200 MB.');

$projectId = max(1, (int) ($_POST['project_id'] ?? DEFAULT_PROJECT_ID));
$originalName = trim((string) ($_POST['file_name'] ?? $file['name'] ?? 'Gutachten.doc'));
$originalName = preg_replace('/[^a-zA-Z0-9äöüÄÖÜß._ -]+/u', '_', basename($originalName)) ?: 'Gutachten.doc';
$mime = trim((string) ($file['type'] ?? 'application/msword')) ?: 'application/msword';
$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
if (!in_array($ext, ['doc', 'docx', 'html', 'htm'], true)) $ext = 'doc';
$storageName = sprintf('%d-%s-%s.%s', $projectId, gmdate('Ymd-His'), bin2hex(random_bytes(6)), $ext);
$destination = reportsDir() . '/' . $storageName;
if (!move_uploaded_file($file['tmp_name'], $destination)) apiError(503, 'Gutachten konnte nicht in der zentralen Ablage gespeichert werden.');

$sha = hash_file('sha256', $destination) ?: '';
$windowCount = max(0, (int) ($_POST['window_count'] ?? 0));
$photoCount = max(0, (int) ($_POST['photo_count'] ?? 0));
$attachmentCount = max(0, (int) ($_POST['attachment_count'] ?? 0));

try {
    $stmt = db()->prepare('INSERT INTO report_archive (project_id, file_name, storage_name, mime_type, file_size, sha256, window_count, photo_count, attachment_count, created_by, created_by_name, created_at) VALUES (:pid,:fn,:sn,:mt,:fs,:sha,:wc,:pc,:ac,:uid,:un,:now)');
    $stmt->execute([
        ':pid' => $projectId,
        ':fn' => $originalName,
        ':sn' => $storageName,
        ':mt' => $mime,
        ':fs' => filesize($destination),
        ':sha' => $sha,
        ':wc' => $windowCount,
        ':pc' => $photoCount,
        ':ac' => $attachmentCount,
        ':uid' => $user['id'],
        ':un' => $user['full_name'] ?: $user['email'],
        ':now' => nowUtc(),
    ]);
    $id = (int) db()->lastInsertId();
} catch (Throwable $e) {
    @unlink($destination);
    apiError(503, 'Gutachten-Metadaten konnten nicht gespeichert werden.');
}

apiJson(['ok' => true, 'id' => $id, 'download_url' => '/intern/api/report-archive.php?download=' . $id], 201);
