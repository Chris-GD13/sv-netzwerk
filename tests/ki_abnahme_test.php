<?php
/**
 * KI-Abnahmetest: Vergleicht erkannte Daten mit Soll-Referenz
 * 
 * Ausführung:
 *   php tests/ki_abnahme_test.php <JSON_ERGEBNIS_DATEI>
 * 
 * Die JSON-Datei wird durch die KI-Analyse erzeugt und enthält
 * die erkannten Gebäude, Räume, Fenster und Konflikte.
 * 
 * Dieses Script vergleicht die Ergebnisse mit den Soll-Werten
 * und erzeugt einen Bewertungsbericht.
 */

// Soll-Daten (aus SOLL_REFERENZ.md)
$SOLL = [
    'gebaeude' => [
        ['kuerzel' => 'VGN', 'name_contains' => 'Verwaltung', 'baujahr' => 2005],
        ['kuerzel' => 'VGS', 'name_contains' => 'Verwaltung', 'baujahr' => 1998],
        ['kuerzel' => 'KON', 'name_contains' => 'Konferenz', 'baujahr' => 2018],
        ['kuerzel' => 'TZ',  'name_contains' => 'Technisch', 'baujahr' => 1985],
        ['kuerzel' => 'NGW', 'name_contains' => 'Nebengebäude', 'baujahr' => 1990],
        ['kuerzel' => 'PW',  'name_contains' => 'Pforte', 'baujahr' => 2010],
        ['kuerzel' => 'KAS', 'name_contains' => 'Kantine', 'baujahr' => 2001],
    ],
    'konflikte' => [
        'K1' => ['beschreibung' => 'VGN 1.OG Hersteller: Roto vs Siegenia', 'pflicht' => true],
        'K2' => ['beschreibung' => 'TZ-F-0106: GU vs Maco', 'pflicht' => true],
        'K3' => ['beschreibung' => 'VGN-109: WC vs Teeküche', 'pflicht' => true],
        'K4' => ['beschreibung' => 'VGN-F-0004: Maße 1000x1400 vs 900x1200', 'pflicht' => false],
        'K5' => ['beschreibung' => 'PW Summe: 13+5=18 falsch', 'pflicht' => false],
        'K6' => ['beschreibung' => 'KAS fehlend in V1', 'pflicht' => true],
        'K7' => ['beschreibung' => 'Gesamtzahl: 34 vs 793', 'pflicht' => false],
        'K8' => ['beschreibung' => 'Entwurf VGN: Roto NX 2010 vs Siegenia 2012', 'pflicht' => true],
    ],
    'fenster_minimum' => 50, // aus den Listen erkennbar
    'raeume_minimum' => 35,
];

// Gewichtung
$GEWICHTE = [
    'gebaeude'      => 0.10,
    'etagen'        => 0.10,
    'raeume'        => 0.15,
    'fenster'       => 0.20,
    'konflikte'     => 0.20,
    'ocr'           => 0.10,
    'mehrsprachig'  => 0.05,
    'plausibilitaet'=> 0.05,
    'rueckfragen'   => 0.05,
];

