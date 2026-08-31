<?php
declare(strict_types=1);

require_once __DIR__.'/../public/intern/api/gf-case-isolation.php';

function expectBlocked(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (RuntimeException $error) {
        if (!str_contains($error->getMessage(), 'Falltrennungs-Sperre')) {
            throw new RuntimeException($message.' (falsche Fehlermeldung: '.$error->getMessage().')');
        }
        return;
    }
    throw new RuntimeException($message.' (nicht blockiert)');
}

$meta = [
    'schaden_nr' => '25-242258-2',
    'versicherungsschein_nr' => '50109011435',
    'schadenart' => 'Leitungswasser (Wohngebäude)',
];
$sources = ['Schaden Bericht-25-242258-2 GF.pdf', 'Angebot 248251094-1.pdf'];

$valid = [
    'summary' => 'Schlussbericht zum Leitungswasserschaden 25-242258-2.',
    'sections' => [
        ['heading' => 'Schadenursache', 'text' => 'Ein Rohrbruch führte zum bestimmungswidrigen Wasseraustritt.'],
    ],
];
$audit = gfValidateCaseIsolation($valid, $meta, $sources, 'Schlussbericht erstellen');
if (($audit['passed'] ?? false) !== true) throw new RuntimeException('Gültiger Leitungswasserschaden wurde nicht freigegeben.');

$foreignIdentifier = [
    'summary' => 'Schlussbericht',
    'sections' => [['heading' => 'Versicherung', 'text' => 'Zusätzlich besteht der Vertrag 50056520459.']],
];
expectBlocked(
    static fn() => gfValidateCaseIsolation($foreignIdentifier, $meta, $sources, 'Schlussbericht erstellen'),
    'Fremde Vertragsnummer muss blockiert werden'
);

$sameCaseEvidence = [['files' => [['name' => 'Unterlagen-Kompakt.pdf', 'facts' => ['Schaden-Nr. 25-242258-2', 'VSNR 12-5392833-60']]]]];
$sameCasePolicy = ['summary' => 'Schlussbericht zum Schaden 25-242258-2 und Vertrag 12-5392833-60.', 'sections' => []];
$sameCaseAudit = gfValidateCaseIsolation($sameCasePolicy, $meta, $sources, 'Schlussbericht erstellen', $sameCaseEvidence);
if (($sameCaseAudit['passed'] ?? false) !== true) throw new RuntimeException('In derselben Fallquelle belegte Vertragsnummer wurde fälschlich blockiert.');

$unrelatedEvidence = [['files' => [['name' => 'Fremd.pdf', 'facts' => ['VSNR 98-7654321-10']]]]];
expectBlocked(
    static fn() => gfValidateCaseIsolation($sameCasePolicy, $meta, $sources, 'Schlussbericht erstellen', $unrelatedEvidence),
    'Vertragsnummer ohne Bezug zur aktiven Schaden-Nr. muss blockiert werden'
);

$foreignFire = [
    'summary' => 'Schlussbericht',
    'sections' => [[
        'heading' => 'Schadenursache',
        'text' => 'Ein Brandereignis an der Lichtleiste führte zu Ruß und erforderte eine Ozonbehandlung.',
    ]],
];
expectBlocked(
    static fn() => gfValidateCaseIsolation($foreignFire, $meta, $sources, 'Schlussbericht erstellen'),
    'Brandschadeninhalt im Leitungswasserschaden muss blockiert werden'
);

$generator = file_get_contents(__DIR__.'/../public/intern/api/gf-ai-generate.php');
$core = file_get_contents(__DIR__.'/../public/intern/api/gf-ai-generate-core.php');
if (!is_string($generator) || !str_contains($generator, 'gfValidateCaseIsolation($result,$meta,$sourceNames,$instructions,$caseEvidence)')) throw new RuntimeException('Falltrennung erhält den dateibezogenen Evidenzbestand nicht.');
if (!str_contains($generator, "'Datei '.(\\\$processedCaseFiles+count(\\\$chunkRefs)+1).' von '") || !str_contains($generator, "' · Gruppe '.(\\\$chunkIndex+1)")) throw new RuntimeException('Eindeutiger Datei- und Gruppenfortschritt fehlt.');
if (!is_string($core) || !str_contains($core, 'BKI-Kalkulation|BKI-Abgeltung|Abgeltungsvereinbarung')) throw new RuntimeException('Erzeugte BKI-/Abgeltungsdateien werden erneut als Fallquellen eingelesen.');

echo "GF case isolation tests passed\n";

