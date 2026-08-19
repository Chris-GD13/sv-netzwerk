from pathlib import Path

path = Path('public/intern/api/sharepoint-v2.php')
source = path.read_text(encoding='utf-8')

old = '''    $canonicalRows = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $canonicalRows[] = isset($row['__window_number']) ? $row : v2CanonicalizeRow($row);
    }
'''

new = '''    $canonicalRows = [];
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        // Immer neu kanonisieren: importierte Zeilen koennen bereits interne __-Felder
        // aus einer frueheren Parserstufe enthalten, die unvollstaendig oder veraltet sind.
        $canonical = v2CanonicalizeRow($row);

        // Robuste Rueckfalle fuer die beiden fachlichen Schluesselfelder.
        // In den BMVg-Listen ist die erste fachliche Spalte die Zimmernummer und
        // "Fenster nr" / "Schlagzahl" die Fensternummer.
        if (trim((string) ($canonical['__room_reference'] ?? '')) === '') {
            foreach (['Zimmer', 'Zimmernummer', 'Zimmer Nr', 'Zimmer-Nr', 'Raum', 'Raumnummer', 'Raum Nr', 'room_number'] as $key) {
                if (isset($row[$key]) && trim((string) $row[$key]) !== '') {
                    $canonical['__room_reference'] = trim((string) $row[$key]);
                    break;
                }
            }
        }
        if (trim((string) ($canonical['__window_number'] ?? '')) === '') {
            foreach (['schlagzahl', 'Schlagzahl', 'Fenster nr', 'Fenster Nr', 'Fenster-Nr', 'Fenster-Nr.', 'Fensternummer', 'window_number'] as $key) {
                if (isset($row[$key]) && trim((string) $row[$key]) !== '') {
                    $canonical['__window_number'] = v2NormalizeWindowNumber($row[$key]);
                    break;
                }
            }
        }

        // Etage abschliessend aus Zimmernummer plausibilisieren (0xxx..3xxx).
        $canonical['__floor_label'] = v2ResolveFloorLabel($canonical);
        $canonicalRows[] = $canonical;
    }
'''

if old not in source:
    if 'Immer neu kanonisieren: importierte Zeilen koennen bereits interne __-Felder' in source:
        print('Patch already applied')
    else:
        raise SystemExit('canonicalRows block not found')
else:
    source = source.replace(old, new, 1)

old_error = "    if ($groups === []) apiError(422, 'Keine verwertbaren Fenster mit Raum- und Pruefnummer gefunden.');"
new_error = '''    if ($groups === []) {
        $sample = array_slice($canonicalRows, 0, 5);
        error_log('[sharepoint-v2] Keine Gruppen. Beispiele: ' . json_encode(array_map(static fn($r) => [
            'room' => $r['__room_reference'] ?? '',
            'window' => $r['__window_number'] ?? '',
            'floor' => $r['__floor_label'] ?? '',
            'source' => $r['__source_file'] ?? '',
            'sheet' => $r['__sheet_name'] ?? '',
        ], $sample), JSON_UNESCAPED_UNICODE));
        apiError(422, 'Keine verwertbaren Fenster gefunden. Erwartet werden Zimmernummer und Fensternummer/Schlagzahl.');
    }'''
if old_error in source:
    source = source.replace(old_error, new_error, 1)

path.write_text(source, encoding='utf-8')