// Lese KI-Ergebnis
$inputFile = $argv[1] ?? null;
if (!$inputFile || !file_exists($inputFile)) {
    echo "Verwendung: php ki_abnahme_test.php <ergebnis.json>\n\n";
    echo "Die JSON-Datei muss folgende Struktur haben:\n";
    echo json_encode([
        'gebaeude' => [['kuerzel' => 'VGN', 'name' => '...', 'baujahr' => 2005]],
        'etagen' => [['gebaeude' => 'VGN', 'name' => 'Erdgeschoss', 'geschoss' => 0]],
        'raeume' => [['raumnummer' => 'VGN-101', 'name' => 'Empfang', 'gebaeude' => 'VGN']],
        'fenster' => [['nummer' => 'VGN-F-0001', 'typ' => 'Dreh-Kipp', 'hersteller' => '...']],
        'konflikte' => [['beschreibung' => '...', 'dokument1' => '...', 'dokument2' => '...']],
        'rueckfragen' => ['Welcher Hersteller ist korrekt für VGN 1.OG?'],
        'ocr_probleme' => ['KAS Grundriss schwer lesbar'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    exit(2);
}

$ergebnis = json_decode(file_get_contents($inputFile), true);
if (!$ergebnis) {
    echo "FEHLER: Ungültige JSON-Datei!\n";
    exit(2);
}

// Bewertung
$scores = [];
$details = [];

echo "╔══════════════════════════════════════════════════════════════╗\n";
echo "║           KI-ABNAHMETEST - ERGEBNISBERICHT                   ║\n";
echo "╠══════════════════════════════════════════════════════════════╣\n\n";

// 1. Gebäude
$erkannt = 0;
foreach ($SOLL['gebaeude'] as $geb) {
    $found = false;
    foreach ($ergebnis['gebaeude'] ?? [] as $eg) {
        if (($eg['kuerzel'] ?? '') === $geb['kuerzel'] ||
            stripos($eg['name'] ?? '', $geb['name_contains']) !== false) {
            $found = true;
            break;
        }
    }
    if ($found) $erkannt++;
    $details['gebaeude'][] = ($found ? '✓' : '✗') . " {$geb['kuerzel']} ({$geb['name_contains']})";
}
$scores['gebaeude'] = $erkannt / count($SOLL['gebaeude']);
echo "1. GEBÄUDE: $erkannt / " . count($SOLL['gebaeude']) . " erkannt (" . round($scores['gebaeude']*100) . "%)\n";
foreach ($details['gebaeude'] as $d) echo "   $d\n";
echo "\n";

// 2. Fenster
$fensterCount = count($ergebnis['fenster'] ?? []);
$scores['fenster'] = min(1.0, $fensterCount / $SOLL['fenster_minimum']);
echo "2. FENSTER: $fensterCount erkannt (Minimum: {$SOLL['fenster_minimum']})\n";
echo "   Score: " . round($scores['fenster']*100) . "%\n\n";

// 3. Räume
$raeumeCount = count($ergebnis['raeume'] ?? []);
$scores['raeume'] = min(1.0, $raeumeCount / $SOLL['raeume_minimum']);
echo "3. RÄUME: $raeumeCount erkannt (Minimum: {$SOLL['raeume_minimum']})\n";
echo "   Score: " . round($scores['raeume']*100) . "%\n\n";

// 4. Konflikte
$konfliktErkannt = 0;
$pflichtErkannt = 0;
$pflichtGesamt = 0;
foreach ($SOLL['konflikte'] as $kid => $konf) {
    if ($konf['pflicht']) $pflichtGesamt++;
    $found = false;
    foreach ($ergebnis['konflikte'] ?? [] as $ek) {
        // Fuzzy match on description
        $desc = strtolower($ek['beschreibung'] ?? '');
        $soll = strtolower($konf['beschreibung']);
        if (similar_text($desc, $soll) > strlen($soll) * 0.4) {
            $found = true;
            break;
        }
    }
    if ($found) {
        $konfliktErkannt++;
        if ($konf['pflicht']) $pflichtErkannt++;
    }
    $details['konflikte'][] = ($found ? '✓' : '✗') . " $kid: {$konf['beschreibung']}" . ($konf['pflicht'] ? ' [PFLICHT]' : '');
}
$scores['konflikte'] = $konfliktErkannt / count($SOLL['konflikte']);
echo "4. KONFLIKTE: $konfliktErkannt / " . count($SOLL['konflikte']) . " erkannt";
echo " (davon Pflicht: $pflichtErkannt/$pflichtGesamt)\n";
foreach ($details['konflikte'] as $d) echo "   $d\n";
echo "\n";

// 5. Rückfragen
$rueckfragen = count($ergebnis['rueckfragen'] ?? []);
$scores['rueckfragen'] = min(1.0, $rueckfragen / 3); // 3 von 6 minimum
echo "5. RÜCKFRAGEN: $rueckfragen gestellt (Minimum: 3)\n";
echo "   Score: " . round($scores['rueckfragen']*100) . "%\n\n";

// 6. OCR (check if garbled docs produced usable output)
$ocrProbleme = count($ergebnis['ocr_probleme'] ?? []);
$scores['ocr'] = $ocrProbleme > 0 ? min(1.0, 0.5 + $ocrProbleme * 0.25) : 0.3;
echo "6. OCR-ERKENNUNG: $ocrProbleme Probleme dokumentiert\n\n";

// 7. Mehrsprachigkeit (check if English email was processed)
$mehrsprachig = false;
foreach ($ergebnis['fenster'] ?? [] as $f) {
    if (stripos($f['hersteller'] ?? '', 'Roto') !== false && 
        stripos($f['bemerkung'] ?? '', 'spare') !== false) {
        $mehrsprachig = true;
    }
}
// Also check if any item references English source
foreach ($ergebnis['konflikte'] ?? [] as $k) {
    if (stripos($k['dokument1'] ?? $k['beschreibung'] ?? '', 'english') !== false ||
        stripos($k['dokument1'] ?? $k['beschreibung'] ?? '', 'EN') !== false) {
        $mehrsprachig = true;
    }
}
$scores['mehrsprachig'] = $mehrsprachig ? 1.0 : 0.0;
echo "7. MEHRSPRACHIGKEIT: " . ($mehrsprachig ? '✓ Erkannt' : '✗ Nicht erkannt') . "\n\n";

// Gesamt
$scores['etagen'] = min(1.0, count($ergebnis['etagen'] ?? []) / 20);
$scores['plausibilitaet'] = $scores['konflikte']; // approximation

$gesamt = 0;
foreach ($GEWICHTE as $key => $weight) {
    $s = $scores[$key] ?? 0.5;
    $gesamt += $s * $weight;
}

echo "╠══════════════════════════════════════════════════════════════╣\n";
echo "║  GESAMTBEWERTUNG                                             ║\n";
echo "╠══════════════════════════════════════════════════════════════╣\n\n";

foreach ($GEWICHTE as $key => $weight) {
    $s = $scores[$key] ?? 0;
    $pct = round($s * 100);
    $weighted = round($s * $weight * 100, 1);
    printf("  %-15s %3d%% × %2d%% = %5.1f%%  %s\n", 
        $key, $pct, $weight*100, $weighted,
        $pct >= 70 ? '✓' : ($pct >= 50 ? '⚠' : '✗'));
}

echo "\n  ─────────────────────────────────────────\n";
$gesamtPct = round($gesamt * 100, 1);
echo "  GESAMT-SCORE:  $gesamtPct%\n\n";

if ($gesamtPct >= 70) {
    echo "  ╔═══════════════════════╗\n";
    echo "  ║  ERGEBNIS: BESTANDEN  ║\n";
    echo "  ╚═══════════════════════╝\n";
    $exitCode = 0;
} else {
    echo "  ╔═══════════════════════════════╗\n";
    echo "  ║  ERGEBNIS: NICHT BESTANDEN    ║\n";
    echo "  ║  (Minimum: 70%)               ║\n";
    echo "  ╚═══════════════════════════════╝\n";
    $exitCode = 1;
}

echo "\n╚══════════════════════════════════════════════════════════════╝\n";
exit($exitCode);
