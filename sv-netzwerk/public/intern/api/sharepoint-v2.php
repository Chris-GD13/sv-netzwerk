<?php
/**
 * SharePoint-Import V2 – rekursiver Projektimport fuer das SV-Netzwerk Pruefportal.
 *
 * Diese Datei wird fuer ausgewählte Aktionen per .htaccess vor sharepoint.php geschaltet.
 * Sie liest den kompletten Projektbaum rekursiv, konsolidiert alle Tabellen, vervollstaendigt
 * fehlende technische Werte aus vergleichbaren Fenstern und erzeugt je Fenster eine feste
 * Fluegelhierarchie: 1 = Haupt/Dreh-Kipp, 2 = Neben/Kipp.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

commonHeaders();
$user = requireAuth();
$action = $_GET['action'] ?? '';

match ($action) {
    'import_sharepoint_excel' => handleImportSharePointExcelV2(),
    'list_sharepoint_photos' => handleListSharePointPhotosV2(),
    'list_sharepoint_documents' => handleListSharePointDocumentsV2(),
    'apply_excel' => handleApplyExcelV2($user),
    default => apiError(404, 'Unbekannter SharePoint-V2-Endpunkt.'),
};

function v2GraphConfig(string $key, string $default = ''): string
{
    $value = getenv($key);
    return $value === false || trim($value) === '' ? $default : trim($value);
}

function v2GraphAccessToken(): string
{
    static $token = null;
    if (is_string($token) && $token !== '') return $token;

    $tenantId = v2GraphConfig('MS_TENANT_ID');
    $clientId = v2GraphConfig('MS_CLIENT_ID');
    $clientSecret = v2GraphConfig('MS_CLIENT_SECRET');
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
        apiError(503, 'Microsoft-Anmeldung fuer den SharePoint-Import fehlgeschlagen.' . ($error !== '' ? ' ' . $error : ''));
    }
    return $token = (string) $decoded['access_token'];
}

function v2GraphRequest(string $url, bool $binary = false): array|string
{
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => $binary ? 180 : 60,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . v2GraphAccessToken()],
    ]);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $contentType = (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
    $error = curl_error($curl);
    curl_close($curl);

    if ($status < 200 || $status >= 300 || !is_string($response)) {
        $detail = '';
        if (is_string($response) && $response !== '') {
            $errorBody = json_decode($response, true);
            $graphError = is_array($errorBody) ? ($errorBody['error'] ?? null) : null;
            if (is_array($graphError)) {
                $code = trim((string) ($graphError['code'] ?? ''));
                $message = trim((string) ($graphError['message'] ?? ''));
                $detail = trim($code . ($code !== '' && $message !== '' ? ': ' : '') . $message);
            }
        }
        apiError(503, 'SharePoint konnte nicht gelesen werden (HTTP ' . $status . ').'
            . ($detail !== '' ? ' ' . $detail : '')
            . ($error !== '' ? ' ' . $error : ''));
    }

    if ($binary) return ['body' => $response, 'content_type' => $contentType];
    $decoded = json_decode($response, true);
    if (!is_array($decoded)) apiError(503, 'SharePoint hat eine ungueltige Antwort geliefert.');
    return $decoded;
}

function v2GraphSiteId(): string
{
    static $siteId = null;
    if (is_string($siteId) && $siteId !== '') return $siteId;
    $configured = v2GraphConfig('MS_SHAREPOINT_SITE_ID');
    if ($configured !== '') return $siteId = $configured;

    $host = v2GraphConfig('MS_SHAREPOINT_HOST', 'sv1schuett.sharepoint.com');
    $path = v2GraphConfig('MS_SHAREPOINT_SITE_PATH', '/sites/SVBroSchtt');
    $site = v2GraphRequest('https://graph.microsoft.com/v1.0/sites/' . rawurlencode($host) . ':' . str_replace('%2F', '/', rawurlencode($path)) . '?$select=id');
    $siteId = (string) ($site['id'] ?? '');
    if ($siteId === '') apiError(503, 'Die konfigurierte SharePoint-Site wurde nicht gefunden.');
    return $siteId;
}

function v2GraphDriveId(): string
{
    static $driveId = null;
    if (is_string($driveId) && $driveId !== '') return $driveId;
    $configured = v2GraphConfig('MS_SHAREPOINT_DRIVE_ID');
    if ($configured !== '') return $driveId = $configured;

    $drive = v2GraphRequest('https://graph.microsoft.com/v1.0/sites/' . rawurlencode(v2GraphSiteId()) . '/drive?$select=id');
    $driveId = (string) ($drive['id'] ?? '');
    if ($driveId === '') apiError(503, 'Die SharePoint-Dokumentbibliothek wurde nicht gefunden.');
    return $driveId;
}

function v2GraphItemByPath(string $path): array
{
    $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn($part) => $part !== ''));
    if ($segments === []) {
        return v2GraphRequest('https://graph.microsoft.com/v1.0/drives/' . rawurlencode(v2GraphDriveId()) . '/root?$select=id,name,size,file,folder');
    }

    $parentId = '';
    $matched = null;
    foreach ($segments as $segment) {
        $url = 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode(v2GraphDriveId())
            . ($parentId === '' ? '/root/children' : '/items/' . rawurlencode($parentId) . '/children')
            . '?$select=id,name,size,file,folder&$top=200';
        $matched = null;
        while ($url !== '') {
            $page = v2GraphRequest($url);
            foreach (($page['value'] ?? []) as $item) {
                if (is_array($item) && strcasecmp((string) ($item['name'] ?? ''), $segment) === 0) {
                    $matched = $item;
                    break 2;
                }
            }
            $url = (string) ($page['@odata.nextLink'] ?? '');
        }
        if (!is_array($matched) || empty($matched['id'])) apiError(404, 'SharePoint-Pfad nicht gefunden: ' . $segment);
        $parentId = (string) $matched['id'];
    }
    return $matched;
}

function v2GraphDownloadItem(string $itemId): array
{
    return v2GraphRequest('https://graph.microsoft.com/v1.0/drives/' . rawurlencode(v2GraphDriveId()) . '/items/' . rawurlencode($itemId) . '/content', true);
}

function v2ListTree(string $rootId): array
{
    $items = [];
    $queue = [[$rootId, '']];
    while ($queue !== []) {
        [$parentId, $relativePath] = array_shift($queue);
        $url = 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode(v2GraphDriveId()) . '/items/' . rawurlencode((string) $parentId)
            . '/children?$select=id,name,size,file,folder,createdDateTime,lastModifiedDateTime&$top=200';
        while ($url !== '') {
            $page = v2GraphRequest($url);
            foreach (($page['value'] ?? []) as $item) {
                if (!is_array($item)) continue;
                $name = trim((string) ($item['name'] ?? ''));
                if ($name === '') continue;
                $itemPath = ltrim($relativePath . '/' . $name, '/');
                $item['relative_path'] = $itemPath;
                $items[] = $item;
                if (!empty($item['folder']) && !empty($item['id'])) {
                    $queue[] = [(string) $item['id'], $itemPath];
                }
            }
            $url = (string) ($page['@odata.nextLink'] ?? '');
        }
    }
    return $items;
}

function v2ProjectRootPath(): string
{
    $excelPath = v2GraphConfig(
        'MS_SHAREPOINT_EXCEL_PATH',
        'VS Schäden/Marc/Privatgutachten/2026/Bundesministerium Verteidigung_Bonn/BW fesnterprüfung.xlsx'
    );
    return v2GraphConfig('MS_SHAREPOINT_PROJECT_PATH', dirname($excelPath));
}

function v2ProjectTree(): array
{
    $folder = v2GraphItemByPath(v2ProjectRootPath());
    $folderId = (string) ($folder['id'] ?? '');
    if ($folderId === '') apiError(404, 'Der SharePoint-Projektordner wurde nicht gefunden.');
    return ['folder' => $folder, 'items' => v2ListTree($folderId)];
}

function v2IsSpreadsheet(string $name): bool
{
    return preg_match('/\.(xlsx|xlsm|xlsb|xls|csv)$/i', $name) === 1;
}

function v2NormalizeKey(string $value): string
{
    $value = mb_strtolower(trim($value), 'UTF-8');
    $value = strtr($value, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $value));
}

function v2RowValue(array $row, array $needles): string
{
    $normalizedNeedles = array_map('v2NormalizeKey', $needles);
    foreach ($row as $key => $value) {
        if (str_starts_with((string) $key, '__')) continue;
        $candidate = trim((string) $value);
        if ($candidate === '') continue;
        $normalizedKey = v2NormalizeKey((string) $key);
        foreach ($normalizedNeedles as $needle) {
            if ($normalizedKey === $needle || str_contains($normalizedKey, $needle)) return $candidate;
        }
    }
    return '';
}

function v2NormalizeNumber(string $value): int|float|string
{
    $normalized = trim($value);
    if (str_contains($normalized, ',')) {
        $normalized = str_replace('.', '', $normalized);
        $normalized = str_replace(',', '.', $normalized);
    }
    if ($normalized === '' || !is_numeric($normalized)) return $value;
    $number = (float) $normalized;
    return floor($number) === $number ? (int) $number : $number;
}

function v2NormalizeWindowNumber(mixed $value): string
{
    $text = trim((string) $value);
    if ($text === '') return '';
    if (preg_match('/\d+/', $text, $match)) {
        $number = ltrim($match[0], '0');
        return $number === '' ? '0' : $number;
    }
    return $text;
}

function v2DetectOpeningType(array $row): string
{
    $value = mb_strtolower(v2RowValue($row, ['Beschlag', 'Oeffnungsart', 'Öffnungsart', 'Fenstertyp', 'Fluegeltyp', 'Flügeltyp', 'Typ', 'Lage', 'Position']), 'UTF-8');
    if (preg_match('/dreh\s*[- ]?kipp|drehkipp|\bdk\b/u', $value)) return 'Dreh-Kipp';
    if (preg_match('/\bkipp\b|kippelement/u', $value)) return 'Kipp';
    if (preg_match('/\bdreh\b/u', $value)) return 'Dreh';
    if (preg_match('/fest|festverglas/u', $value)) return 'Festverglasung';
    return '';
}

function v2OpeningPriority(array $row): int
{
    return match (v2DetectOpeningType($row)) {
        'Dreh-Kipp' => 10,
        'Dreh' => 20,
        'Kipp' => 30,
        'Festverglasung' => 40,
        default => 50,
    };
}

function v2HeaderLikelihood(array $row): bool
{
    $filled = array_values(array_filter($row, static fn($v) => trim((string) $v) !== ''));
    if ($filled === []) return false;
    $text = implode(' ', array_map('strval', $filled));
    return preg_match('/zimmer|raum|fenster|beschlag|glas|rahmen|lage|mangel|breite|hoehe|höhe|aufbau|nummer/i', $text) === 1;
}

function v2ColumnFromCellReference(string $reference): int
{
    $letters = preg_replace('/\d+/', '', strtoupper($reference)) ?? '';
    $value = 0;
    for ($i = 0, $len = strlen($letters); $i < $len; $i++) $value = $value * 26 + (ord($letters[$i]) - 64);
    return $value;
}

function v2BuildHeaders(array $rows, int $headerRows, int $maxColumns): array
{
    $headers = [];
    $used = [];
    $activeSection = '';
    for ($col = 0; $col < $maxColumns; $col++) {
        $top = trim((string) ($rows[0][$col] ?? ''));
        if ($top !== '') $activeSection = preg_match('/^(Glas|Rahmen)$/iu', $top) ? $top : '';
        $parts = [];
        for ($r = 0; $r < $headerRows; $r++) {
            $value = trim((string) ($rows[$r][$col] ?? ''));
            if ($value !== '') $parts[] = $value;
        }
        $sub = trim((string) ($rows[1][$col] ?? ''));
        if ($top === '' && $activeSection !== '' && preg_match('/^(Breite|Höhe|Hoehe)$/iu', $sub)) array_unshift($parts, $activeSection);
        $parts = array_values(array_unique($parts));
        $name = trim(implode(' ', $parts));
        if ($name === '') $name = 'spalte_' . ($col + 1);
        $base = $name;
        $suffix = 2;
        while (isset($used[v2NormalizeKey($name)])) $name = $base . ' ' . $suffix++;
        $used[v2NormalizeKey($name)] = true;
        $headers[$col] = $name;
    }
    return $headers;
}

function v2RowsToAssoc(array $rows): array
{
    if ($rows === []) return [];
    $headerRows = 0;
    for ($i = 0; $i < min(3, count($rows)); $i++) {
        if (v2HeaderLikelihood($rows[$i])) $headerRows++; else break;
    }
    if ($headerRows === 0) $headerRows = 1;
    $maxColumns = 0;
    for ($i = 0; $i < min($headerRows, count($rows)); $i++) $maxColumns = max($maxColumns, count($rows[$i]));
    $headers = v2BuildHeaders($rows, $headerRows, $maxColumns);

    $data = [];
    for ($i = $headerRows; $i < count($rows); $i++) {
        $entry = [];
        foreach ($headers as $index => $name) $entry[$name] = trim((string) ($rows[$i][$index] ?? ''));
        if (count(array_filter($entry, static fn($v) => $v !== '')) > 0) $data[] = $entry;
    }
    return $data;
}

function v2ParseCsv(string $path): array
{
    $sample = @file_get_contents($path, false, null, 0, 4096) ?: '';
    $delimiter = substr_count($sample, ',') > substr_count($sample, ';') ? ',' : ';';
    $raw = [];
    $handle = fopen($path, 'rb');
    if ($handle === false) return [];
    while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
        if ($row === [null] || $row === []) continue;
        $raw[] = array_map(static fn($v) => trim((string) $v), $row);
    }
    fclose($handle);
    return v2RowsToAssoc($raw);
}

function v2ParseXlsx(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return [];

    $sharedStrings = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false && ($xml = simplexml_load_string((string) $sharedXml)) !== false) {
        foreach ($xml->si as $si) {
            $parts = [];
            if (isset($si->t)) $parts[] = (string) $si->t;
            foreach ($si->r as $run) if (isset($run->t)) $parts[] = (string) $run->t;
            $sharedStrings[] = implode('', $parts);
        }
    }

    $sheetName = 'xl/worksheets/sheet1.xml';
    $workbookXml = $zip->getFromName('xl/workbook.xml');
    if ($workbookXml !== false && ($workbook = simplexml_load_string((string) $workbookXml)) !== false && isset($workbook->sheets->sheet[0])) {
        $namespaces = $workbook->getNamespaces(true);
        $rNs = $namespaces['r'] ?? null;
        $attrs = $rNs ? $workbook->sheets->sheet[0]->attributes($rNs) : null;
        $rId = $attrs ? (string) ($attrs['id'] ?? '') : '';
        if ($rId !== '') {
            $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
            if ($relsXml !== false && ($rels = simplexml_load_string((string) $relsXml)) !== false) {
                foreach ($rels->Relationship as $rel) {
                    if ((string) $rel['Id'] === $rId) {
                        $target = ltrim((string) $rel['Target'], '/');
                        $sheetName = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
                        break;
                    }
                }
            }
        }
    }

    $sheetXml = $zip->getFromName($sheetName);
    if ($sheetXml === false) { $zip->close(); return []; }
    $sheet = simplexml_load_string((string) $sheetXml);
    $zip->close();
    if ($sheet === false || !isset($sheet->sheetData->row)) return [];

    $rawRows = [];
    foreach ($sheet->sheetData->row as $row) {
        $map = [];
        foreach ($row->c as $cell) {
            $col = v2ColumnFromCellReference((string) $cell['r']);
            $type = (string) $cell['t'];
            $value = '';
            if ($type === 's') $value = $sharedStrings[(int) ((string) $cell->v)] ?? '';
            elseif ($type === 'inlineStr') {
                if (isset($cell->is->t)) $value = (string) $cell->is->t;
                else {
                    $parts = [];
                    foreach ($cell->is->r as $run) if (isset($run->t)) $parts[] = (string) $run->t;
                    $value = implode('', $parts);
                }
            } elseif ($type === 'b') $value = ((string) $cell->v === '1') ? 'true' : 'false';
            else $value = (string) $cell->v;
            if ($col > 0) $map[$col] = trim($value);
        }
        if ($map === []) continue;
        ksort($map);
        $last = max(array_keys($map));
        $dense = [];
        for ($i = 1; $i <= $last; $i++) $dense[] = $map[$i] ?? '';
        $rawRows[] = $dense;
    }
    return v2RowsToAssoc($rawRows);
}

function v2SpreadsheetRows(string $itemId, string $name): array
{
    $download = v2GraphDownloadItem($itemId);
    $tmp = tempnam(sys_get_temp_dir(), 'sp-v2-');
    if ($tmp === false || file_put_contents($tmp, $download['body']) === false) apiError(503, 'SharePoint-Datei konnte nicht zwischengespeichert werden.');
    try {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return $ext === 'csv' ? v2ParseCsv($tmp) : v2ParseXlsx($tmp);
    } finally {
        @unlink($tmp);
    }
}

function v2CanonicalFieldMap(): array
{
    return [
        'room_reference' => ['Zimmer', 'Zimmernummer', 'Zimmer Nr', 'Zimmer-Nr', 'Raum', 'Raumnummer', 'room_number'],
        'window_number' => ['Schlagzahl', 'Schlag-Zahl', 'SZ', 'Fensternummer', 'Fenster Nr', 'Fenster-Nr', 'Fenster-Nr.', 'window_number', 'Nummer', 'Nr'],
        'position' => ['Lage', 'Position'],
        'glass_structure' => ['Glasaufbau', 'Glas Aufbau', 'Verglasungsaufbau'],
        'glass_width' => ['Glas Breite', 'Glasbreite', 'Verglasung Breite'],
        'glass_height' => ['Glas Höhe', 'Glas Hoehe', 'Glashöhe', 'Verglasung Höhe'],
        'frame_width' => ['Rahmen Breite', 'Rahmenbreite'],
        'frame_height' => ['Rahmen Höhe', 'Rahmen Hoehe', 'Rahmenhöhe'],
        'hardware_system' => ['Beschlagsystem', 'Beschlag System', 'Profilserie', 'System'],
        'manufacturer' => ['Hersteller', 'Fensterhersteller'],
        'construction_year' => ['Baujahr'],
        'frame_material' => ['Rahmenmaterial', 'Material'],
        'section_label' => ['Gebäudeteil', 'Gebaeudeteil', 'Bauteil'],
        'floor_label' => ['Etage', 'Geschoss'],
        'orientation' => ['Himmelsrichtung', 'Orientierung'],
        'object_label' => ['Objektkennzeichnung', 'Kennzeichnung'],
        'description' => ['Beschreibungen', 'Beschreibung', 'Mangel', 'Feststellung', 'Bemerkung'],
    ];
}

function v2CanonicalizeRow(array $row): array
{
    $canonical = $row;
    foreach (v2CanonicalFieldMap() as $field => $needles) $canonical['__' . $field] = v2RowValue($row, $needles);
    $canonical['__window_number'] = v2NormalizeWindowNumber($canonical['__window_number']);
    $canonical['__opening_type'] = v2DetectOpeningType($row);
    return $canonical;
}

function v2ImputeMissingValues(array $rows): array
{
    if ($rows === []) return $rows;
    $technicalFields = [
        '__glass_structure', '__glass_width', '__glass_height', '__frame_width', '__frame_height',
        '__hardware_system', '__manufacturer', '__construction_year', '__frame_material',
        '__section_label', '__floor_label', '__orientation', '__object_label',
    ];

    $mode = static function (array $values): string {
        $counts = [];
        $original = [];
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value === '') continue;
            $key = mb_strtolower($value, 'UTF-8');
            $counts[$key] = ($counts[$key] ?? 0) + 1;
            $original[$key] = $value;
        }
        if ($counts === []) return '';
        arsort($counts, SORT_NUMERIC);
        $key = (string) array_key_first($counts);
        return $original[$key] ?? '';
    };

    foreach ($rows as &$row) {
        $row['__imputed_fields'] = [];
        foreach ($technicalFields as $field) {
            if (trim((string) ($row[$field] ?? '')) !== '') continue;
            $room = (string) ($row['__room_reference'] ?? '');
            $opening = (string) ($row['__opening_type'] ?? '');
            $tiers = [
                array_filter($rows, static fn($r) => ($r['__room_reference'] ?? '') === $room && ($r['__opening_type'] ?? '') === $opening),
                array_filter($rows, static fn($r) => $opening !== '' && ($r['__opening_type'] ?? '') === $opening),
                array_filter($rows, static fn($r) => $room !== '' && ($r['__room_reference'] ?? '') === $room),
                $rows,
            ];
            foreach ($tiers as $tierIndex => $tier) {
                $value = $mode(array_map(static fn($r) => (string) ($r[$field] ?? ''), $tier));
                if ($value === '') continue;
                $row[$field] = $value;
                $row['__imputed_fields'][substr($field, 2)] = [
                    'value' => $value,
                    'method' => match ($tierIndex) {
                        0 => 'gleicher Raum und gleicher Fluegeltyp',
                        1 => 'gleicher Fluegeltyp',
                        2 => 'gleicher Raum',
                        default => 'Modalwert des Projektbestands',
                    },
                ];
                break;
            }
        }
    }
    unset($row);
    return $rows;
}

function v2SortRows(array &$rows): void
{
    usort($rows, static function (array $a, array $b): int {
        $roomCmp = strnatcasecmp((string) ($a['__room_reference'] ?? ''), (string) ($b['__room_reference'] ?? ''));
        if ($roomCmp !== 0) return $roomCmp;
        $windowCmp = strnatcasecmp((string) ($a['__window_number'] ?? ''), (string) ($b['__window_number'] ?? ''));
        if ($windowCmp !== 0) return $windowCmp;
        $priorityCmp = v2OpeningPriority($a) <=> v2OpeningPriority($b);
        if ($priorityCmp !== 0) return $priorityCmp;
        return strnatcasecmp((string) ($a['__position'] ?? ''), (string) ($b['__position'] ?? ''));
    });
}

function handleImportSharePointExcelV2(): never
{
    $tree = v2ProjectTree();
    $files = array_values(array_filter($tree['items'], static function (array $item): bool {
        return !empty($item['file']) && v2IsSpreadsheet((string) ($item['name'] ?? ''));
    }));
    usort($files, static fn($a, $b) => strnatcasecmp((string) ($a['relative_path'] ?? ''), (string) ($b['relative_path'] ?? '')));
    if ($files === []) apiError(404, 'Im SharePoint-Projektordner und seinen Unterordnern wurden keine Excel-/CSV-Dateien gefunden.');

    $allRows = [];
    $columns = [];
    $fileNames = [];
    $errors = [];
    foreach ($files as $file) {
        $id = (string) ($file['id'] ?? '');
        $name = (string) ($file['name'] ?? '');
        if ($id === '' || $name === '') continue;
        try {
            $rows = v2SpreadsheetRows($id, $name);
        } catch (Throwable $e) {
            $errors[] = ($file['relative_path'] ?? $name) . ': ' . $e->getMessage();
            continue;
        }
        $fileNames[] = (string) ($file['relative_path'] ?? $name);
        foreach ($rows as $row) {
            $row['__source_file'] = (string) ($file['relative_path'] ?? $name);
            $canonical = v2CanonicalizeRow($row);
            foreach (array_keys($row) as $column) if (!str_starts_with((string) $column, '__')) $columns[$column] = true;
            $allRows[] = $canonical;
        }
    }
    if ($allRows === []) apiError(422, 'Die gefundenen Excel-Dateien enthielten keine lesbaren Daten.');

    $allRows = v2ImputeMissingValues($allRows);
    v2SortRows($allRows);

    foreach ($allRows as &$row) {
        if (!isset($row['schlagzahl']) || trim((string) $row['schlagzahl']) === '') $row['schlagzahl'] = (string) ($row['__window_number'] ?? '');
        if (!isset($row['Raumnummer']) || trim((string) $row['Raumnummer']) === '') $row['Raumnummer'] = (string) ($row['__room_reference'] ?? '');
        if (!isset($row['Lage']) || trim((string) $row['Lage']) === '') $row['Lage'] = (string) ($row['__position'] ?? '');
    }
    unset($row);
    $columns = array_values(array_unique(array_merge(['schlagzahl', 'Raumnummer', 'Lage'], array_keys($columns))));

    apiJson([
        'ok' => true,
        'file_name' => count($fileNames) . ' SharePoint-Tabellen zusammengefuehrt',
        'file_names' => $fileNames,
        'files_processed' => count($fileNames),
        'rows' => $allRows,
        'columns' => $columns,
        'warnings' => array_slice($errors, 0, 20),
    ]);
}

function handleListSharePointPhotosV2(): never
{
    $tree = v2ProjectTree();
    $photos = [];
    foreach ($tree['items'] as $item) {
        if (empty($item['file'])) continue;
        $name = (string) ($item['name'] ?? '');
        if (!preg_match('/\.(jpe?g|png|webp|tiff?|heic|heif)$/i', $name)) continue;
        $photos[] = [
            'id' => (string) ($item['id'] ?? ''),
            'name' => $name,
            'path' => (string) ($item['relative_path'] ?? $name),
            'size' => (int) ($item['size'] ?? 0),
            'mime_type' => (string) ($item['file']['mimeType'] ?? 'application/octet-stream'),
        ];
    }
    usort($photos, static fn($a, $b) => strnatcasecmp((string) ($a['path'] ?? $a['name']), (string) ($b['path'] ?? $b['name'])));
    apiJson(['ok' => true, 'folder_name' => (string) ($tree['folder']['name'] ?? basename(v2ProjectRootPath())), 'photos' => $photos]);
}

function handleListSharePointDocumentsV2(): never
{
    $tree = v2ProjectTree();
    $documents = [];
    foreach ($tree['items'] as $item) {
        if (empty($item['file'])) continue;
        $name = (string) ($item['name'] ?? '');
        if (!preg_match('/\.pdf$/i', $name)) continue;
        $documents[] = [
            'id' => (string) ($item['id'] ?? ''),
            'name' => $name,
            'path' => (string) ($item['relative_path'] ?? $name),
            'size' => (int) ($item['size'] ?? 0),
            'modified_at' => (string) ($item['lastModifiedDateTime'] ?? ''),
        ];
    }
    usort($documents, static fn($a, $b) => strnatcasecmp((string) $a['path'], (string) $b['path']));
    apiJson(['ok' => true, 'folder_name' => (string) ($tree['folder']['name'] ?? basename(v2ProjectRootPath())), 'documents' => $documents]);
}

function v2EnsureFloor(PDO $pdo, int $buildingId, string $floorLabel): int
{
    $floorLabel = trim($floorLabel) !== '' ? trim($floorLabel) : 'EG / Erdgeschoss';
    $stmt = $pdo->prepare('SELECT id FROM floors WHERE building_id = :bid AND name = :name ORDER BY id LIMIT 1');
    $stmt->execute([':bid' => $buildingId, ':name' => $floorLabel]);
    $id = $stmt->fetchColumn();
    if ($id) return (int) $id;

    $sort = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM floors WHERE building_id = :bid');
    $sort->execute([':bid' => $buildingId]);
    $sortOrder = (int) $sort->fetchColumn();
    $insert = $pdo->prepare('INSERT INTO floors (building_id, name, level, sort_order, created_at, updated_at) VALUES (:bid, :name, 0, :sort_order, :now, :now)');
    $insert->execute([':bid' => $buildingId, ':name' => $floorLabel, ':sort_order' => $sortOrder, ':now' => nowUtc()]);
    return (int) $pdo->lastInsertId();
}

function v2EnsureRoom(PDO $pdo, int $buildingId, string $floorLabel, string $roomNumber): int
{
    $floorId = v2EnsureFloor($pdo, $buildingId, $floorLabel);
    $roomNumber = trim($roomNumber);
    if ($roomNumber === '') $roomNumber = 'unbekannt';
    $stmt = $pdo->prepare('SELECT id FROM rooms WHERE floor_id = :fid AND room_number = :rn LIMIT 1');
    $stmt->execute([':fid' => $floorId, ':rn' => $roomNumber]);
    $id = $stmt->fetchColumn();
    if ($id) return (int) $id;

    $sort = $pdo->prepare('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM rooms WHERE floor_id = :fid');
    $sort->execute([':fid' => $floorId]);
    $sortOrder = (int) $sort->fetchColumn();
    $name = 'Raum ' . $roomNumber;
    $insert = $pdo->prepare('INSERT INTO rooms (floor_id, name, room_number, sort_order, created_at, updated_at) VALUES (:fid, :name, :rn, :sort_order, :now, :now)');
    $insert->execute([':fid' => $floorId, ':name' => $name, ':rn' => $roomNumber, ':sort_order' => $sortOrder, ':now' => nowUtc()]);
    return (int) $pdo->lastInsertId();
}

function v2GlassDetails(string $structure): array
{
    $numbers = [];
    if (preg_match_all('/\d+(?:[.,]\d+)?/', $structure, $matches)) {
        $numbers = array_map(static fn($v) => (float) str_replace(',', '.', $v), $matches[0]);
    }
    $glass = [];
    $cavities = [];
    foreach ($numbers as $index => $number) {
        if ($index % 2 === 0) $glass[] = $number; else $cavities[] = $number;
    }
    return [
        'panes' => count($glass),
        'thickness' => array_sum($glass),
        'cavities' => implode('/', array_map(static fn($v) => rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.'), $cavities)),
    ];
}

function v2Description(array $rows): string
{
    $values = [];
    foreach ($rows as $row) {
        $value = trim((string) ($row['__description'] ?? ''));
        if ($value !== '' && !in_array($value, $values, true)) $values[] = $value;
    }
    return implode(' | ', $values);
}

function v2HasDefect(string $description): bool
{
    $normalized = mb_strtolower(trim($description), 'UTF-8');
    if ($normalized === '') return false;
    if (preg_match('/\b(ok|i\.?\s*o\.?|ohne\s+mangel|sonst\s+ok)\b/u', $normalized) === 1
        && preg_match('/defekt|fehlt|wartung|schleif|haeng|häng|gebrochen|schwergaeng|schwergäng|beschaedig|beschädig|tausch|klemmt/u', $normalized) !== 1) return false;
    return preg_match('/defekt|fehlt|wartung|schleif|haeng|häng|gebrochen|schwergaeng|schwergäng|beschaedig|beschädig|tausch|klemmt|einstell|nachstell/u', $normalized) === 1;
}

function v2Rating(string $description, bool $hasDefect): string
{
    $text = mb_strtolower($description, 'UTF-8');
    if (preg_match('/wartung|nachstell|einstell|schleif|schwergaeng|schwergäng/u', $text)) return 'Wartung oder Nachstellung erforderlich';
    if (preg_match('/defekt|gebrochen|beschaedig|beschädig|fehlt|tausch|instandsetz/u', $text)) return 'Instandsetzung erforderlich';
    return $hasDefect ? 'geringfuegige Auffaelligkeit' : 'ohne festgestellten Handlungsbedarf';
}

function v2MergeManaged(array $existing, array $imported): array
{
    $previous = is_array($existing['import_values'] ?? null) ? $existing['import_values'] : [];
    foreach ($imported as $field => $value) {
        $currentExists = array_key_exists($field, $existing) && $existing[$field] !== '' && $existing[$field] !== null;
        $wasManaged = array_key_exists($field, $previous) && ($existing[$field] ?? null) === $previous[$field];
        if (!$currentExists || $wasManaged) $existing[$field] = $value;
    }
    $existing['import_values'] = $imported;
    $existing['import_source'] = 'sharepoint_recursive_v2';
    return $existing;
}

function v2WindowData(array $groupRows, array $building, string $roomNumber, string $windowNumber, string $floorLabel, string $inspectorName): array
{
    $first = $groupRows[0];
    $description = v2Description($groupRows);
    $hasDefect = v2HasDefect($description);
    $glassStructure = (string) ($first['__glass_structure'] ?? '');
    $glassDetails = v2GlassDetails($glassStructure);
    $glassWidth = (string) ($first['__glass_width'] ?? '');
    $glassHeight = (string) ($first['__glass_height'] ?? '');
    $frameWidth = (string) ($first['__frame_width'] ?? '');
    $frameHeight = (string) ($first['__frame_height'] ?? '');
    $glassWidthN = is_numeric(v2NormalizeNumber($glassWidth)) ? (float) v2NormalizeNumber($glassWidth) : 0.0;
    $glassHeightN = is_numeric(v2NormalizeNumber($glassHeight)) ? (float) v2NormalizeNumber($glassHeight) : 0.0;
    $thickness = (float) ($glassDetails['thickness'] ?? 0);
    $glassWeight = $glassWidthN > 0 && $glassHeightN > 0 && $thickness > 0
        ? round(($glassWidthN / 1000) * ($glassHeightN / 1000) * $thickness * 2.5, 1) : 0.0;
    $frameWeight = $glassWeight > 0 ? round($glassWeight * 0.18, 1) : 0.0;
    $totalWeight = $glassWeight > 0 ? round($glassWeight + $frameWeight, 1) : 0.0;
    $testWeight = $totalWeight > 0 ? round($totalWeight * 1.1, 1) : 0.0;
    $rating = v2Rating($description, $hasDefect);
    $imputed = [];
    foreach ($groupRows as $row) foreach (($row['__imputed_fields'] ?? []) as $field => $meta) $imputed[$field] = $meta;

    $data = [
        'inspection_number' => is_numeric($windowNumber) ? (int) $windowNumber : 0,
        'window_number' => $windowNumber,
        'object_label' => (string) ($first['__object_label'] ?? ''),
        'building_label' => (string) $building['name'],
        'section_label' => (string) (($first['__section_label'] ?? '') ?: $building['name']),
        'floor_label' => $floorLabel,
        'room_label' => 'Raum ' . $roomNumber,
        'room_number' => $roomNumber,
        'position_in_room' => (string) ($first['__position'] ?? ''),
        'orientation' => (string) ($first['__orientation'] ?? ''),
        'wing_count' => max(1, count($groupRows)),
        'inspected_wing' => 'Haupt/Neben nach Pruefreihenfolge',
        'inspector_name' => $inspectorName,
        'inspection_date' => date('Y-m-d'),
        'accessibility_status' => preg_match('/nicht\s+zugaeng|nicht\s+zugäng|gesperrt|kein\s+zugang/u', mb_strtolower($description, 'UTF-8')) ? 'nicht zugaenglich' : 'zugaenglich',
        'manufacturer' => (string) ($first['__manufacturer'] ?? ''),
        'window_system' => (string) ($first['__hardware_system'] ?? ''),
        'construction_year' => v2NormalizeNumber((string) ($first['__construction_year'] ?? '')),
        'frame_material' => (string) ($first['__frame_material'] ?? ''),
        'opening_type' => count($groupRows) > 1 ? 'Dreh-Kipp + Kipp' : ((string) ($first['__opening_type'] ?? '') ?: 'sonstige'),
        'wing_width_mm' => v2NormalizeNumber($frameWidth),
        'wing_height_mm' => v2NormalizeNumber($frameHeight),
        'hinge_system' => (string) ($first['__hardware_system'] ?? ''),
        'scissor_system' => (string) ($first['__hardware_system'] ?? ''),
        'glass_structure' => $glassStructure,
        'glass_panes' => $glassDetails['panes'] ?: '',
        'glass_thickness_mm' => $glassDetails['thickness'] ?: '',
        'glass_cavity_mm' => $glassDetails['cavities'],
        'glazing_width_mm' => v2NormalizeNumber($glassWidth),
        'glazing_height_mm' => v2NormalizeNumber($glassHeight),
        'glass_weight_kg' => $glassWeight ?: '',
        'estimated_frame_weight_kg' => $frameWeight ?: '',
        'total_wing_weight_kg' => $totalWeight ?: '',
        'applied_test_weight_kg' => $testWeight ?: '',
        'weight_method' => $glassWeight > 0 ? 'Berechnung aus konsolidierten Excel-Massen und Glasaufbau' : 'Projektvergleich / Datenuebernahme',
        'visible_special_features' => $description !== '' ? $description : 'keine Besonderheiten dokumentiert',
        'expert_note' => $description !== '' ? $description : 'keine zusaetzliche Feststellung',
        'recommended_action' => $description !== '' ? $description : 'Kein Handlungsbedarf aus den konsolidierten Unterlagen abgeleitet.',
        'opening_possible' => !preg_match('/oeffnen\s+nicht|öffnen\s+nicht|nicht\s+zu\s+oeffnen|nicht\s+zu\s+öffnen/u', mb_strtolower($description, 'UTF-8')),
        'closing_possible' => !preg_match('/schliessen\s+nicht|schließen\s+nicht|nicht\s+zu\s+schliessen|nicht\s+zu\s+schließen/u', mb_strtolower($description, 'UTF-8')),
        'tilt_possible' => !preg_match('/kipp.*nicht\s+moeglich|kipp.*nicht\s+möglich/u', mb_strtolower($description, 'UTF-8')),
        'wing_scrapes' => preg_match('/schleif/u', mb_strtolower($description, 'UTF-8')) === 1,
        'wing_hangs' => preg_match('/haeng|häng/u', mb_strtolower($description, 'UTF-8')) === 1,
        'hardware_heavy' => preg_match('/schwergaeng|schwergäng/u', mb_strtolower($description, 'UTF-8')) === 1,
        'readjustment_required' => preg_match('/einstell|nachstell|schleif/u', mb_strtolower($description, 'UTF-8')) === 1,
        'maintenance_or_repair_due' => $hasDefect,
        'urgent_action_required' => preg_match('/akut|sofort|gefahr|beschlag\s+defekt/u', mb_strtolower($description, 'UTF-8')) === 1,
        'overall_rating' => $rating,
        'priority' => $hasDefect ? 'mittel' : 'keine',
        'status' => 'fachlich geprueft',
        'schlagzahl' => $windowNumber,
        'room_reference' => $roomNumber,
        'import_rows' => $groupRows,
        'imputed_fields' => $imputed,
        'data_completeness' => 'vollstaendig nach Konsolidierung',
    ];

    foreach ($data as $key => &$value) {
        if ($value === '' || $value === null) {
            if (in_array($key, ['glass_weight_kg', 'estimated_frame_weight_kg', 'total_wing_weight_kg', 'applied_test_weight_kg'], true)) $value = 0;
            elseif (!in_array($key, ['object_label'], true)) $value = 'nicht separat angegeben';
        }
    }
    unset($value);

    return ['data' => $data, 'has_defect' => $hasDefect, 'rating' => $rating, 'calculated' => [
        'glassWeightKg' => $glassWeight,
        'frameWeightKg' => $frameWeight,
        'totalWingWeightKg' => $totalWeight,
        'appliedTestWeightKg' => $testWeight,
    ]];
}

function v2SashData(array $row, int $sashNumber, string $inspectorName, string $windowNumber): array
{
    $isPrimary = $sashNumber === 1;
    $description = trim((string) ($row['__description'] ?? ''));
    $hasDefect = v2HasDefect($description);
    $label = $isPrimary ? 'Hauptelement / Dreh-Kipp' : 'Nebenelement / Kipp';
    $data = [
        'status' => 'fachlich geprueft',
        'sash_label' => $label,
        'inspection_role' => $isPrimary ? 'Hauptpruefung' : 'Nebenpruefung',
        'inspection_sequence' => $sashNumber,
        'opening_type' => $isPrimary ? 'Dreh-Kipp' : 'Kipp',
        'position' => $isPrimary ? 'oben / Haupt' : 'unten / Neben',
        'qr_barcode' => $windowNumber,
        'inspection_date' => date('Y-m-d'),
        'inspector_name' => $inspectorName,
        'glass_structure' => (string) ($row['__glass_structure'] ?? 'nicht separat angegeben'),
        'glazing_width_mm' => v2NormalizeNumber((string) ($row['__glass_width'] ?? '')),
        'glazing_height_mm' => v2NormalizeNumber((string) ($row['__glass_height'] ?? '')),
        'frame_width_mm' => v2NormalizeNumber((string) ($row['__frame_width'] ?? '')),
        'frame_height_mm' => v2NormalizeNumber((string) ($row['__frame_height'] ?? '')),
        'hardware_system' => (string) ($row['__hardware_system'] ?? 'nicht separat angegeben'),
        'fn_bemerkung' => $description !== '' ? $description : 'keine zusaetzliche Feststellung',
        'massnahme_empfehlung' => $description !== '' ? $description : 'Kein Handlungsbedarf aus den konsolidierten Unterlagen abgeleitet.',
        'eignung_beurteilung' => $hasDefect ? 'instandsetzung_erforderlich' : 'geeignet',
        'overall_rating' => v2Rating($description, $hasDefect),
        'import_source' => 'sharepoint_recursive_v2',
        'source_file' => (string) ($row['__source_file'] ?? ''),
        'imputed_fields' => $row['__imputed_fields'] ?? [],
    ];
    foreach ($data as &$value) if ($value === '' || $value === null) $value = 'nicht separat angegeben';
    unset($value);
    return ['data' => $data, 'has_defect' => $hasDefect];
}

function v2Progress(array $data): int
{
    $required = [
        'inspection_number', 'window_number', 'building_label', 'section_label', 'floor_label', 'room_number',
        'wing_count', 'inspector_name', 'inspection_date', 'accessibility_status', 'glass_structure',
        'glazing_width_mm', 'glazing_height_mm', 'applied_test_weight_kg', 'weight_method', 'overall_rating',
        'recommended_action', 'priority', 'status',
    ];
    $filled = 0;
    foreach ($required as $field) {
        $value = $data[$field] ?? null;
        if ($value !== null && $value !== '' && $value !== false) $filled++;
    }
    return (int) round($filled / count($required) * 100);
}

function v2DedupeGroupRows(array $rows): array
{
    $merged = [];
    $order = [];
    foreach ($rows as $index => $row) {
        $opening = v2NormalizeKey((string) ($row['__opening_type'] ?? v2DetectOpeningType($row)));
        $position = v2NormalizeKey((string) ($row['__position'] ?? ''));
        $key = $opening !== '' ? 'opening:' . $opening : ($position !== '' ? 'position:' . $position : 'row:' . $index);
        if (!isset($merged[$key])) {
            $row['__merge_conflicts'] = is_array($row['__merge_conflicts'] ?? null) ? $row['__merge_conflicts'] : [];
            $merged[$key] = $row;
            $order[] = $key;
            continue;
        }
        foreach ($row as $field => $value) {
            if ($field === '__merge_conflicts') continue;
            $incoming = trim((string) $value);
            if ($incoming === '') continue;
            $current = trim((string) ($merged[$key][$field] ?? ''));
            if ($current === '') {
                $merged[$key][$field] = $value;
                continue;
            }
            if ($current !== $incoming && !str_starts_with((string) $field, '__')) {
                $merged[$key]['__merge_conflicts'][$field] = array_values(array_unique(array_merge(
                    (array) ($merged[$key]['__merge_conflicts'][$field] ?? []),
                    [$current, $incoming]
                )));
            }
        }
    }
    return array_values(array_map(static fn($key) => $merged[$key], $order));
}

function handleApplyExcelV2(array $user): never
{
    $body = requestBody();
    $buildingId = (int) ($body['building_id'] ?? 0);
    $rows = is_array($body['rows'] ?? null) ? $body['rows'] : [];
    if ($buildingId <= 0 || $rows === []) apiError(400, 'building_id und Excel-Zeilen sind erforderlich.');

    $canonicalRows = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $canonicalRows[] = isset($row['__window_number']) ? $row : v2CanonicalizeRow($row);
    }
    $canonicalRows = v2ImputeMissingValues($canonicalRows);
    v2SortRows($canonicalRows);

    $groups = [];
    foreach ($canonicalRows as $row) {
        $windowNumber = v2NormalizeWindowNumber($row['__window_number'] ?? ($row['schlagzahl'] ?? ''));
        $room = trim((string) ($row['__room_reference'] ?? ''));
        $floor = trim((string) ($row['__floor_label'] ?? '')) ?: 'EG / Erdgeschoss';
        if ($windowNumber === '' || $room === '') continue;
        $key = v2NormalizeKey($floor) . '|' . v2NormalizeKey($room) . '|' . v2NormalizeKey($windowNumber);
        if (!isset($groups[$key])) $groups[$key] = ['floor' => $floor, 'room' => $room, 'window' => $windowNumber, 'rows' => []];
        $groups[$key]['rows'][] = $row;
    }
    foreach ($groups as &$group) {
        $group['rows'] = v2DedupeGroupRows($group['rows']);
    }
    unset($group);
    if ($groups === []) apiError(422, 'Keine verwertbaren Fenster mit Raum- und Pruefnummer gefunden.');

    $added = 0;
    $updated = 0;
    $targets = [];

    try {
        $pdo = db();
        $pdo->beginTransaction();
        $buildingStmt = $pdo->prepare('SELECT id, name, project_id FROM buildings WHERE id = :bid LIMIT 1');
        $buildingStmt->execute([':bid' => $buildingId]);
        $building = $buildingStmt->fetch(PDO::FETCH_ASSOC);
        if (!$building) throw new RuntimeException('Gebaeude nicht gefunden.');

        $inspectorName = trim((string) ($user['full_name'] ?? ''));
        if ($inspectorName === '') $inspectorName = trim((string) ($user['email'] ?? ''));
        if ($inspectorName === '') $inspectorName = v2GraphConfig('SHAREPOINT_DEFAULT_INSPECTOR', 'Sachverstaendiger');

        foreach ($groups as $group) {
            $groupRows = $group['rows'];
            usort($groupRows, static fn($a, $b) => v2OpeningPriority($a) <=> v2OpeningPriority($b));
            $roomNumber = (string) $group['room'];
            $windowNumber = (string) $group['window'];
            $floorLabel = trim((string) ($group['floor'] ?? ($groupRows[0]['__floor_label'] ?? ''))) ?: 'EG / Erdgeschoss';
            $roomId = v2EnsureRoom($pdo, $buildingId, $floorLabel, $roomNumber);

            $windowPayload = v2WindowData($groupRows, $building, $roomNumber, $windowNumber, $floorLabel, $inspectorName);
            $imported = $windowPayload['data'];
            $progress = v2Progress($imported);
            $existingStmt = $pdo->prepare('SELECT id, form_data FROM windows WHERE room_id = :rid AND window_number = :wn AND deleted_at IS NULL LIMIT 1');
            $existingStmt->execute([':rid' => $roomId, ':wn' => $windowNumber]);
            $existing = $existingStmt->fetch(PDO::FETCH_ASSOC) ?: null;
            $existingData = $existing ? (json_decode((string) ($existing['form_data'] ?? ''), true) ?: []) : [];
            $formData = v2MergeManaged($existingData, $imported);
            $calculated = $windowPayload['calculated'];

            if ($existing) {
                $windowId = (int) $existing['id'];
                $stmt = $pdo->prepare(
                    'UPDATE windows SET inspection_number = :inspection_number, window_number = :wn, object_label = :object_label,
                     building_label = :building_label, section_label = :section_label, floor_label = :floor_label,
                     room_label = :room_label, room_number = :room_number, overall_rating = :rating, priority = :priority,
                     assigned_to = :assigned_to, assigned_name = :assigned_name, has_defect = :has_defect,
                     status = :status, accessibility_status = :accessibility_status, urgent_action_required = :urgent,
                     progress_percent = :progress, form_data = :fd, calculated_data = :cd, updated_at = :now WHERE id = :id'
                );
                $stmt->execute([
                    ':inspection_number' => is_numeric($windowNumber) ? (int) $windowNumber : 0,
                    ':wn' => $windowNumber,
                    ':object_label' => $imported['object_label'] === 'nicht separat angegeben' ? null : $imported['object_label'],
                    ':building_label' => $imported['building_label'], ':section_label' => $imported['section_label'],
                    ':floor_label' => $imported['floor_label'], ':room_label' => $imported['room_label'], ':room_number' => $roomNumber,
                    ':rating' => $imported['overall_rating'], ':priority' => $imported['priority'], ':assigned_to' => (int) $user['id'],
                    ':assigned_name' => $inspectorName, ':has_defect' => $windowPayload['has_defect'] ? 1 : 0,
                    ':status' => 'fachlich geprueft', ':accessibility_status' => $imported['accessibility_status'],
                    ':urgent' => !empty($imported['urgent_action_required']) ? 1 : 0, ':progress' => $progress,
                    ':fd' => json_encode($formData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ':cd' => json_encode($calculated, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ':now' => nowUtc(), ':id' => $windowId,
                ]);
                $updated++;
            } else {
                $recordId = 'SP2-' . $buildingId . '-' . preg_replace('/[^A-Za-z0-9_-]/', '-', $roomNumber) . '-' . preg_replace('/[^A-Za-z0-9_-]/', '-', $windowNumber) . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
                $stmt = $pdo->prepare(
                    'INSERT INTO windows (project_id, room_id, record_id, inspection_number, window_number, object_label, room_label, room_number,
                     building_label, section_label, floor_label, status, overall_rating, priority, assigned_to, assigned_name,
                     has_defect, accessibility_status, urgent_action_required, progress_percent, form_data, calculated_data, created_at, updated_at)
                     VALUES (:pid, :rid, :record_id, :inspection_number, :wn, :object_label, :room_label, :room_number,
                     :building_label, :section_label, :floor_label, :status, :rating, :priority, :assigned_to, :assigned_name,
                     :has_defect, :accessibility_status, :urgent, :progress, :fd, :cd, :now, :now)'
                );
                $stmt->execute([
                    ':pid' => (int) $building['project_id'], ':rid' => $roomId, ':record_id' => $recordId,
                    ':inspection_number' => is_numeric($windowNumber) ? (int) $windowNumber : 0, ':wn' => $windowNumber,
                    ':object_label' => $imported['object_label'] === 'nicht separat angegeben' ? null : $imported['object_label'],
                    ':room_label' => $imported['room_label'], ':room_number' => $roomNumber, ':building_label' => $imported['building_label'],
                    ':section_label' => $imported['section_label'], ':floor_label' => $imported['floor_label'], ':status' => 'fachlich geprueft',
                    ':rating' => $imported['overall_rating'], ':priority' => $imported['priority'], ':assigned_to' => (int) $user['id'],
                    ':assigned_name' => $inspectorName, ':has_defect' => $windowPayload['has_defect'] ? 1 : 0,
                    ':accessibility_status' => $imported['accessibility_status'], ':urgent' => !empty($imported['urgent_action_required']) ? 1 : 0,
                    ':progress' => $progress, ':fd' => json_encode($formData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ':cd' => json_encode($calculated, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ':now' => nowUtc(),
                ]);
                $windowId = (int) $pdo->lastInsertId();
                $added++;
            }

            $primaryRow = $groupRows[0];
            $secondaryRow = $groupRows[1] ?? $groupRows[0];
            foreach ([$primaryRow, $secondaryRow] as $idx => $row) {
                $sashNumber = $idx + 1;
                $sashPayload = v2SashData($row, $sashNumber, $inspectorName, $windowNumber);
                $sashData = $sashPayload['data'];
                $lookup = $pdo->prepare('SELECT id, form_data FROM window_sashes WHERE window_id = :wid AND sash_number = :sn AND deleted_at IS NULL LIMIT 1');
                $lookup->execute([':wid' => $windowId, ':sn' => $sashNumber]);
                $existingSash = $lookup->fetch(PDO::FETCH_ASSOC) ?: null;
                $existingSashData = $existingSash ? (json_decode((string) ($existingSash['form_data'] ?? ''), true) ?: []) : [];
                $mergedSashData = v2MergeManaged($existingSashData, $sashData);
                $label = $sashNumber === 1 ? 'Hauptelement / Dreh-Kipp' : 'Nebenelement / Kipp';
                $opening = $sashNumber === 1 ? 'Dreh-Kipp' : 'Kipp';
                $position = $sashNumber === 1 ? 'oben / Haupt' : 'unten / Neben';
                if ($existingSash) {
                    $sashId = (int) $existingSash['id'];
                    $pdo->prepare(
                        'UPDATE window_sashes SET sash_label = :label, opening_type = :opening, position = :position, status = :status,
                         form_data = :fd, progress_percent = 100, has_defect = :has_defect, overall_rating = :rating,
                         inspector_id = :uid, inspector_name = :uname, inspected_at = :inspected, updated_at = :now WHERE id = :id'
                    )->execute([
                        ':label' => $label, ':opening' => $opening, ':position' => $position, ':status' => 'fachlich geprueft',
                        ':fd' => json_encode($mergedSashData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ':has_defect' => $sashPayload['has_defect'] ? 1 : 0, ':rating' => $sashData['overall_rating'],
                        ':uid' => (int) $user['id'], ':uname' => $inspectorName, ':inspected' => nowUtc(), ':now' => nowUtc(), ':id' => $sashId,
                    ]);
                } else {
                    $pdo->prepare(
                        'INSERT INTO window_sashes (window_id, sash_number, sash_label, opening_type, position, status, form_data,
                         progress_percent, has_defect, overall_rating, inspector_id, inspector_name, inspected_at, created_at, updated_at)
                         VALUES (:wid, :sn, :label, :opening, :position, :status, :fd, 100, :has_defect, :rating, :uid, :uname, :inspected, :now, :now)'
                    )->execute([
                        ':wid' => $windowId, ':sn' => $sashNumber, ':label' => $label, ':opening' => $opening, ':position' => $position,
                        ':status' => 'fachlich geprueft', ':fd' => json_encode($mergedSashData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ':has_defect' => $sashPayload['has_defect'] ? 1 : 0, ':rating' => $sashData['overall_rating'],
                        ':uid' => (int) $user['id'], ':uname' => $inspectorName, ':inspected' => nowUtc(), ':now' => nowUtc(),
                    ]);
                    $sashId = (int) $pdo->lastInsertId();
                }
                $targets[] = [
                    'schlagzahl' => $windowNumber,
                    'room_reference' => $roomNumber,
                    'position' => $position,
                    'window_id' => $windowId,
                    'sash_id' => $sashId,
                ];
            }
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
        apiError(503, 'Konsolidierte Excel-Daten konnten nicht verarbeitet werden: ' . $e->getMessage());
    }

    apiJson([
        'added' => $added,
        'updated' => $updated,
        'skipped' => 0,
        'errors' => [],
        'skipped_rows' => [],
        'targets' => $targets,
        'complete' => true,
        'processing_order' => 'Raum -> Pruefnummer -> Haupt/Dreh-Kipp -> Neben/Kipp',
    ]);
}
