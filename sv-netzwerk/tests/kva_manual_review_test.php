<?php
declare(strict_types=1);

$api = file_get_contents(__DIR__.'/../public/intern/api/kva-release.php');
$core = file_get_contents(__DIR__.'/../public/intern/api/kva-release-core-v2.php');
$page = file_get_contents(__DIR__.'/../src/pages/intern/versicherungsfaelle/index.astro');
if (!is_string($api) || !is_string($core) || !is_string($page)) exit(1);

function loadKvaFunction(string $source, string $name): void
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

loadKvaFunction($api, 'krMoney');
loadKvaFunction($core, 'krReviewedKva');

$preview = ['case_no'=>'26-130133-6 GF','company'=>'Mey Generalbau GmbH','email'=>'kontakt@meygeneralbau.de','quote_number'=>'AN2629164','insurer'=>'SV SparkassenVersicherung','net'=>null,'gross'=>null];
$reviewed = krReviewedKva($preview, ['net'=>'19.842,16 €','gross'=>'23.612,18 €','subject'=>'Manuell geprüfte KVA-Freigabe','body'=>'Geprüfter Freigabetext']);
if (abs($reviewed['net'] - 19842.16) > 0.001 || abs($reviewed['gross'] - 23612.18) > 0.001) throw new RuntimeException('Manuell ergänzte Beträge werden nicht übernommen.');
if ($reviewed['subject'] !== 'Manuell geprüfte KVA-Freigabe' || !$reviewed['sparkasse']) throw new RuntimeException('Manuell geprüfte Angaben werden nicht vollständig übernommen.');

$checks = [
    'Firma editierbar'=>!str_contains($page, 'id="vf-kva-company" readonly'),
    'KVA-Nummer editierbar'=>!str_contains($page, 'id="vf-kva-number" readonly'),
    'Netto editierbar'=>!str_contains($page, 'id="vf-kva-net" readonly'),
    'Brutto editierbar'=>!str_contains($page, 'id="vf-kva-gross" readonly'),
    'Betreff editierbar'=>!str_contains($page, 'id="vf-kva-subject" readonly'),
    'Freigabetext synchronisiert'=>str_contains($page, 'function syncDraftText()'),
    'Geprüfte Werte werden versendet'=>str_contains($page, 'token,...values'),
];
$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
if ($failed !== []) throw new RuntimeException("Fehlgeschlagen:\n- ".implode("\n- ", $failed));

echo "Manuelle KVA-Prüffelder und Textsynchronisierung sind aktiv.\n";
