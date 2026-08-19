from pathlib import Path

path = Path('public/intern/api/sharepoint-v2.php')
source = path.read_text(encoding='utf-8')

floor_helper = '''function v2FloorFromRoomReference(string $room): string
{
    $digits = preg_replace('/\\D+/', '', trim($room)) ?? '';
    if ($digits === '') return '';
    $first = $digits[0] ?? '';
    return match ($first) {
        '0' => 'EG',
        '1' => '1. OG',
        '2' => '2. OG',
        '3' => '3. OG',
        default => '',
    };
}

function v2ResolveFloorLabel(array $row): string
{
    $room = trim((string) ($row['__room_reference'] ?? v2RowValue($row, ['Zimmer', 'Zimmernummer', 'Zimmer Nr', 'Zimmer-Nr', 'Raum', 'Raumnummer', 'room_number'])));
    $roomFloor = v2FloorFromRoomReference($room);
    $sheetFloor = trim((string) ($row['__floor_label'] ?? v2RowValue($row, ['Etage', 'Geschoss'])));
    if ($roomFloor !== '') return $roomFloor;
    return $sheetFloor;
}

'''

if 'function v2FloorFromRoomReference(string $room): string' not in source:
    marker = 'function v2CanonicalizeRow(array $row): array\n{'
    if marker not in source:
        raise SystemExit('v2CanonicalizeRow anchor not found')
    source = source.replace(marker, floor_helper + marker, 1)

old_canonical = '''function v2CanonicalizeRow(array $row): array
{
    $canonical = $row;
    foreach (v2CanonicalFieldMap() as $field => $needles) $canonical['__' . $field] = v2RowValue($row, $needles);
    $canonical['__window_number'] = v2NormalizeWindowNumber($canonical['__window_number']);
    $canonical['__opening_type'] = v2DetectOpeningType($row);
    return $canonical;
}
'''
new_canonical = '''function v2CanonicalizeRow(array $row): array
{
    $canonical = $row;
    foreach (v2CanonicalFieldMap() as $field => $needles) $canonical['__' . $field] = v2RowValue($row, $needles);
    $canonical['__window_number'] = v2NormalizeWindowNumber($canonical['__window_number']);
    $canonical['__opening_type'] = v2DetectOpeningType($row);
    $canonical['__floor_label_original'] = (string) ($canonical['__floor_label'] ?? '');
    $canonical['__floor_label'] = v2ResolveFloorLabel($canonical);
    return $canonical;
}
'''
if old_canonical in source:
    source = source.replace(old_canonical, new_canonical, 1)
elif "['__floor_label_original']" not in source:
    raise SystemExit('canonicalize block not found')

helper = '''function v2DedupeGroupRows(array $rows): array
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

'''

if 'function v2DedupeGroupRows(array $rows): array' not in source:
    marker = 'function handleApplyExcelV2(array $user): never\n{'
    if marker not in source:
        raise SystemExit('handleApplyExcelV2 anchor not found')
    source = source.replace(marker, helper + marker, 1)

old_group = '''    $groups = [];
    foreach ($canonicalRows as $row) {
        $windowNumber = v2NormalizeWindowNumber($row['__window_number'] ?? ($row['schlagzahl'] ?? ''));
        $room = trim((string) ($row['__room_reference'] ?? ''));
        if ($windowNumber === '' || $room === '') continue;
        $key = v2NormalizeKey($room) . '|' . v2NormalizeKey($windowNumber);
        if (!isset($groups[$key])) $groups[$key] = ['room' => $room, 'window' => $windowNumber, 'rows' => []];
        $groups[$key]['rows'][] = $row;
    }
'''

new_group = '''    $groups = [];
    foreach ($canonicalRows as $row) {
        $windowNumber = v2NormalizeWindowNumber($row['__window_number'] ?? ($row['schlagzahl'] ?? ''));
        $room = trim((string) ($row['__room_reference'] ?? ''));
        $floor = trim((string) ($row['__floor_label'] ?? '')) ?: v2FloorFromRoomReference($room);
        if ($floor === '') $floor = 'EG';
        if ($windowNumber === '' || $room === '') continue;
        $key = v2NormalizeKey($floor) . '|' . v2NormalizeKey($room) . '|' . v2NormalizeKey($windowNumber);
        if (!isset($groups[$key])) $groups[$key] = ['floor' => $floor, 'room' => $room, 'window' => $windowNumber, 'rows' => []];
        $groups[$key]['rows'][] = $row;
    }
    foreach ($groups as &$group) {
        $group['rows'] = v2DedupeGroupRows($group['rows']);
    }
    unset($group);
'''

if old_group in source:
    source = source.replace(old_group, new_group, 1)
elif "v2NormalizeKey($floor) . '|' . v2NormalizeKey($room) . '|' . v2NormalizeKey($windowNumber)" not in source:
    raise SystemExit('group block not found')

old_floor = "$floorLabel = trim((string) ($groupRows[0]['__floor_label'] ?? '')) ?: 'EG / Erdgeschoss';"
new_floor = "$floorLabel = trim((string) ($group['floor'] ?? ($groupRows[0]['__floor_label'] ?? ''))) ?: v2FloorFromRoomReference($roomNumber);\n        if ($floorLabel === '') $floorLabel = 'EG';"
if old_floor in source:
    source = source.replace(old_floor, new_floor, 1)

path.write_text(source, encoding='utf-8')
