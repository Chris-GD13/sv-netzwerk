<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
commonHeaders();
$user = requireAuth();

if (!in_array($user['role'], ['administrator', 'projektleiter', 'pruefer', 'sachverstaendiger'], true)) {
    apiError(403, 'Keine Berechtigung.');
}

$buildingId = max(0, (int) ($_GET['building_id'] ?? 0));
if ($buildingId <= 0) {
    apiError(400, 'building_id ist erforderlich.');
}

try {
    $stmt = db()->prepare(
        'SELECT
            w.id AS window_id,
            w.window_number,
            w.inspection_number,
            ro.room_number,
            ws.id AS sash_id,
            ws.position AS sash_position,
            ws.sash_number
         FROM windows w
         INNER JOIN rooms ro ON ro.id = w.room_id
         INNER JOIN floors fl ON fl.id = ro.floor_id
         LEFT JOIN window_sashes ws
           ON ws.id = (
             SELECT ws2.id
             FROM window_sashes ws2
             WHERE ws2.window_id = w.id AND ws2.deleted_at IS NULL
             ORDER BY ws2.sash_number ASC, ws2.id ASC
             LIMIT 1
           )
         WHERE fl.building_id = :building_id
           AND w.deleted_at IS NULL
         ORDER BY COALESCE(w.inspection_number, 999999), w.window_number, w.id'
    );
    $stmt->execute([':building_id' => $buildingId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[sharepoint-photo-targets] ' . $e->getMessage());
    apiError(503, 'Vorhandener Fensterbestand konnte nicht als Fotoziel geladen werden.');
}

$targets = [];
$withoutSash = 0;
foreach ($rows as $row) {
    $number = trim((string) ($row['inspection_number'] ?? ''));
    if ($number === '' || $number === '0') {
        $number = trim((string) ($row['window_number'] ?? ''));
    }
    if (preg_match('/\d+/', $number, $match)) {
        $number = (string) ((int) $match[0]);
    }
    if ($number === '') {
        continue;
    }

    $sashId = (int) ($row['sash_id'] ?? 0);
    if ($sashId <= 0) {
        $withoutSash++;
        continue;
    }

    $targets[] = [
        'schlagzahl' => $number,
        'room_reference' => trim((string) ($row['room_number'] ?? '')),
        'position' => trim((string) ($row['sash_position'] ?? '')),
        'window_id' => (int) $row['window_id'],
        'sash_id' => $sashId,
    ];
}

apiJson([
    'added' => 0,
    'updated' => 0,
    'skipped' => 0,
    'errors' => [],
    'skipped_rows' => [],
    'targets' => $targets,
    'photo_only' => true,
    'windows_found' => count($rows),
    'targets_found' => count($targets),
    'windows_without_sash' => $withoutSash,
]);
