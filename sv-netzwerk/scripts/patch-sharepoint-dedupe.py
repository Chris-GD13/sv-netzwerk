from pathlib import Path

path = Path('public/intern/api/sharepoint-v2.php')
source = path.read_text(encoding='utf-8')

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
'''

if old_group in source:
    source = source.replace(old_group, new_group, 1)
elif "v2NormalizeKey($floor) . '|' . v2NormalizeKey($room) . '|' . v2NormalizeKey($windowNumber)" not in source:
    raise SystemExit('group block not found')

old_floor = "$floorLabel = trim((string) ($groupRows[0]['__floor_label'] ?? '')) ?: 'EG / Erdgeschoss';"
new_floor = "$floorLabel = trim((string) ($group['floor'] ?? ($groupRows[0]['__floor_label'] ?? ''))) ?: 'EG / Erdgeschoss';"
if old_floor in source:
    source = source.replace(old_floor, new_floor, 1)

path.write_text(source, encoding='utf-8')
