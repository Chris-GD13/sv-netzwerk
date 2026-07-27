<?php
/**
 * Export-Log API – SV-Netzwerk Prüfportal
 *
 * POST /api/exports.php – Export-Eintrag protokollieren
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

commonHeaders();

$user = requireAuth();

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
