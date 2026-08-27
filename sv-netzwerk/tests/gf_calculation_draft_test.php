<?php
declare(strict_types=1);

require_once __DIR__ . '/../public/intern/api/gf-calculation-draft.php';

$state = gfCalculationDraftState([
    'summary' => 'Vorläufige Wiederherstellungskalkulation.',
    'items' => [[
        'position_code' => 'BKI 123',
        'description' => 'Beschädigten Wandputz erneuern',
        'quantity' => '12,50',
        'unit' => 'm²',
        'unit_price' => '45,80 EUR',
        'regional_factor' => '1,05',
        'source_name' => 'BKI Altbau 2026',
        'source_page' => '123',
    ]],
    'vat' => 19,
    'assumptions' => ['Schadenfläche vor Ort prüfen.'],
    'open_points' => ['Sockelleiste noch aufmessen.'],
], [
    'schaden_plz' => '72661',
    'schaden_ort' => 'Grafenberg',
], 'Nur den unmittelbar betroffenen Bereich ansetzen.');

$line = $state['lines'][0] ?? [];
if (($line['quantity'] ?? null) !== 12.5 || ($line['unit_price'] ?? null) !== 45.8 || ($line['regional_factor'] ?? null) !== 1.05) {
    fwrite(STDERR, "Deutsche Dezimalwerte wurden nicht korrekt normalisiert.\n");
    exit(1);
}
if (($state['location'] ?? '') !== '72661 Grafenberg') {
    fwrite(STDERR, "Schadenort wurde nicht übernommen.\n");
    exit(1);
}
foreach (['KI-Erstentwurf', 'Annahmen:', 'Offene Punkte:', 'Berücksichtigte Vorgabe:'] as $needle) {
    if (!str_contains((string)($state['note'] ?? ''), $needle)) {
        fwrite(STDERR, "Pflichtvermerk fehlt: {$needle}\n");
        exit(1);
    }
}

$rejected = false;
try {
    gfCalculationDraftState(['items' => [[
        'description' => 'Unbelegte Position',
        'quantity' => 1,
        'unit' => 'St',
        'unit_price' => 100,
    ]]], [], '');
} catch (RuntimeException $error) {
    $rejected = str_contains($error->getMessage(), 'Quelle fehlt');
}
if (!$rejected) {
    fwrite(STDERR, "Unbelegte Kalkulationsposition wurde nicht gesperrt.\n");
    exit(1);
}

$link = gfCalculationDraftLink('ai:Fall 1');
if ($link !== '/intern/kalkulation/?draft_key=ai%3AFall%201') {
    fwrite(STDERR, "Entwurfslink ist nicht URL-sicher.\n");
    exit(1);
}

echo "Kalkulationsentwurf: Werte, Quellen, Hinweise und Bearbeitungslink geprüft.\n";
