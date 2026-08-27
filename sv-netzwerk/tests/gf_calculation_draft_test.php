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
    $rejected = str_contains($error->getMessage(), 'quelle') || str_contains($error->getMessage(), 'Quelle');
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

$imagePart = gfCalculationInputPart(['file_id' => 'file-photo', 'mime' => 'image/jpeg']);
if (($imagePart['type'] ?? '') !== 'input_image' || ($imagePart['detail'] ?? '') !== 'high') {
    fwrite(STDERR, "Schadenfoto wird nicht als hochauflösendes Bild an die Auswertung übergeben.\n");
    exit(1);
}
$documentPart = gfCalculationInputPart(['file_id' => 'file-pdf', 'mime' => 'application/pdf']);
if (($documentPart['type'] ?? '') !== 'input_file') {
    fwrite(STDERR, "Dokument wird nicht als Datei an die Auswertung übergeben.\n");
    exit(1);
}
$aiCore = file_get_contents(__DIR__.'/../public/intern/api/gf-ai-generate-core.php');
foreach ([
    'function gfOpenAIUploadName',
    "'image/jpeg'=>'jpg'",
    "'image/png'=>'png'",
    "'image/gif'=>'gif'",
    "'image/webp'=>'webp'",
    "'upload_name'=>\$uploadName",
] as $needle) {
    if (!is_string($aiCore) || !str_contains($aiCore, $needle)) {
        fwrite(STDERR, "KI-Bildnormalisierung fehlt: {$needle}\n");
        exit(1);
    }
}
if (is_string($aiCore) && str_contains($aiCore, "'image/tiff'")) {
    fwrite(STDERR, "Nicht unterstütztes TIFF-Format wird weiterhin als KI-Bild zugelassen.\n");
    exit(1);
}

$evidence = gfCalculationEvidenceText([['files' => [[
    'name' => 'Schadenfoto_Küche.jpg',
    'document_type' => 'Schadenfoto',
    'facts' => ['Wandbekleidung sichtbar beschädigt.'],
    'visual_findings' => ['Abplatzung im Sockelbereich; kein Maßstab sichtbar.'],
    'measurements' => [],
    'amounts' => [],
    'open_points' => ['Fläche vor Ort aufmessen.'],
], [
    'name' => 'KVA.pdf',
    'document_type' => 'Kostenvoranschlag',
    'facts' => [str_repeat('Leistungsbeschreibung ', 2000)],
    'amounts' => [['description' => 'Wiederherstellung', 'net' => 1000]],
]]]], 12000);
if (!str_contains($evidence, 'Schadenfoto_Küche.jpg') || !str_contains($evidence, 'visual_findings')) {
    fwrite(STDERR, "Bildbefund fehlt im priorisierten Kalkulationsevidenzbestand.\n");
    exit(1);
}
if (mb_strlen($evidence, 'UTF-8') > 12000) {
    fwrite(STDERR, "Kalkulationsevidenz überschreitet das vorgegebene Kontextbudget.\n");
    exit(1);
}

$uiFiles = [
    __DIR__.'/../src/pages/intern/kalkulation/index.astro',
    __DIR__.'/../src/pages/intern/kalkulation/versicherungsschaeden.astro',
];
foreach ($uiFiles as $uiFile) {
    $ui = file_get_contents($uiFile);
    if (!is_string($ui)
        || !str_contains($ui, 'data-optical-settlement-template')
        || !str_contains($ui, 'data-auto-grow')
        || !str_contains($ui, 'ausdrücklich als abgegolten bezeichneten Schadenpositionen')) {
        fwrite(STDERR, "Optische Abgeltung oder mitwachsendes Hinweisfeld fehlt in {$uiFile}.\n");
        exit(1);
    }
    if (str_contains($ui, 'Schadenfall vollumfänglich abgegolten')) {
        fwrite(STDERR, "Zu weit gefasste Vollabgeltung ist weiterhin in {$uiFile} enthalten.\n");
        exit(1);
    }
}
$noteHelper = file_get_contents(__DIR__.'/../public/intern/calculation-note-helper.js');
foreach (['Abgeltungsvereinbarung – optischer Schaden', 'scrollHeight', '[Positionen eintragen]', '[Prozentsatz]'] as $needle) {
    if (!is_string($noteHelper) || !str_contains($noteHelper, $needle)) {
        fwrite(STDERR, "Mustertext- oder Größenlogik fehlt: {$needle}\n");
        exit(1);
    }
}

echo "Kalkulationsentwurf: Werte, Quellen, Bilder, Kontextbudget, optische Abgeltung, mitwachsendes Hinweisfeld und Bearbeitungslink geprüft.\n";
