<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
commonHeaders();
$user = requireAuth();
requireRole($user, ['administrator', 'projektleiter']);

$method = $_SERVER['REQUEST_METHOD'];
$buildingId = isset($_GET['building_id']) ? (int) $_GET['building_id'] : 0;
if ($buildingId <= 0) apiError(400, 'building_id ist erforderlich.');

function rbLoadBuilding(PDO $pdo, int $buildingId): array {
    $stmt = $pdo->prepare('SELECT id, project_id, name FROM buildings WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $buildingId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) apiError(404, 'Gebaeude nicht gefunden.');
    return $row;
}

function rbWindowIds(PDO $pdo, int $buildingId): array {
    $stmt = $pdo->prepare(
        'SELECT w.id FROM windows w
         JOIN rooms r ON r.id = w.room_id
         JOIN floors f ON f.id = r.floor_id
         WHERE f.building_id = :bid'
    );
    $stmt->execute([':bid' => $buildingId]);
    return array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'id'));
}

function rbCounts(PDO $pdo, int $buildingId): array {
    $windowIds = rbWindowIds($pdo, $buildingId);
    $counts = ['windows' => count($windowIds), 'sashes' => 0, 'photos' => 0, 'rooms' => 0, 'floors' => 0];
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM rooms r JOIN floors f ON f.id=r.floor_id WHERE f.building_id=:bid');
    $stmt->execute([':bid' => $buildingId]); $counts['rooms'] = (int)$stmt->fetchColumn();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM floors WHERE building_id=:bid');
    $stmt->execute([':bid' => $buildingId]); $counts['floors'] = (int)$stmt->fetchColumn();
    if ($windowIds) {
        $in = implode(',', array_fill(0, count($windowIds), '?'));
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM window_sashes WHERE window_id IN ($in)");
        $stmt->execute($windowIds); $counts['sashes'] = (int)$stmt->fetchColumn();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM photos WHERE window_id IN ($in)");
        $stmt->execute($windowIds); $counts['photos'] = (int)$stmt->fetchColumn();
    }
    return $counts;
}

try { $pdo = db(); } catch (Throwable $e) { apiError(503, 'Datenbank nicht erreichbar.'); }
$building = rbLoadBuilding($pdo, $buildingId);

if ($method === 'GET') {
    apiJson(['ok' => true, 'building' => $building, 'counts' => rbCounts($pdo, $buildingId)]);
}

if ($method !== 'POST') apiError(405, 'POST erforderlich.');
$body = requestBody();
$confirmation = trim((string)($body['confirmation'] ?? ''));
$expected = 'RESET ' . (string)$building['name'];
if (!hash_equals($expected, $confirmation)) {
    apiError(400, 'Bestaetigung stimmt nicht. Erwartet: ' . $expected);
}

$before = rbCounts($pdo, $buildingId);
$windowIds = rbWindowIds($pdo, $buildingId);
$physicalDeleted = 0;
$physicalMissing = 0;

try {
    $pdo->beginTransaction();

    if ($windowIds) {
        $in = implode(',', array_fill(0, count($windowIds), '?'));
        $photoStmt = $pdo->prepare("SELECT storage_path FROM photos WHERE window_id IN ($in)");
        $photoStmt->execute($windowIds);
        $photoPaths = array_column($photoStmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'storage_path');

        // Hartes Loeschen der Fenster ist hier bewusst gewollt: abhängige Sashes, Fotos,
        // Locks und Audit-Logs werden gemaess Schema per ON DELETE CASCADE entfernt.
        $deleteWindows = $pdo->prepare("DELETE FROM windows WHERE id IN ($in)");
        $deleteWindows->execute($windowIds);

        foreach ($photoPaths as $storagePath) {
            $relative = ltrim((string)$storagePath, '/');
            if ($relative === '' || str_contains($relative, '..')) { $physicalMissing++; continue; }
            $file = rtrim(photosDir(), '/\\') . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative);
            if (is_file($file)) {
                if (@unlink($file)) $physicalDeleted++; else $physicalMissing++;
            } else {
                $physicalMissing++;
            }
        }
    }

    // Nach dem Fenster-Loeschen darf die hierarchische Importstruktur dieses Gebaeudes
    // ebenfalls leer werden. Das Gebaeude selbst bleibt bestehen.
    $deleteFloors = $pdo->prepare('DELETE FROM floors WHERE building_id = :bid');
    $deleteFloors->execute([':bid' => $buildingId]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[reset-building] ' . $e->getMessage());
    apiError(503, 'Fallbestand konnte nicht zurueckgesetzt werden: ' . $e->getMessage());
}

error_log('[reset-building] building=' . $buildingId . ' name=' . $building['name'] . ' user=' . ($user['email'] ?? $user['full_name'] ?? '') . ' counts=' . json_encode($before));
apiJson([
    'ok' => true,
    'building' => $building,
    'deleted' => $before,
    'physical_photo_files_deleted' => $physicalDeleted,
    'physical_photo_files_missing_or_failed' => $physicalMissing,
    'message' => 'Fallbestand wurde vollstaendig geleert. Das Gebaeude bleibt bestehen und kann neu importiert werden.',
]);
