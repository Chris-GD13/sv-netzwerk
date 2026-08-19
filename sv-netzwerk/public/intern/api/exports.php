<?php
/**
 * Export-Log API – SV-Netzwerk Prüfportal
 *
 * GET  /api/exports.php?project_id=1&export_type=Word-Gutachten – Exporthistorie
 * POST /api/exports.php – Export-Eintrag protokollieren
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

commonHeaders();

$user = requireAuth();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $projectId = max(1, (int) ($_GET['project_id'] ?? DEFAULT_PROJECT_ID));
    $exportType = trim((string) ($_GET['export_type'] ?? 'Word-Gutachten'));
    try {
        $stmt = db()->prepare(
            'SELECT id, project_id, export_type, exported_by, file_name, filter_snapshot, created_at
             FROM export_logs
             WHERE project_id = :pid AND export_type = :et
             ORDER BY created_at DESC, id DESC
             LIMIT 200'
        );
        $stmt->execute([':pid' => $projectId, ':et' => $exportType]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['project_id'] = (int) $row['project_id'];
            $row['filter_snapshot'] = $row['filter_snapshot'] ? (json_decode((string) $row['filter_snapshot'], true) ?: []) : [];
            // Bis zur zentralen Dateiablage ist dies die revisionsnahe Erzeugungshistorie.
            $row['archived_file_available'] = false;
        }
        unset($row);
        apiJson(['items' => $rows]);
    } catch (Throwable $e) {
        apiError(503, 'Gutachtenhistorie konnte nicht geladen werden.');
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError(405, 'Methode nicht erlaubt.');
}

$body       = requestBody();
$exportType = trim((string) ($body['export_type'] ?? ''));
$fileName   = trim((string) ($body['file_name']   ?? ''));
$filter     = $body['filter_snapshot'] ?? [];

if ($exportType === '') {
    apiError(400, 'export_type fehlt.');
}

try {
    db()->prepare(
        'INSERT INTO export_logs (project_id, export_type, exported_by, file_name, filter_snapshot, created_at)
         VALUES (:pid, :et, :uid, :fn, :fs, :now)'
    )->execute([
        ':pid' => DEFAULT_PROJECT_ID,
        ':et'  => $exportType,
        ':uid' => $user['id'],
        ':fn'  => $fileName,
        ':fs'  => json_encode($filter, JSON_UNESCAPED_UNICODE),
        ':now' => nowUtc(),
    ]);
} catch (Throwable) {
    // Export-Log-Fehler sind nicht kritisch
}

apiJson(['ok' => true]);
