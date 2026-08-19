<?php
/**
 * Delta-Helfer fuer den SharePoint-Fotoimport.
 *
 * Der bestehende Import bleibt unveraendert. Dieses Hilfs-API liefert nur die
 * noch nicht bekannten SharePoint-Fotos und merkt erfolgreiche Importe. Damit
 * muessen Folgeimporte nicht erneut alle bereits zugeordneten Fotos laden und
 * analysieren.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
commonHeaders();
requireAuth();

$action = $_GET['action'] ?? '';

match ($action) {
    'filter' => handleDeltaFilter(),
    'mark' => handleDeltaMark(),
    'state' => handleDeltaState(),
    'reset' => handleDeltaReset(),
    default => apiError(404, 'Unbekannter Delta-Endpunkt.'),
};

function deltaStatePath(): string
{
    return __DIR__ . '/../sharepoint-delta-state.json';
}

function loadDeltaState(): array
{
    $path = deltaStatePath();
    if (!is_file($path)) return ['buildings' => []];
    $decoded = json_decode((string) file_get_contents($path), true);
    return is_array($decoded) ? $decoded : ['buildings' => []];
}

function saveDeltaState(array $state): void
{
    $path = deltaStatePath();
    if (file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
        apiError(503, 'Delta-Status konnte nicht gespeichert werden.');
    }
}

function normalizePhotoName(string $value): string
{
    $value = mb_strtolower(trim($value), 'UTF-8');
    $value = preg_replace('/\?.*$/', '', $value) ?? $value;
    $value = basename(str_replace('\\', '/', $value));
    return preg_replace('/[^\p{L}\p{N}._-]+/u', '', $value) ?? $value;
}

function existingPhotoNamesForBuilding(int $buildingId): array
{
    if ($buildingId <= 0) return [];
    try {
        $pdo = db();
        $cols = $pdo->query('SHOW COLUMNS FROM photos')->fetchAll(PDO::FETCH_COLUMN, 0);
        if (!is_array($cols)) return [];
        $candidates = ['original_name', 'file_name', 'filename', 'name', 'path', 'file_path', 'storage_path', 'url', 'caption'];
        $nameCols = array_values(array_intersect($candidates, $cols));
        if ($nameCols === []) return [];

        $select = implode(', ', array_map(static fn($c) => 'p.`' . str_replace('`', '``', $c) . '`', $nameCols));
        $sql = 'SELECT ' . $select . '\n'
             . 'FROM photos p\n'
             . 'JOIN windows w ON w.id = p.window_id\n'
             . 'JOIN rooms r ON r.id = w.room_id\n'
             . 'JOIN floors f ON f.id = r.floor_id\n'
             . 'WHERE f.building_id = :bid';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':bid' => $buildingId]);
        $known = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            foreach ($nameCols as $col) {
                $raw = trim((string) ($row[$col] ?? ''));
                if ($raw === '') continue;
                $name = normalizePhotoName($raw);
                if ($name !== '') $known[$name] = true;
            }
        }
        return $known;
    } catch (Throwable $e) {
        error_log('[sharepoint-delta] Bestandsabgleich fehlgeschlagen: ' . $e->getMessage());
        return [];
    }
}

function handleDeltaFilter(): never
{
    $body = requestBody();
    $buildingId = (int) ($body['building_id'] ?? 0);
    $mode = (string) ($body['mode'] ?? 'complete');
    $photos = is_array($body['photos'] ?? null) ? $body['photos'] : [];

    if ($mode === 'full') {
        apiJson([
            'photos' => $photos,
            'total' => count($photos),
            'skipped' => 0,
            'mode' => 'full',
        ]);
    }

    $state = loadDeltaState();
    $bucket = $state['buildings']['building_' . $buildingId]['photos'] ?? [];
    $processedIds = is_array($bucket) ? array_fill_keys(array_keys($bucket), true) : [];
    $knownNames = existingPhotoNamesForBuilding($buildingId);

    $remaining = [];
    $skipped = 0;
    foreach ($photos as $photo) {
        if (!is_array($photo)) continue;
        $id = trim((string) ($photo['id'] ?? ''));
        $name = normalizePhotoName((string) ($photo['name'] ?? $photo['path'] ?? ''));
        $knownById = $id !== '' && isset($processedIds[$id]);
        $knownByName = $name !== '' && isset($knownNames[$name]);
        if ($knownById || $knownByName) {
            $skipped++;
            continue;
        }
        $remaining[] = $photo;
    }

    apiJson([
        'photos' => $remaining,
        'total' => count($photos),
        'new' => count($remaining),
        'skipped' => $skipped,
        'mode' => $mode,
    ]);
}

function extractItemId(mixed $value): string
{
    if (!is_array($value)) return '';
    foreach (['item_id', 'sharepoint_id', 'drive_item_id', 'photo_id', 'id'] as $key) {
        if (isset($value[$key]) && is_scalar($value[$key])) {
            $candidate = trim((string) $value[$key]);
            if ($candidate !== '') return $candidate;
        }
    }
    foreach ($value as $child) {
        if (is_array($child)) {
            $found = extractItemId($child);
            if ($found !== '') return $found;
        }
    }
    return '';
}

function handleDeltaMark(): never
{
    $body = requestBody();
    $buildingId = (int) ($body['building_id'] ?? 0);
    $payload = is_array($body['payload'] ?? null) ? $body['payload'] : $body;
    $itemId = trim((string) ($body['item_id'] ?? ''));
    if ($itemId === '') $itemId = extractItemId($payload);
    if ($buildingId <= 0 || $itemId === '') apiError(400, 'building_id oder SharePoint-ID fehlt.');

    $state = loadDeltaState();
    $key = 'building_' . $buildingId;
    if (!isset($state['buildings'][$key])) $state['buildings'][$key] = ['photos' => []];
    if (!isset($state['buildings'][$key]['photos']) || !is_array($state['buildings'][$key]['photos'])) {
        $state['buildings'][$key]['photos'] = [];
    }
    $state['buildings'][$key]['photos'][$itemId] = [
        'processed_at' => nowUtc(),
        'name' => (string) ($body['name'] ?? ''),
        'path' => (string) ($body['path'] ?? ''),
    ];
    saveDeltaState($state);
    apiJson(['ok' => true]);
}

function handleDeltaState(): never
{
    $buildingId = (int) ($_GET['building_id'] ?? 0);
    $state = loadDeltaState();
    $bucket = $state['buildings']['building_' . $buildingId]['photos'] ?? [];
    apiJson(['processed' => is_array($bucket) ? count($bucket) : 0]);
}

function handleDeltaReset(): never
{
    $body = requestBody();
    $buildingId = (int) ($body['building_id'] ?? 0);
    if ($buildingId <= 0) apiError(400, 'building_id fehlt.');
    $state = loadDeltaState();
    unset($state['buildings']['building_' . $buildingId]);
    saveDeltaState($state);
    apiJson(['ok' => true]);
}
