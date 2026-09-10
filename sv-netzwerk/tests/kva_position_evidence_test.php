<?php
declare(strict_types=1);

$source = file_get_contents(__DIR__.'/../public/intern/api/bki-calculator.php');
if ($source === false) exit(1);

function loadFunction(string $source, string $name): void
{
    $start = strpos($source, 'function '.$name.'(');
    if ($start === false) throw new RuntimeException("Function $name not found");
    $brace = strpos($source, '{', $start);
    $depth = 0;
    for ($end = $brace, $length = strlen($source); $end < $length; $end++) {
        if ($source[$end] === '{') $depth++;
        if ($source[$end] === '}' && --$depth === 0) break;
    }
    eval(substr($source, $start, $end - $start + 1));
}

foreach (['bkKvaNorm', 'bkKvaEvidenceHasNumber', 'bkKvaEvidenceCoversText', 'bkKvaEvidenceHasUnit', 'bkFinalizeKva', 'bkKvaJobWorkspace', 'bkKvaJobCleanup'] as $function) loadFunction($source, $function);

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

// Einheiten aus echten KVA-Belegzeilen enthalten die Unicode-Hochzahlen. Werden sie nicht erkannt,
// verliert die Auslesung korrekte Positionen und bricht anschließend an der Summenprüfung ab.
check(bkKvaEvidenceHasUnit('Pos. 15 12,00 m² 850,00 EUR 10.200,00 EUR', 'm²'), 'm² muss als Einheit im Beleg erkannt werden');
check(bkKvaEvidenceHasUnit('Pos. 4 3,00 m³ 120,00 EUR 360,00 EUR', 'm³'), 'm³ muss als Einheit im Beleg erkannt werden');
check(bkKvaEvidenceHasUnit('Pos. 2 10,00 qm 40,00 EUR 400,00 EUR', 'm²'), 'qm muss weiterhin als m²-Schreibweise gelten');
check(bkKvaEvidenceHasUnit('Pos. 3 1,00 psch 900,00 EUR 900,00 EUR', 'psch'), 'psch muss als Einheit erkannt werden');
check(!bkKvaEvidenceHasUnit('Pos. 7 5,00 St 20,00 EUR 100,00 EUR', 'm²'), 'Eine im Beleg nicht vorhandene Einheit darf nicht bestätigt werden');

$rows = [];
for ($i = 1; $i <= 3; $i++) {
    $unitPrice = 50.0 + $i;
    $rows[] = [
        'source_position' => (string)$i,
        'page_number' => 1,
        'evidence' => "Pos. $i Malerarbeiten Anstrich Wand weiss 1,00 Stk $unitPrice EUR $unitPrice EUR",
        'description' => 'Malerarbeiten Anstrich Wand weiss',
        'quantity' => 1.0,
        'unit' => 'Stk',
        'offered_unit_price' => $unitPrice,
        'offered_total' => $unitPrice,
    ];
}
// Lange Leistungstexte werden im knappen Tabellenbeleg nicht wortgetreu wiederholt. Solange
// Positionsnummer, Menge, Einheitspreis und Gesamtpreis exakt und rechnerisch stimmig belegt sind,
// muss die Position erhalten bleiben.
$rows[] = [
    'source_position' => '4',
    'page_number' => 2,
    'evidence' => 'Pos. 4 12,00 m² 850,00 EUR 10200,00 EUR',
    'description' => 'Lieferung, Anpassung und fachgerechte Montage einer hochwertigen Einbauküche inklusive Elektrogeräten, Arbeitsplatte aus Naturstein sowie sämtlicher Anschlussarbeiten gemäß Aufmaß vor Ort',
    'quantity' => 12.0,
    'unit' => 'm²',
    'offered_unit_price' => 850.0,
    'offered_total' => 10200.0,
];

$net = array_sum(array_map(static fn(array $row): float => (float)$row['offered_total'], $rows));
$result = bkFinalizeKva('KVA-Pruefung.pdf', ['quote_number' => '', 'company' => 'Test GmbH', 'net_total' => $net, 'positions' => $rows]);
check(count($result['positions']) === 4, 'Alle belegten Positionen müssen erhalten bleiben, auch bei m² und langem Leistungstext');
check(abs(array_sum(array_map(static fn(array $row): float => (float)$row['offered_total'], $result['positions'])) - $net) < 0.01, 'Die übernommenen Positionen müssen die Nettosumme ergeben');

// Eine frei erfundene Position ohne passenden Beleg muss weiterhin verworfen werden und die
// Auswertung insgesamt mit verständlicher Meldung stoppen.
$fabricated = $rows;
$fabricated[] = [
    'source_position' => '99',
    'page_number' => 5,
    'evidence' => 'nicht vorhanden',
    'description' => 'Frei erfundene Position ohne Beleg',
    'quantity' => 3.0,
    'unit' => 'Stk',
    'offered_unit_price' => 999.0,
    'offered_total' => 2997.0,
];
$rejected = false;
try {
    bkFinalizeKva('KVA-Pruefung.pdf', ['quote_number' => '', 'company' => 'Test GmbH', 'net_total' => $net + 2997.0, 'positions' => $fabricated]);
} catch (RuntimeException $e) {
    $rejected = str_contains($e->getMessage(), 'stimmen nicht mit der sichtbaren Netto-Angebotssumme');
}
check($rejected, 'Nicht belegte Positionen müssen weiterhin zum Abbruch mit Summenmeldung führen');

// Falsche Angebotsnummer bleibt ein harter Abbruchgrund.
$wrongNumber = false;
try {
    bkFinalizeKva('S-1_Angebot AN2629552.pdf', ['quote_number' => 'AN1111111', 'company' => 'Test GmbH', 'net_total' => $net, 'positions' => $rows]);
} catch (RuntimeException $e) {
    $wrongNumber = str_contains($e->getMessage(), 'Angebotsnummer');
}
check($wrongNumber, 'Eine abweichende Angebotsnummer muss die Auswertung verwerfen');

// Zwischengespeicherte Seitenbilder des Hintergrundauftrags dürfen nicht liegen bleiben.
$workspace = bkKvaJobWorkspace(987654);
@mkdir($workspace, 0700, true);
file_put_contents($workspace.DIRECTORY_SEPARATOR.'seite-0.bin', 'test');
check(is_file($workspace.DIRECTORY_SEPARATOR.'seite-0.bin'), 'Testdatei im Auftragsordner muss angelegt sein');
bkKvaJobCleanup(987654);
check(!is_dir($workspace), 'Der Auftragsordner muss nach der Verarbeitung entfernt werden');

echo "KVA-Belegprüfung und Hintergrundauftrag abgesichert.\n";
