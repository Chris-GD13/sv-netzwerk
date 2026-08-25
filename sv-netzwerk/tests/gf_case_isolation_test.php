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

echo "GF case isolation tests passed\n";

