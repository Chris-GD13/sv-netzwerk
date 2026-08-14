<?php
/**
 * SharePoint-Import – SV-Netzwerk Prüfportal
 *
 * Endpoints:
 * - GET  ?action=get_url&building_id={id}
 * - POST ?action=set_url
 * - POST ?action=import_excel (multipart file)
 * - POST ?action=import_sharepoint_excel
 * - GET  ?action=list_sharepoint_photos
 * - GET  ?action=sharepoint_photo&id={driveItemId}
 * - POST ?action=import_sharepoint_photo
 * - POST ?action=apply_excel
 * - POST ?action=upload_photo (multipart file)
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

commonHeaders();
$user = requireAuth();
$action = $_GET['action'] ?? '';

match ($action) {
    'get_url' => handleGetUrl(),
    'set_url' => handleSetUrl(),
    'import_excel' => handleImportExcel(),
    'import_sharepoint_excel' => handleImportSharePointExcel(),
    'list_sharepoint_photos' => handleListSharePointPhotos(),
    'sharepoint_photo' => handleSharePointPhoto(),
    'import_sharepoint_photo' => handleImportSharePointPhoto($user),
    'apply_excel' => handleApplyExcel($user),
    'upload_photo' => handleUploadPhoto($user),
    default => apiError(404, 'Unbekannter SharePoint-Endpunkt.'),
};

function graphConfig(string $key, string $default = ''): string
{
    $value = getenv($key);
    return $value === false || trim($value) === '' ? $default : trim($value);
}

function graphAccessToken(): string
{
    static $token = null;
    if (is_string($token) && $token !== '') {
        return $token;
    }

    $tenantId = graphConfig('MS_TENANT_ID');
    $clientId = graphConfig('MS_CLIENT_ID');
    $clientSecret = graphConfig('MS_CLIENT_SECRET');
    if ($tenantId === '' || $clientId === '' || $clientSecret === '') {
        apiError(503, 'Die SharePoint-Verbindung ist auf dem Server noch nicht vollständig eingerichtet.');
    }

    $curl = curl_init('https://login.microsoftonline.com/' . rawurlencode($tenantId) . '/oauth2/v2.0/token');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS => http_build_query([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials',
        ]),
    ]);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    $decoded = is_string($response) ? json_decode($response, true) : null;
    if ($status < 200 || $status >= 300 || !is_array($decoded) || empty($decoded['access_token'])) {
        apiError(503, 'Microsoft-Anmeldung für den SharePoint-Import fehlgeschlagen.' . ($error !== '' ? ' ' . $error : ''));
    }
    $token = (string) $decoded['access_token'];
    return $token;
}

function graphRequest(string $url, bool $binary = false): array|string
{
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $binary ? 120 : 45,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . graphAccessToken()],
    ]);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $contentType = (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
    $error = curl_error($curl);
    curl_close($curl);
    if ($status < 200 || $status >= 300 || !is_string($response)) {
        apiError(503, 'SharePoint konnte nicht gelesen werden (HTTP ' . $status . ').' . ($error !== '' ? ' ' . $error : ''));
    }
    if ($binary) {
        return ['body' => $response, 'content_type' => $contentType];
    }
    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        apiError(503, 'SharePoint hat eine ungültige Antwort geliefert.');
    }
    return $decoded;
}

function graphSiteId(): string
{
    static $siteId = null;
    if (is_string($siteId) && $siteId !== '') {
        return $siteId;
    }
    $configured = graphConfig('MS_SHAREPOINT_SITE_ID');
    if ($configured !== '') {
        return $siteId = $configured;
    }
    $host = graphConfig('MS_SHAREPOINT_HOST', 'sv1schuett.sharepoint.com');
    $path = graphConfig('MS_SHAREPOINT_SITE_PATH', '/sites/SVBroSchtt');
    $site = graphRequest('https://graph.microsoft.com/v1.0/sites/' . rawurlencode($host) . ':' . str_replace('%2F', '/', rawurlencode($path)) . '?$select=id');
    $siteId = (string) ($site['id'] ?? '');
    if ($siteId === '') {
        apiError(503, 'Die konfigurierte SharePoint-Site wurde nicht gefunden.');
    }
    return $siteId;
}

function graphDriveId(): string
{
    static $driveId = null;
    if (is_string($driveId) && $driveId !== '') {
        return $driveId;
    }
    $configured = graphConfig('MS_SHAREPOINT_DRIVE_ID');
    if ($configured !== '') {
        return $driveId = $configured;
    }
    $drive = graphRequest('https://graph.microsoft.com/v1.0/sites/' . rawurlencode(graphSiteId()) . '/drive?$select=id');
    $driveId = (string) ($drive['id'] ?? '');
    if ($driveId === '') {
        apiError(503, 'Die SharePoint-Dokumentbibliothek wurde nicht gefunden.');
    }
    return $driveId;
}

function graphItemByPath(string $path): array
{
    $segments = array_map('rawurlencode', array_values(array_filter(explode('/', trim($path, '/')), static fn($part) => $part !== '')));
    $encodedPath = implode('/', $segments);
    return graphRequest('https://graph.microsoft.com/v1.0/drives/' . rawurlencode(graphDriveId()) . '/root:/' . $encodedPath . '?$select=id,name,size,file,folder');
}

function graphDownloadItem(string $itemId): array
{
    return graphRequest('https://graph.microsoft.com/v1.0/drives/' . rawurlencode(graphDriveId()) . '/items/' . rawurlencode($itemId) . '/content', true);
}

function sharePointStatePath(): string
{
    return __DIR__ . '/../sharepoint-state.json';
}

function normalizeSchlagzahl(mixed $value): string
{
    if ($value === null) {
        return '';
    }
    $text = trim((string) $value);
    if ($text === '') {
        return '';
    }
    if (preg_match('/\d+/', $text, $match)) {
        $normalized = ltrim($match[0], '0');
        return $normalized === '' ? '0' : $normalized;
    }
    return '';
}

function excelRowLookup(array $row, array $keys): string
{
    foreach ($keys as $key) {
        $value = $row[$key] ?? null;
        if ($value === null || $value === '') {
            continue;
        }
        $text = trim((string) $value);
        if ($text !== '') {
            return $text;
        }
    }

    foreach ($row as $key => $value) {
        $candidate = trim((string) $value);
        if ($candidate === '') {
            continue;
        }
        $normalizedKey = strtolower((string) $key);
        foreach ($keys as $needle) {
            if (str_contains($normalizedKey, strtolower((string) $needle))) {
                return $candidate;
            }
        }
    }

    return '';
}

function ensureBuildingFloor(PDO $pdo, int $buildingId): int
{
    $row = $pdo->prepare('SELECT id FROM floors WHERE building_id = :bid ORDER BY level ASC, sort_order ASC, id ASC LIMIT 1');
    $row->execute([':bid' => $buildingId]);
    $floor = $row->fetch(PDO::FETCH_ASSOC);
    if ($floor) {
        return (int) $floor['id'];
    }

    $stmt = $pdo->prepare('INSERT INTO floors (building_id, name, level, sort_order, created_at, updated_at) VALUES (:bid, :name, 0, 10, :created_at, :updated_at)');
    $stmt->execute([
        ':bid' => $buildingId,
        ':name' => 'EG / Erdgeschoss',
        ':created_at' => nowUtc(),
        ':updated_at' => nowUtc(),
    ]);

    return (int) $pdo->lastInsertId();
}

function ensureBuildingRoom(PDO $pdo, int $buildingId, array $row): int
{
    $floorId = ensureBuildingFloor($pdo, $buildingId);
    $roomRef = excelRowLookup($row, ['Zimmer', 'Zimmernummer', 'Zimmer Nr', 'Raum', 'Raumnummer', 'Raumnummer', 'room', 'room_number', 'A', 'Zimmer-Nr']);
    $roomNumber = trim((string) preg_replace('/\s+/', ' ', $roomRef));
    $roomName = $roomNumber !== '' ? 'Raum ' . $roomNumber : 'Import Raum';

    $lookup = $pdo->prepare(
        'SELECT id FROM rooms WHERE floor_id = :fid AND (room_number = :rn OR name = :name) LIMIT 1'
    );
    $lookup->execute([':fid' => $floorId, ':rn' => $roomNumber, ':name' => $roomName]);
    $existing = $lookup->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        return (int) $existing['id'];
    }

    // MySQL error 1093: do not read from rooms in a subquery while inserting
    // into rooms. Resolve the next sort order in a separate statement first.
    $sortOrderStmt = $pdo->prepare(
        'SELECT COALESCE(MAX(sort_order), 0) + 10 FROM rooms WHERE floor_id = :fid'
    );
    $sortOrderStmt->execute([':fid' => $floorId]);
    $sortOrder = (int) $sortOrderStmt->fetchColumn();

    $stmt = $pdo->prepare(
        'INSERT INTO rooms (floor_id, name, room_number, sort_order, created_at, updated_at) VALUES (:fid, :name, :rn, :sort_order, :created_at, :updated_at)'
    );
    $stmt->execute([
        ':fid' => $floorId,
        ':name' => $roomName,
        ':rn' => $roomNumber,
        ':sort_order' => $sortOrder,
        ':created_at' => nowUtc(),
        ':updated_at' => nowUtc(),
    ]);

    return (int) $pdo->lastInsertId();
}

function excelWindowNumber(array $row, string $fallback): string
{
    $value = excelRowLookup($row, ['Fensternummer', 'Fenster Nr', 'Fenster-Nr', 'window_number', 'window number', 'Fenster', 'Nr', 'C', 'Fenster-Nr.', 'Nummer']);
    $trimmed = trim((string) $value);
    if ($trimmed !== '') {
        return $trimmed;
    }
    return $fallback !== '' ? $fallback : 'Import';
}

function handleGetUrl(): never
{
    $buildingId = isset($_GET['building_id']) ? (int) $_GET['building_id'] : 0;
    if ($buildingId <= 0) {
        apiError(400, 'building_id fehlt.');
    }

    $data = [];
    $path = sharePointStatePath();
    if (is_file($path)) {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }

    apiJson(['url' => (string) ($data['building_' . $buildingId] ?? '')]);
}

function handleSetUrl(): never
{
    $body = requestBody();
    $buildingId = isset($body['building_id']) ? (int) $body['building_id'] : 0;
    $url = trim((string) ($body['url'] ?? ''));
    if ($buildingId <= 0 || $url === '') {
        apiError(400, 'building_id und url sind erforderlich.');
    }

    $path = sharePointStatePath();
    $data = [];
    if (is_file($path)) {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }

    $data['building_' . $buildingId] = $url;
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        apiError(503, 'Verzeichnis für SharePoint-URL konnte nicht erstellt werden.');
    }
    if (file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
        apiError(503, 'SharePoint-URL konnte nicht gespeichert werden.');
    }

    apiJson(['ok' => true]);
}

function isAllowedSpreadsheetFile(string $name, string $mime): bool
{
    $lowerName = strtolower($name);
    $lowerMime = strtolower($mime);
    if (preg_match('/\.(xlsx|xlsm|xlsb|xls|csv)$/i', $lowerName)) {
        return true;
    }
    return str_contains($lowerMime, 'excel')
        || str_contains($lowerMime, 'spreadsheet')
        || str_contains($lowerMime, 'csv')
        || str_contains($lowerMime, 'tab-separated');
}

function handleImportExcel(): never
{
    if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
        apiError(400, 'Keine Excel-Datei hochgeladen.');
    }

    $file = $_FILES['file'];
    $tmp = $file['tmp_name'];
    $name = basename($file['name']);
    $mime = mime_content_type($tmp) ?: '';
    if (!isAllowedSpreadsheetFile($name, $mime)) {
        apiError(400, 'Bitte nur Excel-Dateien (.xlsx, .xls, .csv) hochladen.');
    }

    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext === 'csv') {
        $rows = parseCsvRows($tmp);
    } elseif (in_array($ext, ['xls', 'xlsx', 'xlsm', 'xlsb'], true)) {
        $rows = parseXlsxRows($tmp);
    } else {
        apiError(400, 'Nur XLS, XLSX, XLSM, XLSB oder CSV erlaubt.');
    }

    if (empty($rows)) {
        apiError(422, 'Excel-Datei konnte nicht gelesen werden oder enthielt keine Daten.');
    }

    $headers = array_values(array_map('trim', array_keys($rows[0])));
    $normalizedRows = [];
    foreach ($rows as $row) {
        $normalized = [];
        foreach ($row as $key => $value) {
            $normalized[(string) $key] = $value;
        }
        $normalizedRows[] = $normalized;
    }

    apiJson(['ok' => true, 'rows' => $normalizedRows, 'columns' => $headers]);
}

function spreadsheetResult(string $path, string $name): array
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if ($ext === 'csv') {
        $rows = parseCsvRows($path);
    } elseif (in_array($ext, ['xls', 'xlsx', 'xlsm', 'xlsb'], true)) {
        $rows = parseXlsxRows($path);
    } else {
        apiError(400, 'Die SharePoint-Datei ist keine unterstützte Excel- oder CSV-Datei.');
    }
    if ($rows === []) {
        apiError(422, 'Die Excel-Datei aus SharePoint enthielt keine lesbaren Daten.');
    }
    return [
        'rows' => array_map(static function (array $row): array {
            $normalized = [];
            foreach ($row as $key => $value) {
                $normalized[(string) $key] = $value;
            }
            return $normalized;
        }, $rows),
        'columns' => array_values(array_map('trim', array_keys($rows[0]))),
    ];
}

function handleImportSharePointExcel(): never
{
    $path = graphConfig(
        'MS_SHAREPOINT_EXCEL_PATH',
        'VS Schäden/Marc/Privatgutachten/2026/Bundesministerium Verteidigung_Bonn/BW fesnterprüfung.xlsx'
    );
    $item = graphItemByPath($path);
    $itemId = (string) ($item['id'] ?? '');
    if ($itemId === '') {
        apiError(404, 'Die konfigurierte Excel-Datei wurde in SharePoint nicht gefunden.');
    }
    $download = graphDownloadItem($itemId);
    $tmp = tempnam(sys_get_temp_dir(), 'sp-excel-');
    if ($tmp === false || file_put_contents($tmp, $download['body']) === false) {
        apiError(503, 'Die Excel-Datei konnte nicht zwischengespeichert werden.');
    }
    try {
        $result = spreadsheetResult($tmp, (string) ($item['name'] ?? basename($path)));
    } finally {
        @unlink($tmp);
    }
    apiJson(['ok' => true, 'file_name' => (string) ($item['name'] ?? basename($path))] + $result);
}

function handleListSharePointPhotos(): never
{
    $path = graphConfig(
        'MS_SHAREPOINT_PHOTO_PATH',
        'VS Schäden/Marc/Privatgutachten/2026/Bundesministerium Verteidigung_Bonn/Bilder Gebäude 800/EG'
    );
    $folder = graphItemByPath($path);
    $folderId = (string) ($folder['id'] ?? '');
    if ($folderId === '') {
        apiError(404, 'Der konfigurierte Fotoordner wurde in SharePoint nicht gefunden.');
    }

    $url = 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode(graphDriveId()) . '/items/' . rawurlencode($folderId)
        . '/children?$select=id,name,size,file,createdDateTime,lastModifiedDateTime&$top=200';
    $photos = [];
    while ($url !== '') {
        $page = graphRequest($url);
        foreach (($page['value'] ?? []) as $item) {
            if (!is_array($item) || empty($item['file'])) {
                continue;
            }
            $name = (string) ($item['name'] ?? '');
            if (!preg_match('/\.(jpe?g|png|webp|tiff?|heic|heif)$/i', $name)) {
                continue;
            }
            $photos[] = [
                'id' => (string) ($item['id'] ?? ''),
                'name' => $name,
                'size' => (int) ($item['size'] ?? 0),
                'mime_type' => (string) ($item['file']['mimeType'] ?? 'application/octet-stream'),
            ];
        }
        $url = (string) ($page['@odata.nextLink'] ?? '');
    }
    usort($photos, static fn(array $a, array $b): int => strnatcasecmp($a['name'], $b['name']));
    apiJson(['ok' => true, 'folder_name' => (string) ($folder['name'] ?? basename($path)), 'photos' => $photos]);
}

function handleSharePointPhoto(): never
{
    $itemId = trim((string) ($_GET['id'] ?? ''));
    if ($itemId === '') {
        apiError(400, 'SharePoint-Datei-ID fehlt.');
    }
    $download = graphDownloadItem($itemId);
    header('Content-Type: ' . ($download['content_type'] !== '' ? $download['content_type'] : 'application/octet-stream'));
    header('Cache-Control: private, max-age=300');
    echo $download['body'];
    exit;
}

function importRowValue(array $row, array $needles): string
{
    foreach ($row as $key => $value) {
        $normalizedKey = strtolower((string) preg_replace('/[^a-z0-9äöüß]+/u', ' ', (string) $key));
        foreach ($needles as $needle) {
            $normalizedNeedle = strtolower((string) preg_replace('/[^a-z0-9äöüß]+/u', ' ', $needle));
            if ($normalizedKey === $normalizedNeedle || str_contains($normalizedKey, $normalizedNeedle)) {
                $candidate = trim((string) $value);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }
    }
    return '';
}

function importHasDefect(string $description): bool
{
    $normalized = strtolower(trim($description));
    if ($normalized === '') {
        return false;
    }
    return preg_match('/\b(ok|i\.?\s*o\.?|sonst\s+ok|ohne\s+mangel)\b/u', $normalized) !== 1
        || preg_match('/\b(meg|wa|wartung|defekt|fehlt|gesperrt|schleif|häng|gebrochen|schwergängig|nicht\s+möglich|beschädigt|tauschen|klemmt)\b/u', $normalized) === 1;
}

function mergeImportDescriptions(array $rows): string
{
    $descriptions = [];
    foreach ($rows as $row) {
        $description = importRowValue($row, ['Beschreibungen', 'Beschreibung', 'Mangel', 'Feststellung']);
        if ($description !== '' && !in_array($description, $descriptions, true)) {
            $descriptions[] = $description;
        }
    }
    return implode(' | ', $descriptions);
}

function handleApplyExcel(array $user): never
{
    $body = requestBody();
    $buildingId = isset($body['building_id']) ? (int) $body['building_id'] : 0;
    $rows = is_array($body['rows'] ?? null) ? $body['rows'] : [];
    $schlagzahlColumn = trim((string) ($body['schlagzahl_column'] ?? 'schlagzahl'));
    if ($buildingId <= 0 || $schlagzahlColumn === '') {
        apiError(400, 'building_id und schlagzahl_column sind erforderlich.');
    }

    $added = 0;
    $updated = 0;
    $skipped = 0;
    $errors = [];
    $targets = [];

    try {
        $pdo = db();
        $pdo->beginTransaction();
        $buildingStmt = $pdo->prepare('SELECT id, name, project_id FROM buildings WHERE id = :bid LIMIT 1');
        $buildingStmt->execute([':bid' => $buildingId]);
        $building = $buildingStmt->fetch(PDO::FETCH_ASSOC);
        if (!$building) {
            apiError(404, 'Gebäude nicht gefunden.');
        }

        $groups = [];
        $groupOrder = [];
        $lastRoomReference = '';
        $lastGroupKey = '';
        $lastSchlagzahl = '';
        $lastPosition = '';
        foreach ($rows as $sourceIndex => $row) {
            if (!is_array($row)) {
                $skipped++; continue;
            }

            $schlagzahl = normalizeSchlagzahl($row[$schlagzahlColumn] ?? '');
            if ($schlagzahl === '') {
                $candidate = normalizeSchlagzahl(excelRowLookup($row, ['Schlagzahl', 'Schlag-Zahl', 'SZ', 'Fensternummer', 'Fenster Nr', 'Fenster-Nr', 'Nr']));
                if ($candidate === '') {
                    $skipped++; continue;
                }
                $schlagzahl = $candidate;
            }

            $roomReference = importRowValue($row, ['Zimmer', 'Zimmernummer', 'Zimmer Nr', 'Raum', 'Raumnummer']);
            $position = importRowValue($row, ['Lage', 'Position']);
            if ($roomReference !== '') {
                $lastRoomReference = $roomReference;
            } elseif ($lastRoomReference !== '') {
                $roomReference = $lastRoomReference;
            }
            if ($roomReference === '') {
                $skipped++;
                $errors[] = 'Zeile ' . ($sourceIndex + 1) . ': Raum/Zimmer fehlt.';
                continue;
            }

            $normalizedPosition = strtolower(trim($position));
            $groupKey = strtolower($roomReference . '|' . $position . '|' . $schlagzahl);
            if ($lastGroupKey !== '' && $lastSchlagzahl === $schlagzahl && $lastPosition === $normalizedPosition) {
                // The source list stores DK and Kipp as consecutive detail rows.
                // Keep them together even when the second row contains a room typo.
                $groupKey = $lastGroupKey;
            }
            if (!isset($groups[$groupKey])) {
                $groups[$groupKey] = [
                    'schlagzahl' => $schlagzahl,
                    'room_reference' => $roomReference,
                    'position' => $position,
                    'rows' => [],
                ];
                $groupOrder[] = $groupKey;
            }
            $groups[$groupKey]['rows'][] = $row;
            $lastGroupKey = $groupKey;
            $lastSchlagzahl = $schlagzahl;
            $lastPosition = $normalizedPosition;
        }

        foreach ($groupOrder as $groupKey) {
            $group = $groups[$groupKey];
            $groupRows = $group['rows'];
            $primaryRow = $groupRows[0];
            $schlagzahl = $group['schlagzahl'];
            $position = trim((string) $group['position']);

            $roomId = ensureBuildingRoom($pdo, $buildingId, $primaryRow);
            $windowNumber = $schlagzahl;
            $windowExists = $pdo->prepare('SELECT id FROM windows WHERE room_id = :rid AND window_number = :wn AND deleted_at IS NULL LIMIT 1');
            $windowExists->execute([':rid' => $roomId, ':wn' => $windowNumber]);
            $existingWindow = $windowExists->fetch(PDO::FETCH_ASSOC);

            $description = mergeImportDescriptions($groupRows);
            $hasDefect = importHasDefect($description);
            $openingTypes = [];
            foreach ($groupRows as $groupRow) {
                $openingType = importRowValue($groupRow, ['Beschlag', 'Öffnungsart', 'Oeffnungsart']);
                if ($openingType !== '' && !in_array(strtoupper($openingType), $openingTypes, true)) {
                    $openingTypes[] = strtoupper($openingType);
                }
            }
            $openingType = implode(' + ', $openingTypes);

            $windowFormData = [
                'import_source' => 'sharepoint_excel',
                'schlagzahl' => $schlagzahl,
                'room_reference' => $group['room_reference'],
                'position' => $position,
                'import_rows' => $groupRows,
            ];

            if ($existingWindow) {
                $pdo->prepare(
                    'UPDATE windows SET room_label = :room_label, room_number = :room_number,
                     has_defect = :has_defect, status = :status, form_data = :fd, updated_at = :now WHERE id = :id'
                )->execute([
                    ':room_label' => 'Raum ' . $group['room_reference'],
                    ':room_number' => $group['room_reference'],
                    ':has_defect' => $hasDefect ? 1 : 0,
                    ':status' => 'in Bearbeitung',
                    ':fd' => json_encode($windowFormData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ':now' => nowUtc(),
                    ':id' => (int) $existingWindow['id'],
                ]);
                $windowId = (int) $existingWindow['id'];
                $updated++;
            } else {
                $recordId = 'SP-' . strtoupper(bin2hex(random_bytes(6)));
                $stmt = $pdo->prepare(
                    'INSERT INTO windows (project_id, room_id, record_id, window_number, room_label, room_number, building_label, floor_label, status, has_defect, form_data, created_at, updated_at)
                     VALUES (:pid, :rid, :record_id, :wn, :room_label, :room_number, :building_label, :floor_label, :status, :has_defect, :form_data, :created_at, :updated_at)'
                );
                $stmt->execute([
                    ':pid' => (int) $building['project_id'],
                    ':rid' => $roomId,
                    ':record_id' => $recordId,
                    ':wn' => $windowNumber,
                    ':room_label' => 'Raum ' . $group['room_reference'],
                    ':room_number' => $group['room_reference'],
                    ':building_label' => (string) $building['name'],
                    ':floor_label' => 'EG / Erdgeschoss',
                    ':status' => 'in Bearbeitung',
                    ':has_defect' => $hasDefect ? 1 : 0,
                    ':form_data' => json_encode($windowFormData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ':created_at' => nowUtc(),
                    ':updated_at' => nowUtc(),
                ]);
                $windowId = (int) $pdo->lastInsertId();
                $added++;
            }

            $glassWidth = importRowValue($primaryRow, ['Glas Breite']);
            $glassHeight = importRowValue($primaryRow, ['Glas Höhe', 'Glas Hoehe']);
            $frameWidth = importRowValue($primaryRow, ['Rahmen Breite']);
            $frameHeight = importRowValue($primaryRow, ['Rahmen Höhe', 'Rahmen Hoehe']);
            $glassStructure = importRowValue($primaryRow, ['Glasaufbau']);
            $sashLabel = trim('Flügel ' . $position);
            if ($sashLabel === 'Flügel') {
                $sashLabel = 'Flügel ' . $schlagzahl;
            }
            $sashFormData = [
                'status' => 'in Bearbeitung',
                'sash_label' => $sashLabel,
                'opening_type' => $openingType,
                'position' => $position,
                'qr_barcode' => $schlagzahl,
                'inspection_date' => date('Y-m-d'),
                'inspector_name' => $user['full_name'] ?: $user['email'],
                'glass_structure' => $glassStructure,
                'glazing_width_mm' => $glassWidth,
                'glazing_height_mm' => $glassHeight,
                'frame_width_mm' => $frameWidth,
                'frame_height_mm' => $frameHeight,
                'fn_bemerkung' => $description,
                'massnahme_empfehlung' => $description,
                'eignung_beurteilung' => $hasDefect ? 'instandsetzung_erforderlich' : 'geeignet',
                'overall_rating' => $hasDefect ? 'Instandsetzung erforderlich' : 'ohne festgestellten Handlungsbedarf',
                'import_source' => 'sharepoint_excel',
                'import_rows' => $groupRows,
            ];

            $sashLookup = $pdo->prepare(
                'SELECT id FROM window_sashes WHERE window_id = :wid AND deleted_at IS NULL ORDER BY sash_number ASC LIMIT 1'
            );
            $sashLookup->execute([':wid' => $windowId]);
            $sashId = (int) ($sashLookup->fetchColumn() ?: 0);
            if ($sashId > 0) {
                $pdo->prepare(
                    'UPDATE window_sashes SET sash_label = :label, opening_type = :opening_type, position = :position,
                     status = :status, form_data = :form_data, progress_percent = :progress, has_defect = :has_defect,
                     overall_rating = :rating, inspector_id = :inspector_id, inspector_name = :inspector_name,
                     inspected_at = :inspected_at, updated_at = :updated_at WHERE id = :id'
                )->execute([
                    ':label' => $sashLabel,
                    ':opening_type' => $openingType !== '' ? $openingType : null,
                    ':position' => $position !== '' ? $position : null,
                    ':status' => 'in Bearbeitung',
                    ':form_data' => json_encode($sashFormData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ':progress' => 73,
                    ':has_defect' => $hasDefect ? 1 : 0,
                    ':rating' => $sashFormData['overall_rating'],
                    ':inspector_id' => (int) $user['id'],
                    ':inspector_name' => $sashFormData['inspector_name'],
                    ':inspected_at' => nowUtc(),
                    ':updated_at' => nowUtc(),
                    ':id' => $sashId,
                ]);
            } else {
                $pdo->prepare(
                    'INSERT INTO window_sashes (window_id, sash_number, sash_label, opening_type, position, status,
                     form_data, progress_percent, has_defect, overall_rating, inspector_id, inspector_name, inspected_at, created_at, updated_at)
                     VALUES (:wid, 1, :label, :opening_type, :position, :status, :form_data, :progress, :has_defect,
                     :rating, :inspector_id, :inspector_name, :inspected_at, :created_at, :updated_at)'
                )->execute([
                    ':wid' => $windowId,
                    ':label' => $sashLabel,
                    ':opening_type' => $openingType !== '' ? $openingType : null,
                    ':position' => $position !== '' ? $position : null,
                    ':status' => 'in Bearbeitung',
                    ':form_data' => json_encode($sashFormData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ':progress' => 73,
                    ':has_defect' => $hasDefect ? 1 : 0,
                    ':rating' => $sashFormData['overall_rating'],
                    ':inspector_id' => (int) $user['id'],
                    ':inspector_name' => $sashFormData['inspector_name'],
                    ':inspected_at' => nowUtc(),
                    ':created_at' => nowUtc(),
                    ':updated_at' => nowUtc(),
                ]);
                $sashId = (int) $pdo->lastInsertId();
            }

            $targets[] = [
                'schlagzahl' => $schlagzahl,
                'room_reference' => $group['room_reference'],
                'position' => $position,
                'window_id' => $windowId,
                'sash_id' => $sashId,
            ];
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        apiError(503, 'Excel-Zeilen konnten nicht verarbeitet werden: ' . $e->getMessage());
    }

    apiJson([
        'added' => $added,
        'updated' => $updated,
        'skipped' => $skipped,
        'errors' => array_slice($errors, 0, 10),
        'targets' => $targets,
    ]);
}

function handleUploadPhoto(array $user): never
{
    if (empty($_FILES['photo']) || !is_uploaded_file($_FILES['photo']['tmp_name'])) {
        apiError(400, 'Keine Foto-Datei hochgeladen.');
    }

    $buildingId = isset($_POST['building_id']) ? (int) $_POST['building_id'] : 0;
    $sashId = isset($_POST['sash_id']) ? (int) $_POST['sash_id'] : 0;
    $schlagzahl = normalizeSchlagzahl($_POST['schlagzahl'] ?? '');
    $category = trim((string) ($_POST['category'] ?? 'Fensterkennzeichnung'));
    if ($buildingId <= 0 || $schlagzahl === '') {
        apiError(400, 'building_id und Schlagzahl sind erforderlich.');
    }

    $file = $_FILES['photo'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        apiError(400, 'Upload-Fehler: ' . uploadErrorMessage((int) $file['error']));
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif', 'image/tiff'];
    if (!in_array($mimeType, $allowed, true)) {
        apiError(400, 'Nur Bildtypen JPG, PNG, WEBP, TIFF, HEIC sind erlaubt.');
    }

    if ($sashId > 0) {
        $windowStmt = db()->prepare(
            'SELECT w.id, ws.id AS sash_id
             FROM window_sashes ws
             JOIN windows w ON w.id = ws.window_id
             JOIN rooms ro ON ro.id = w.room_id
             JOIN floors fl ON fl.id = ro.floor_id
             WHERE ws.id = :sid AND fl.building_id = :bid
               AND ws.deleted_at IS NULL AND w.deleted_at IS NULL
             LIMIT 1'
        );
        $windowStmt->execute([':sid' => $sashId, ':bid' => $buildingId]);
    } else {
        $windowStmt = db()->prepare(
            'SELECT w.id, NULL AS sash_id
             FROM windows w
             JOIN rooms ro ON ro.id = w.room_id
             JOIN floors fl ON fl.id = ro.floor_id
             WHERE fl.building_id = :bid AND w.deleted_at IS NULL AND w.window_number = :wn
             LIMIT 1'
        );
        $windowStmt->execute([':bid' => $buildingId, ':wn' => $schlagzahl]);
    }
    $window = $windowStmt->fetch();
    if (!$window) {
        apiError(404, 'Keine passende Schlagzahl im Gebäude gefunden.');
    }
    $windowId = (int) $window['id'];
    $sashId = (int) ($window['sash_id'] ?? 0);

    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'jpg';
    $storageDir = photosDir() . '/' . $windowId;
    if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
        apiError(503, 'Foto-Verzeichnis konnte nicht erstellt werden.');
    }

    $safeName = bin2hex(random_bytes(12)) . '.' . $ext;
    $target = $storageDir . '/' . $safeName;
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        apiError(503, 'Foto konnte nicht gespeichert werden.');
    }

    try {
        db()->prepare(
            'INSERT INTO photos (window_id, sash_id, category, caption, file_name, storage_path, inspector_id, inspector_name, taken_at, created_at)
             VALUES (:wid, :sid, :cat, :cap, :fn, :sp, :uid, :uname, :taken_at, :created_at)'
        )->execute([
            ':wid' => $windowId,
            ':sid' => $sashId > 0 ? $sashId : null,
            ':cat' => $category,
            ':cap' => null,
            ':fn' => $file['name'],
            ':sp' => (string) $windowId . '/' . $safeName,
            ':uid' => (int) $user['id'],
            ':uname' => $user['full_name'] ?: $user['email'],
            ':taken_at' => nowUtc(),
            ':created_at' => nowUtc(),
        ]);
    } catch (Throwable $e) {
        @unlink($target);
        apiError(503, 'Foto konnte nicht in der Datenbank gespeichert werden: ' . $e->getMessage());
    }

    apiJson(['ok' => true, 'window_id' => $windowId, 'sash_id' => $sashId ?: null, 'message' => 'Foto zu Schlagzahl ' . $schlagzahl . ' zugeordnet.']);
}

function handleImportSharePointPhoto(array $user): never
{
    $body = requestBody();
    $buildingId = (int) ($body['building_id'] ?? 0);
    $sashId = (int) ($body['sash_id'] ?? 0);
    $schlagzahl = normalizeSchlagzahl($body['schlagzahl'] ?? '');
    $itemId = trim((string) ($body['item_id'] ?? ''));
    $fileName = basename(trim((string) ($body['file_name'] ?? 'SharePoint-Foto.jpg')));
    $category = trim((string) ($body['category'] ?? 'Fensterkennzeichnung'));
    if ($buildingId <= 0 || $sashId <= 0 || $schlagzahl === '' || $itemId === '') {
        apiError(400, 'Gebäude, Flügel, Schlagzahl und SharePoint-Datei-ID sind erforderlich.');
    }

    $targetStmt = db()->prepare(
        'SELECT w.id, ws.id AS sash_id
         FROM window_sashes ws
         JOIN windows w ON w.id = ws.window_id
         JOIN rooms ro ON ro.id = w.room_id
         JOIN floors fl ON fl.id = ro.floor_id
         WHERE ws.id = :sid AND fl.building_id = :bid AND w.window_number = :wn
           AND ws.deleted_at IS NULL AND w.deleted_at IS NULL LIMIT 1'
    );
    $targetStmt->execute([':sid' => $sashId, ':bid' => $buildingId, ':wn' => $schlagzahl]);
    $target = $targetStmt->fetch(PDO::FETCH_ASSOC);
    if (!$target) {
        apiError(404, 'Der zugeordnete Flügel wurde nicht gefunden.');
    }
    $windowId = (int) $target['id'];

    $duplicate = db()->prepare('SELECT id FROM photos WHERE sash_id = :sid AND file_name = :fn LIMIT 1');
    $duplicate->execute([':sid' => $sashId, ':fn' => $fileName]);
    if ($duplicate->fetchColumn()) {
        apiJson(['ok' => true, 'window_id' => $windowId, 'sash_id' => $sashId, 'message' => 'Foto war bereits vorhanden.']);
    }

    $download = graphDownloadItem($itemId);
    $mimeType = strtolower(trim(explode(';', (string) $download['content_type'])[0]));
    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif', 'image/tiff'];
    if (!in_array($mimeType, $allowed, true)) {
        apiError(400, 'Die SharePoint-Datei ist kein unterstütztes Bildformat.');
    }
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'jpg';
    $storageDir = photosDir() . '/' . $windowId;
    if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
        apiError(503, 'Foto-Verzeichnis konnte nicht erstellt werden.');
    }
    $safeName = bin2hex(random_bytes(12)) . '.' . $ext;
    $filePath = $storageDir . '/' . $safeName;
    if (file_put_contents($filePath, $download['body']) === false) {
        apiError(503, 'Das SharePoint-Foto konnte nicht gespeichert werden.');
    }
    try {
        db()->prepare(
            'INSERT INTO photos (window_id, sash_id, category, caption, file_name, storage_path, inspector_id, inspector_name, taken_at, created_at)
             VALUES (:wid, :sid, :cat, :cap, :fn, :sp, :uid, :uname, :taken_at, :created_at)'
        )->execute([
            ':wid' => $windowId,
            ':sid' => $sashId,
            ':cat' => $category,
            ':cap' => null,
            ':fn' => $fileName,
            ':sp' => (string) $windowId . '/' . $safeName,
            ':uid' => (int) $user['id'],
            ':uname' => $user['full_name'] ?: $user['email'],
            ':taken_at' => nowUtc(),
            ':created_at' => nowUtc(),
        ]);
    } catch (Throwable $e) {
        @unlink($filePath);
        apiError(503, 'Das SharePoint-Foto konnte nicht registriert werden: ' . $e->getMessage());
    }
    apiJson(['ok' => true, 'window_id' => $windowId, 'sash_id' => $sashId, 'message' => 'SharePoint-Foto wurde zugeordnet.']);
}

function detectCsvDelimiter(string $path): string
{
    $sample = @file_get_contents($path, false, null, 0, 4096);
    if ($sample === false || $sample === '') {
        return ';';
    }
    $sample = str_replace(["\r\n", "\r"], "\n", $sample);
    $semicolon = substr_count($sample, ';');
    $comma = substr_count($sample, ',');
    return $comma > $semicolon ? ',' : ';';
}

function isLikelyHeaderRow(array $row): bool
{
    $filled = array_values(array_filter($row, static fn($value) => trim((string) $value) !== ''));
    if ($filled === []) {
        return false;
    }

    $textEntries = 0;
    foreach ($filled as $value) {
        $text = trim((string) $value);
        if (preg_match('/[A-Za-zÄÖÜäöüß]/u', $text) === 1 || preg_match('/\b(Zimmer|Fenster|Beschlag|Glas|Rahmen|Lage|Mangel|Typ|Nr|Nummer|Breite|Hoehe|Aufbau)\b/i', $text) === 1) {
            $textEntries++;
        }
    }

    return $textEntries >= max(2, (int) ceil(count($filled) * 0.6));
}

function combineHeaderParts(array $parts): string
{
    $unique = [];
    $seen = [];
    foreach ($parts as $part) {
        $clean = trim((string) $part);
        if ($clean === '') {
            continue;
        }
        $key = strtolower(str_replace(['_', '-'], ' ', preg_replace('/\s+/', ' ', $clean)));
        if ($key !== '' && !isset($seen[$key])) {
            $unique[] = $clean;
            $seen[$key] = true;
        }
    }

    return implode(' ', $unique);
}

function buildSpreadsheetHeaders(array $rows, int $headerRowCount, int $maxColumns): array
{
    $headers = [];
    $used = [];
    $activeSection = '';
    for ($col = 0; $col < $maxColumns; $col++) {
        $topValue = trim((string) ($rows[0][$col] ?? ''));
        if ($topValue !== '') {
            $activeSection = preg_match('/^(Glas|Rahmen)$/iu', $topValue) === 1 ? $topValue : '';
        }

        $parts = [];
        for ($rowIndex = 0; $rowIndex < $headerRowCount; $rowIndex++) {
            $value = trim((string) ($rows[$rowIndex][$col] ?? ''));
            if ($value !== '') {
                $parts[] = $value;
            }
        }
        $subHeader = trim((string) ($rows[1][$col] ?? ''));
        if ($topValue === '' && $activeSection !== '' && preg_match('/^(Breite|Höhe|Hoehe)$/iu', $subHeader) === 1) {
            array_unshift($parts, $activeSection);
        }

        $name = combineHeaderParts($parts);
        if ($name === '') {
            $name = 'spalte_' . ($col + 1);
        }
        $baseName = $name;
        $suffix = 2;
        while (isset($used[strtolower($name)])) {
            $name = $baseName . ' ' . $suffix++;
        }
        $used[strtolower($name)] = true;
        $headers[$col] = $name;
    }
    return $headers;
}

function detectHeaderRowCount(array $rows): int
{
    $count = 0;
    $limit = min(3, count($rows));
    for ($i = 0; $i < $limit; $i++) {
        if (isLikelyHeaderRow($rows[$i])) {
            $count++;
        } else {
            break;
        }
    }

    return $count;
}

function parseCsvRows(string $path): array
{
    $rows = [];
    if (($handle = fopen($path, 'rb')) === false) {
        return $rows;
    }

    $delimiter = detectCsvDelimiter($path);
    while (($data = fgetcsv($handle, 0, $delimiter)) !== false) {
        if ($data === [null] || $data === []) {
            continue;
        }
        $rows[] = array_map(static fn($v) => trim((string) $v), $data);
    }
    fclose($handle);

    if ($rows === []) {
        return [];
    }

    $headerRowCount = detectHeaderRowCount($rows);
    $headerStart = $headerRowCount > 0 ? 0 : 0;
    $dataStart = max(1, $headerRowCount);

    $maxColumns = 0;
    for ($i = 0; $i < $headerRowCount; $i++) {
        $maxColumns = max($maxColumns, count($rows[$i]));
    }

    $header = buildSpreadsheetHeaders($rows, $headerRowCount, $maxColumns);

    $data = [];
    for ($i = $dataStart; $i < count($rows); $i++) {
        $line = $rows[$i];
        $entry = [];
        foreach ($header as $index => $name) {
            $entry[$name] = $line[$index] ?? '';
        }
        if (count(array_filter($entry, static fn($v) => trim((string) $v) !== '')) > 0) {
            $data[] = $entry;
        }
    }

    return $data;
}

function parseXlsxRows(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        return [];
    }

    $sharedStrings = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $xml = simplexml_load_string((string) $sharedXml);
        if ($xml !== false) {
            foreach ($xml->si as $si) {
                $parts = [];
                if (isset($si->t)) {
                    $parts[] = (string) $si->t;
                }
                foreach ($si->r as $run) {
                    if (isset($run->t)) {
                        $parts[] = (string) $run->t;
                    }
                }
                $sharedStrings[] = implode('', $parts);
            }
        }
    }

    $workbook = $zip->getFromName('xl/workbook.xml');
    $sheetName = 'xl/worksheets/sheet1.xml';
    if ($workbook !== false && ($xml = simplexml_load_string((string) $workbook)) !== false) {
        if (isset($xml->sheets->sheet) && isset($xml->sheets->sheet[0])) {
            $firstSheet = $xml->sheets->sheet[0];
            $rId = (string) $firstSheet['r:id'];
            if ($rId !== '') {
                $rels = simplexml_load_string((string) $zip->getFromName('xl/_rels/workbook.xml.rels'));
                if ($rels !== false) {
                    foreach ($rels->Relationship as $rel) {
                        if ((string) $rel['Id'] === $rId) {
                            $sheetName = 'xl/' . ltrim((string) $rel['Target'], '/');
                            break;
                        }
                    }
                }
            }
        }
    }

    $sheetXml = $zip->getFromName($sheetName);
    if ($sheetXml === false) {
        $zip->close();
        return [];
    }

    $sheet = simplexml_load_string((string) $sheetXml);
    $zip->close();
    if ($sheet === false || !isset($sheet->sheetData->row)) {
        return [];
    }

    $rows = [];
    foreach ($sheet->sheetData->row as $row) {
        $valueMap = [];
        foreach ($row->c as $cell) {
            $cellType = (string) $cell['t'];
            $cellReference = (string) $cell['r'];
            $colIndex = columnFromCellReference($cellReference);
            $cellValue = '';

            if ($cellType === 's') {
                $rawValue = (string) $cell->v;
                $index = (int) $rawValue;
                $cellValue = $sharedStrings[$index] ?? '';
            } elseif ($cellType === 'inlineStr') {
                $cellValue = isset($cell->is->t) ? (string) $cell->is->t : '';
            } elseif ($cellType === 'b') {
                $cellValue = ((string) $cell->v === '1') ? 'true' : 'false';
            } else {
                $cellValue = (string) $cell->v;
                if ($cellValue === '' && isset($cell->is)) {
                    $cellValue = (string) $cell->is->t;
                }
            }

            $valueMap[$colIndex] = trim((string) $cellValue);
        }

        if ($valueMap === []) {
            continue;
        }

        ksort($valueMap);
        $lastColumn = max(array_keys($valueMap));
        $denseRow = [];
        for ($column = 1; $column <= $lastColumn; $column++) {
            $denseRow[] = $valueMap[$column] ?? '';
        }
        $rows[] = $denseRow;
    }

    if ($rows === []) {
        return [];
    }

    $headerRowCount = detectHeaderRowCount($rows);
    $dataStart = max(1, $headerRowCount);
    $maxColumns = 0;
    for ($i = 0; $i < min($headerRowCount, count($rows)); $i++) {
        $maxColumns = max($maxColumns, count($rows[$i]));
    }

    $header = buildSpreadsheetHeaders($rows, $headerRowCount, $maxColumns);

    $data = [];
    for ($i = $dataStart; $i < count($rows); $i++) {
        $values = $rows[$i];
        $entry = [];
        foreach ($header as $idx => $name) {
            $entry[$name] = $values[$idx] ?? '';
        }
        if (count(array_filter($entry, static fn($v) => trim((string) $v) !== '')) > 0) {
            $data[] = $entry;
        }
    }

    return $data;
}

function columnFromCellReference(string $reference): int
{
    $letters = preg_replace('/\d+/', '', strtoupper($reference)) ?? '';
    $value = 0;
    $len = strlen($letters);
    for ($i = 0; $i < $len; $i++) {
        $value = $value * 26 + (ord($letters[$i]) - 64);
    }
    return $value;
}

function uploadErrorMessage(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE,
        UPLOAD_ERR_FORM_SIZE => 'Datei ist zu groß.',
        UPLOAD_ERR_NO_FILE => 'Keine Datei ausgewählt.',
        UPLOAD_ERR_NO_TMP_DIR => 'Kein temporäres Verzeichnis.',
        UPLOAD_ERR_CANT_WRITE => 'Datei konnte nicht geschrieben werden.',
        default => 'Upload-Fehler #' . $code,
    };
}
