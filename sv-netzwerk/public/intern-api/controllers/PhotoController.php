<?php
declare(strict_types=1);

namespace SvIntern\Controllers;

use SvIntern\Middleware\Role;
use SvIntern\Models\Photo;
use SvIntern\Models\Window;
use SvIntern\Models\AuditLog;
use SvIntern\Services\UploadService;

final class PhotoController
{
    /** GET /intern-api/windows/{windowId}/photos */
    public static function list(array $session, \PDO $db, string $windowId): never
    {
        $model  = new Photo($db);
        $photos = $model->listByWindow($windowId);
        \jsonResponse(['data' => $photos]);
    }

    /** POST /intern-api/windows/{windowId}/photos */
    public static function upload(array $session, \PDO $db, string $windowId): never
    {
        Role::require($session, Role::PRUEFER);

        // Fenster muss existieren
        $windowModel = new Window($db);
        if ($windowModel->findById($windowId) === null) {
            \jsonError('Fenster nicht gefunden.', 404);
        }

        if (empty($_FILES['file'])) {
            \jsonError('Keine Datei gesendet.', 400);
        }

        $category = trim((string) ($_POST['category'] ?? 'sonstiges'));
        $caption  = \strOrNull($_POST['caption'] ?? null);

        try {
            $uploadService = new UploadService();
            $result = $uploadService->process($_FILES['file']);
        } catch (\RuntimeException $e) {
            \jsonError($e->getMessage(), 400);
        }

        $photoId    = \generateUuid();
        $photoModel = new Photo($db);
        $photoModel->create(
            id:            $photoId,
            windowId:      $windowId,
            category:      $category,
            caption:       $caption,
            fileName:      $result['file_name'],
            storagePath:   $result['storage_path'],
            inspectorId:   $session['user_id'],
            inspectorName: $session['user_name'],
        );

        $auditLog = new AuditLog($db);
        $auditLog->log(
            actorId:    $session['user_id'],
            actorName:  $session['user_name'],
            actionType: 'upload',
            entityType: 'photo',
            entityId:   $photoId,
            fieldName:  'category',
            newValue:   $category,
            windowId:   $windowId,
            ip:         \clientIp(),
        );

        \jsonResponse(['data' => $photoModel->findById($photoId)], 201);
    }

    /** DELETE /intern-api/photos/{id} */
    public static function delete(array $session, \PDO $db, string $id): never
    {
        $photoModel = new Photo($db);
        $photo      = $photoModel->findById($id);

        if ($photo === null) {
            \jsonError('Foto nicht gefunden.', 404);
        }

        // Nur eigene Fotos oder Admin
        if ($photo['inspector_id'] !== $session['user_id'] && !Role::isAdmin($session)) {
            \jsonError('Nicht autorisiert.', 403);
        }

        $photoModel->softDelete($id, $session['user_id']);

        // Physische Datei loeschen
        try {
            $uploadService = new UploadService();
            $uploadService->delete((string) $photo['storage_path']);
        } catch (\Throwable) {
            // Datei nicht vorhanden – DB-Loeschung trotzdem durchgefuehrt
        }

        $auditLog = new AuditLog($db);
        $auditLog->log(
            actorId:    $session['user_id'],
            actorName:  $session['user_name'],
            actionType: 'delete',
            entityType: 'photo',
            entityId:   $id,
            windowId:   (string) ($photo['window_id'] ?? ''),
            ip:         \clientIp(),
        );

        \jsonResponse(['ok' => true]);
    }

    /**
     * GET /intern-api/photos/{id}/file
     * Liefert die Bilddatei aus – nur fuer authentifizierte Benutzer.
     * Kein JSON, sondern Binary-Ausgabe.
     */
    public static function serve(array $session, \PDO $db, string $id): never
    {
        $photoModel = new Photo($db);
        $photo      = $photoModel->findById($id);

        if ($photo === null) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Foto nicht gefunden.']);
            exit;
        }

        try {
            $uploadService = new UploadService();
            $filePath      = $uploadService->resolvePath((string) $photo['storage_path']);
        } catch (\RuntimeException $e) {
            http_response_code(404);
            header('Content-Type: application/json');
            echo json_encode(['error' => $e->getMessage()]);
            exit;
        }

        $finfo    = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = (string) $finfo->file($filePath);

        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: private, max-age=3600');
        header('Content-Disposition: inline; filename="' . basename($filePath) . '"');
        header('X-Content-Type-Options: nosniff');

        readfile($filePath);
        exit;
    }
}
