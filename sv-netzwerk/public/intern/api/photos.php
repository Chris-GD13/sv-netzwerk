<?php
/**
 * Fotos API – SV-Netzwerk Prüfportal
 *
 * GET    ?window_id={id}                        – Fotos eines Fensters auflisten
 * GET    ?sash_id={id}                          – Fotos eines Flügels auflisten
 * POST   (multipart) ?window_id={id}            – Foto hochladen (am Fenster)
 * POST   (multipart) ?window_id={id}&sash_id={} – Foto hochladen (am Flügel)
 * DELETE ?id={photoId}                          – Foto löschen
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

commonHeaders();

$user   = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$id     = isset($_GET['id'])        ? (int) $_GET['id']        : null;
$wid    = isset($_GET['window_id']) ? (int) $_GET['window_id'] : null;
$sid    = isset($_GET['sash_id'])   ? (int) $_GET['sash_id']   : null;

match (true) {
    $method === 'GET'    && $sid !== null           => handleListBySash($sid),
    $method === 'GET'    && $wid !== null            => handleList($wid),
    $method === 'POST'   && $wid !== null            => handleUpload($wid, $sid, $user),
    $method === 'DELETE' && $id !== null             => handleDelete($id, $user),
    default                                          => apiError(404, 'Unbekannter Endpunkt.'),
};

function handleList(int $windowId): never
{
    try {
        $stmt = db()->prepare(
            'SELECT id, window_id, sash_id, category, caption, file_name, taken_at, inspector_name, storage_path
             FROM photos
             WHERE window_id = :wid AND deleted_at IS NULL
             ORDER BY created_at DESC'
        );
        $stmt->execute([':wid' => $windowId]);
        $rows = $stmt->fetchAll();
    } catch (Throwable) {
        apiError(503, 'Fotos konnten nicht geladen werden.');
    }
    apiJson(array_map('mapPhoto', $rows));
}

function handleListBySash(int $sashId): never
{
    try {
        $stmt = db()->prepare(
            'SELECT id, window_id, sash_id, category, caption, file_name, taken_at, inspector_name, storage_path
             FROM photos
             WHERE sash_id = :sid AND deleted_at IS NULL
             ORDER BY created_at DESC'
        );
        $stmt->execute([':sid' => $sashId]);
        $rows = $stmt->fetchAll();
    } catch (Throwable) {
        apiError(503, 'Flügel-Fotos konnten nicht geladen werden.');
    }
    apiJson(array_map('mapPhoto', $rows));
}

function handleUpload(int $windowId, ?int $sashId, array $user): never
{
    if (empty($_FILES['photo'])) {
        apiError(400, 'Keine Datei übermittelt (Feldname: photo).');
    }

    $file     = $_FILES['photo'];
    $category = trim((string) ($_POST['category'] ?? 'sonstiges'));
    $caption  = trim((string) ($_POST['caption']  ?? ''));
    // sash_id kann auch via POST kommen
    if ($sashId === null && isset($_POST['sash_id']) && (int)$_POST['sash_id'] > 0) {
        $sashId = (int) $_POST['sash_id'];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        apiError(400, 'Upload-Fehler: ' . uploadErrorMessage($file['error']));
    }

    // MIME-Typ prüfen
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    $allowed  = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];
    if (!in_array($mimeType, $allowed, true)) {
        apiError(400, 'Nur JPEG-, PNG-, WEBP- und HEIC-Bilder erlaubt.');
    }

    $ext      = pathinfo($file['name'], PATHINFO_EXTENSION);
    $ext      = preg_replace('/[^a-z0-9]/', '', strtolower($ext)) ?: 'jpg';
    $fileName = bin2hex(random_bytes(12)) . '.' . $ext;
    $subDir   = photosDir() . '/' . $windowId;

    if (!is_dir($subDir) && !mkdir($subDir, 0750, true)) {
        apiError(503, 'Verzeichnis konnte nicht erstellt werden.');
    }

    $destPath = $subDir . '/' . $fileName;
    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        apiError(503, 'Datei konnte nicht gespeichert werden.');
    }

    $storagePath = $windowId . '/' . $fileName;

    try {
        db()->prepare(
            'INSERT INTO photos
             (window_id, sash_id, category, caption, file_name, storage_path, inspector_id, inspector_name, taken_at, created_at)
             VALUES (:wid, :sid, :cat, :cap, :fn, :sp, :iid, :in, :now, :now2)'
        )->execute([
            ':wid'  => $windowId,
            ':sid'  => $sashId,
            ':cat'  => $category,
            ':cap'  => $caption !== '' ? $caption : null,
            ':fn'   => $file['name'],
            ':sp'   => $storagePath,
            ':iid'  => $user['id'],
            ':in'   => $user['full_name'] ?: $user['email'],
            ':now'  => nowUtc(),
            ':now2' => nowUtc(),
        ]);
        $newId = (int) db()->lastInsertId();
    } catch (Throwable $e) {
        @unlink($destPath);
        apiError(503, 'Fotodatensatz konnte nicht gespeichert werden.');
    }

    apiJson([
        'id'             => $newId,
        'window_id'      => $windowId,
        'sash_id'        => $sashId,
        'category'       => $category,
        'caption'        => $caption !== '' ? $caption : null,
        'file_name'      => $file['name'],
        'storage_path'   => $storagePath,
        'inspector_name' => $user['full_name'] ?: $user['email'],
        'taken_at'       => nowUtc(),
    ], 201);
}

function handleDelete(int $photoId, array $user): never
{
    try {
        $stmt = db()->prepare('SELECT storage_path, inspector_id FROM photos WHERE id = :id AND deleted_at IS NULL');
        $stmt->execute([':id' => $photoId]);
        $photo = $stmt->fetch();
    } catch (Throwable) {
        apiError(503, 'Datenbankfehler.');
    }

    if (!$photo) {
        apiError(404, 'Foto nicht gefunden.');
    }

    // Nur der Eigentümer oder Administrator darf löschen
    if ((int) $photo['inspector_id'] !== $user['id'] && $user['role'] !== 'administrator') {
        apiError(403, 'Keine Berechtigung.');
    }

    try {
        db()->prepare('UPDATE photos SET deleted_at = :now WHERE id = :id')
            ->execute([':now' => nowUtc(), ':id' => $photoId]);
    } catch (Throwable) {
        apiError(503, 'Foto konnte nicht gelöscht werden.');
    }

    // Physische Datei entfernen
    $filePath = photosDir() . '/' . $photo['storage_path'];
    if (is_file($filePath)) {
        @unlink($filePath);
    }

    apiJson(['ok' => true]);
}

function mapPhoto(array $row): array
{
    return [
        'id'             => (string) $row['id'],
        'window_id'      => (string) $row['window_id'],
        'sash_id'        => isset($row['sash_id']) ? (string) $row['sash_id'] : null,
        'category'       => $row['category'],
        'caption'        => $row['caption'],
        'file_name'      => $row['file_name'],
        'taken_at'       => $row['taken_at'],
        'inspector_name' => $row['inspector_name'],
        'storage_path'   => $row['storage_path'],
    ];
}

function uploadErrorMessage(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE,
        UPLOAD_ERR_FORM_SIZE => 'Datei zu groß.',
        UPLOAD_ERR_NO_FILE   => 'Keine Datei gewählt.',
        UPLOAD_ERR_NO_TMP_DIR => 'Kein temporäres Verzeichnis.',
        UPLOAD_ERR_CANT_WRITE => 'Schreibfehler.',
        default               => "Fehlercode $code.",
    };
}
