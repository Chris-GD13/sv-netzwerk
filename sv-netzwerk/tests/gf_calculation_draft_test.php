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
        'quantity_source' => 'Aufmaß.pdf, Seite 2',
        'quantity_confidence' => 'belegt',
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
foreach (['KI-Erstentwurf', 'Mengenquellen:', 'Aufmaß.pdf, Seite 2', 'Annahmen:', 'Offene Punkte:', 'Berücksichtigte Vorgabe:'] as $needle) {
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

$provisionalState = gfCalculationDraftState([
    'items' => [[
        'position_code' => 'BKI 456',
        'description' => 'Sichtbar geschädigte Wandfläche bearbeiten',
        'quantity' => 8,
        'unit' => 'm²',
        'unit_price' => 52.40,
        'source_name' => 'BKI Altbau 2026',
        'source_page' => '456',
        'quantity_source' => 'Vorläufige Schätzmenge aus Schadenfoto 3',
        'quantity_confidence' => 'vorläufig',
    ]],
], [], 'Erste Kalkulation erstellen.');
if (!str_contains((string)($provisionalState['note'] ?? ''), 'Vorläufig geschätzte Mengen – zwingend vor Ort prüfen')) {
    fwrite(STDERR, "Vorläufige Bild-/Berichtsmenge wird nicht transparent gekennzeichnet.\n");
    exit(1);
}

$calculationHelper = file_get_contents(__DIR__.'/../public/intern/api/gf-calculation-draft.php');
foreach ([
    'function gfPlanCalculationFromEvidence(',
    'function gfPriceCalculationPlan(',
    'function gfCalculationRequiresWaterBlocks(',
    'function gfCalculationHasRequiredWaterBlocks(',
    'function gfEnsureRequiredWaterCalculationBlocks(',
    'Werte Bericht, vorhandene Aufmaße und die dateibezogenen Fotobefunde gemeinsam aus.',
    'Mengen aus Bericht, Aufmaß oder eindeutig zählbarem Fotobefund haben Vorrang',
    'Ziel ist eine tatsächlich nutzbare erste Kalkulation und nicht ein leerer Entwurf.',
    'Eine Position darf nicht allein wegen fehlenden Aufmaßes entfallen.',
    'Trocknung, Rückbau einschließlich Entsorgung und Wiederherstellung',
    'quantity_confidence',
    'if (!empty($result[\'items\']) && is_array($result[\'items\'])) return gfEnsureRequiredWaterCalculationBlocks(',
    'return gfEnsureRequiredWaterCalculationBlocks($priced, $evidence, $meta, $instructions, $storeId);',
] as $needle) {
    if (!is_string($calculationHelper) || !str_contains($calculationHelper, $needle)) {
        fwrite(STDERR, "Zweistufige Kalkulation aus Bericht, Aufmaß, Bildern und BKI fehlt: {$needle}\n");
        exit(1);
    }
}

if (!gfCalculationRequiresWaterBlocks(['schadenart' => 'Leitungswasserschaden'])) {
    fwrite(STDERR, "Wasserschaden wurde nicht als Fall mit vier Pflicht-Kostenblöcken erkannt.\n");
    exit(1);
}
if (gfCalculationRequiresWaterBlocks(['schadenart' => 'Sturmschaden'])) {
    fwrite(STDERR, "Die Wasserschaden-Pflichtblöcke wurden fälschlich auf einen Sturmschaden angewendet.\n");
    exit(1);
}
$completeWaterCalculation = ['items' => [
    ['description' => 'Ergebnisorientierte Leckageortung und Reparatur der Leckage'],
    ['description' => 'Technische Trocknung der durchfeuchteten Bauteile'],
    ['description' => 'Rückbau und Entsorgung geschädigter Wandbekleidungen'],
    ['description' => 'Wiederherstellung von Innenputz und Anstrich'],
]];
if (!gfCalculationHasRequiredWaterBlocks($completeWaterCalculation)) {
    fwrite(STDERR, "Vollständige Wasserschaden-Kalkulation wurde nicht erkannt.\n");
    exit(1);
}
if (gfCalculationHasRequiredWaterBlocks(['items' => array_slice($completeWaterCalculation['items'], 0, 3)])) {
    fwrite(STDERR, "Unvollständige Wasserschaden-Kalkulation hat die Vier-Block-QS bestanden.\n");
    exit(1);
}

$emptyState = gfCalculationDraftState([
    'summary' => 'Die Akte enthält noch keine belegten Mengen und Preise.',
    'items' => [],
    'open_points' => ['Leistungen und Mengen auf der Kalkulationsseite ergänzen.'],
], [
    'schaden_nr' => '26-154397-2',
    'schaden_plz' => '72639',
    'schaden_ort' => 'Neuffen',
], 'Kalkulation als bearbeitbaren Entwurf vorbereiten.');
if (($emptyState['lines'] ?? null) !== [] || empty($emptyState['requiresManualCompletion'])) {
    fwrite(STDERR, "Ein aktenbedingt leerer Kalkulationsentwurf wurde nicht zur manuellen Ergänzung freigegeben.\n");
    exit(1);
}
foreach (['keine fachlich vertretbare Position', 'zur manuellen Ergänzung angelegt'] as $needle) {
    if (!str_contains((string)($emptyState['note'] ?? ''), $needle)) {
        fwrite(STDERR, "Sicherheitshinweis im leeren Kalkulationsentwurf fehlt: {$needle}.\n");
        exit(1);
    }
}

$generator = file_get_contents(__DIR__.'/../public/intern/api/gf-ai-generate.php');
foreach (['empty_editable_draft', 'manual_completion_required', 'requiresManualCompletion', "in_array(\\'kalkulation\\',\$outputs,true)"] as $needle) {
    if (!is_string($generator) || !str_contains($generator, $needle)) {
        fwrite(STDERR, "Kalkulations-QS oder automatische BKI-Aktivierung fehlt: {$needle}\n");
        exit(1);
    }
}

$portalPage = file_get_contents(__DIR__.'/../src/pages/intern/versicherungsfaelle/index.astro');
foreach (['statusFailures=0', 'läuft serverseitig weiter.', 'Statusverbindung kurz unterbrochen', 'return loadGenerated(attempt+1)', 'Erneuter Abruf läuft'] as $needle) {
    if (!is_string($portalPage) || !str_contains($portalPage, $needle)) {
        fwrite(STDERR, "Tolerantes Kalkulations-Polling fehlt: {$needle}\n");
        exit(1);
    }
}
$insuranceCalculationPage = file_get_contents(__DIR__.'/../src/pages/intern/kalkulation/versicherungsschaeden.astro');
foreach (['Diesen Fall kalkulieren', 'vs-case-tools', 'waterQuick', "['01.01.000',1,'Ortung / Reparatur']", "['02.01.000',1,'Trocknung']", "['10.00.000',10,'Rückbau']", "['03.01.000',1,'Wiederherstellung']", "['13.02.000',24,'Rückbau Laminat']", "['13.04.000',24,'Wiederherstellung Laminat']", "['13.05.004',24,'Wiederherstellung Laminat']", 'Schnelldurchgang Wasserschaden'] as $needle) {
    if (!is_string($insuranceCalculationPage) || !str_contains($insuranceCalculationPage, $needle)) {
        fwrite(STDERR, "Wasserschaden-Schnelldurchgang auf der Versicherungsschaden-Kalkulation fehlt: {$needle}\n");
        exit(1);
    }
}
$recoveryScript = file_get_contents(__DIR__.'/../public/intern/transient-calculation-status.js');
foreach (['Bearbeitung angehalten', 'Failed to fetch', 'interruptedTexts.some'] as $needle) {
    if (!is_string($recoveryScript) || !str_contains($recoveryScript, $needle)) {
        fwrite(STDERR, "Wiederaufnahme nach Verbindungsfehler fehlt: {$needle}\n");
        exit(1);
    }
}
$portalLayout = file_get_contents(__DIR__.'/../src/layouts/InternalLayout.astro');
if (!is_string($portalLayout) || !str_contains($portalLayout, 'transient-calculation-status.js?v=20260901-1')) {
    fwrite(STDERR, "Cache-Busting für die korrigierte Statuswiederaufnahme fehlt.\n");
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
    "const GF_OPENAI_UPLOAD_POLICY_VERSION='2';",
    'function gfOpenAIUploadExtension',
    'function gfOpenAIUploadName',
    "'image/jpeg'=>'jpg'",
    "'image/png'=>'png'",
    "'image/gif'=>'gif'",
    "'image/webp'=>'webp'",
    "'application/x-pdf'=>'pdf'",
    "\$tmp=\$tmpBase.'.'.\$extension",
    "'policy'=>GF_OPENAI_UPLOAD_POLICY_VERSION",
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

$uploadHelperStart = is_string($aiCore) ? strpos($aiCore, 'function gfOpenAIUploadExtension') : false;
$uploadHelperEnd = is_string($aiCore) ? strpos($aiCore, 'function gfSupported', (int)$uploadHelperStart) : false;
if ($uploadHelperStart === false || $uploadHelperEnd === false) {
    fwrite(STDERR, "KI-Dateiregeln konnten nicht für den Verhaltenstest geladen werden.\n");
    exit(1);
}
eval(substr($aiCore, $uploadHelperStart, $uploadHelperEnd - $uploadHelperStart));
$uploadNames = [
    ['Bericht.PDF', 'application/octet-stream', 'Bericht.pdf'],
    ['scan.noname2', 'application/pdf', 'scan.pdf'],
    ['scan.noname2', 'application/x-pdf', 'scan.pdf'],
    ['scan.noname2', 'application/octet-stream', ''],
    ['Tabelle', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Tabelle.xlsx'],
];
foreach ($uploadNames as [$name, $mime, $expected]) {
    $actual = gfOpenAIUploadName($name, $mime);
    if ($actual !== $expected) {
        fwrite(STDERR, "KI-Dateiname wurde nicht sicher normalisiert: {$name} / {$mime} => {$actual}.\n");
        exit(1);
    }
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
