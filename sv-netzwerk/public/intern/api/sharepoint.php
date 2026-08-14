<?php
/**
 * SharePoint-Import – SV-Netzwerk Prüfportal
 *
 * Endpoints:
 * - GET  ?action=get_url&building_id={id}
 * - POST ?action=set_url
 * - POST ?action=import_excel (multipart file)
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
    'apply_excel' => handleApplyExcel(),
    'upload_photo' => handleUploadPhoto($user),
    default => apiError(404, 'Unbekannter SharePoint-Endpunkt.'),
};

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

    $stmt = $pdo->prepare('INSERT INTO floors (building_id, name, level, sort_order, created_at, updated_at) VALUES (:bid, :name, 0, 10, :now, :now)');
    $stmt->execute([
        ':bid' => $buildingId,
        ':name' => 'EG / Erdgeschoss',
        ':now' => nowUtc(),
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
        'INSERT INTO rooms (floor_id, name, room_number, sort_order, created_at, updated_at) VALUES (:fid, :name, :rn, :sort_order, :now, :now)'
    );
    $stmt->execute([
        ':fid' => $floorId,
        ':name' => $roomName,
        ':rn' => $roomNumber,
        ':sort_order' => $sortOrder,
        ':now' => nowUtc(),
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

function handleApplyExcel(): never
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

    try {
        $pdo = db();
        $buildingStmt = $pdo->prepare('SELECT id, name, project_id FROM buildings WHERE id = :bid LIMIT 1');
        $buildingStmt->execute([':bid' => $buildingId]);
        $building = $buildingStmt->fetch(PDO::FETCH_ASSOC);
        if (!$building) {
            apiError(404, 'Gebäude nicht gefunden.');
        }

        $existing = $pdo->prepare(
            'SELECT w.id, w.window_number, w.room_id, ro.room_number, ro.name AS room_name
             FROM windows w
             LEFT JOIN rooms ro ON ro.id = w.room_id
             LEFT JOIN floors fl ON fl.id = ro.floor_id
             WHERE fl.building_id = :bid AND w.deleted_at IS NULL'
        );
        $existing->execute([':bid' => $buildingId]);
        $windowMap = [];
        foreach ($existing->fetchAll() as $row) {
            $windowMap[normalizeSchlagzahl($row['window_number'])] = (int) $row['id'];
        }

        foreach ($rows as $row) {
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

            $roomId = ensureBuildingRoom($pdo, $buildingId, $row);
            $windowNumber = excelWindowNumber($row, $schlagzahl);
            $windowExists = $pdo->prepare('SELECT id FROM windows WHERE room_id = :rid AND window_number = :wn AND deleted_at IS NULL LIMIT 1');
            $windowExists->execute([':rid' => $roomId, ':wn' => $windowNumber]);
            $existingWindow = $windowExists->fetch(PDO::FETCH_ASSOC);

            $formData = ['import_source' => 'sharepoint_excel', 'schlagzahl' => $schlagzahl, 'room_reference' => excelRowLookup($row, ['Zimmer', 'Zimmernummer', 'Zimmer Nr', 'Raum', 'Raumnummer', 'room_number', 'A'])];
            foreach ($row as $key => $value) {
                $formData[(string) $key] = $value;
            }

            if ($existingWindow) {
                $pdo->prepare(
                    'UPDATE windows SET form_data = :fd, updated_at = :now WHERE id = :id'
                )->execute([
                    ':fd' => json_encode($formData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ':now' => nowUtc(),
                    ':id' => (int) $existingWindow['id'],
                ]);
                $updated++;
                continue;
            }

            $recordId = 'SP-' . strtoupper(bin2hex(random_bytes(6)));
            $stmt = $pdo->prepare(
                'INSERT INTO windows (project_id, room_id, record_id, window_number, room_label, room_number, building_label, floor_label, status, form_data, created_at, updated_at)
                 VALUES (:pid, :rid, :record_id, :wn, :room_label, :room_number, :building_label, :floor_label, :status, :form_data, :now, :now)'
            );
            $stmt->execute([
                ':pid' => (int) $building['project_id'],
                ':rid' => $roomId,
                ':record_id' => $recordId,
                ':wn' => $windowNumber,
                ':room_label' => trim((string) ($row['Zimmer'] ?? $row['Raum'] ?? '')) ?: 'Import',
                ':room_number' => trim((string) (excelRowLookup($row, ['Zimmer', 'Zimmernummer', 'Zimmer Nr', 'Raum', 'Raumnummer', 'room_number', 'A']) ?: '')),
                ':building_label' => (string) $building['name'],
                ':floor_label' => 'EG / Erdgeschoss',
                ':status' => 'nicht begonnen',
                ':form_data' => json_encode($formData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ':now' => nowUtc(),
            ]);
            $added++;
        }
    } catch (Throwable $e) {
        apiError(503, 'Excel-Zeilen konnten nicht verarbeitet werden: ' . $e->getMessage());
    }

    apiJson([
        'added' => $added,
        'updated' => $updated,
        'skipped' => $skipped,
        'errors' => array_slice($errors, 0, 10),
    ]);
}

function handleUploadPhoto(array $user): never
{
    if (empty($_FILES['photo']) || !is_uploaded_file($_FILES['photo']['tmp_name'])) {
        apiError(400, 'Keine Foto-Datei hochgeladen.');
    }

    $buildingId = isset($_POST['building_id']) ? (int) $_POST['building_id'] : 0;
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

    $windowStmt = db()->prepare(
        'SELECT w.id
         FROM windows w
         JOIN rooms ro ON ro.id = w.room_id
         JOIN floors fl ON fl.id = ro.floor_id
         WHERE fl.building_id = :bid AND w.deleted_at IS NULL AND w.window_number = :wn
         LIMIT 1'
    );
    $windowStmt->execute([':bid' => $buildingId, ':wn' => $schlagzahl]);
    $window = $windowStmt->fetch();
    if (!$window) {
        apiError(404, 'Keine passende Schlagzahl im Gebäude gefunden.');
    }
    $windowId = (int) $window['id'];

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
             VALUES (:wid, NULL, :cat, :cap, :fn, :sp, :uid, :uname, :now, :now)'
        )->execute([
            ':wid' => $windowId,
            ':cat' => $category,
            ':cap' => null,
            ':fn' => $file['name'],
            ':sp' => (string) $windowId . '/' . $safeName,
            ':uid' => (int) $user['id'],
            ':uname' => $user['full_name'] ?: $user['email'],
            ':now' => nowUtc(),
        ]);
    } catch (Throwable $e) {
        @unlink($target);
        apiError(503, 'Foto konnte nicht in der Datenbank gespeichert werden: ' . $e->getMessage());
    }

    apiJson(['ok' => true, 'window_id' => $windowId, 'message' => 'Foto zu Schlagzahl ' . $schlagzahl . ' zugeordnet.']);
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

    $header = [];
    $maxColumns = 0;
    for ($i = 0; $i < $headerRowCount; $i++) {
        $maxColumns = max($maxColumns, count($rows[$i]));
    }

    for ($col = 0; $col < $maxColumns; $col++) {
        $parts = [];
        for ($rowIndex = 0; $rowIndex < $headerRowCount; $rowIndex++) {
            $current = $rows[$rowIndex][$col] ?? '';
            if ($current !== '') {
                $parts[] = $current;
            }
        }
        $header[$col] = combineHeaderParts($parts);
        if ($header[$col] === '') {
            $header[$col] = 'spalte_' . ($col + 1);
        }
    }

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
                foreach ($si->t as $t) {
                    $parts[] = (string) $t;
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
        $rows[] = array_values($valueMap);
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

    $header = [];
    for ($col = 0; $col < $maxColumns; $col++) {
        $parts = [];
        for ($rowIndex = 0; $rowIndex < $headerRowCount; $rowIndex++) {
            $value = $rows[$rowIndex][$col] ?? '';
            if ($value !== '') {
                $parts[] = $value;
            }
        }
        $header[$col] = combineHeaderParts($parts);
        if ($header[$col] === '') {
            $header[$col] = 'spalte_' . ($col + 1);
        }
    }

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
