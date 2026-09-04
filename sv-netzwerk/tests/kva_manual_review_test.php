<?php
declare(strict_types=1);

$api = file_get_contents(__DIR__.'/../public/intern/api/kva-release.php');
$core = file_get_contents(__DIR__.'/../public/intern/api/kva-release-core-v2.php');
$page = file_get_contents(__DIR__.'/../src/pages/intern/versicherungsfaelle/index.astro');
if (!is_string($api) || !is_string($core) || !is_string($page)) exit(1);
require_once __DIR__.'/../public/intern/api/kva-contact-merge.php';
if (!defined('KR_MEY_GENERALBAU_RECIPIENT')) define('KR_MEY_GENERALBAU_RECIPIENT', 'backoffice@meygeneralbau.de');

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
loadKvaFunction($core, 'krMeyGeneralbau');
loadKvaFunction($core, 'krMeyOperationalCc');
loadKvaFunction($core, 'krReviewedKva');

$preview = ['case_no'=>'26-130133-6 GF','company'=>'Mey Generalbau GmbH','email'=>'kontakt@meygeneralbau.de','quote_number'=>'AN2629164','insurer'=>'SV SparkassenVersicherung','net'=>null,'gross'=>null];
$reviewed = krReviewedKva($preview, ['net'=>'19.842,16 €','gross'=>'23.612,18 €','subject'=>'Manuell geprüfte KVA-Freigabe','body'=>'Geprüfter Freigabetext']);
if (abs($reviewed['net'] - 19842.16) > 0.001 || abs($reviewed['gross'] - 23612.18) > 0.001) throw new RuntimeException('Manuell ergänzte Beträge werden nicht übernommen.');
if ($reviewed['subject'] !== 'Manuell geprüfte KVA-Freigabe' || !$reviewed['sparkasse']) throw new RuntimeException('Manuell geprüfte Angaben werden nicht vollständig übernommen.');
if ($reviewed['email'] !== 'backoffice@meygeneralbau.de' || !$reviewed['mey_generalbau']) throw new RuntimeException('Mey-Generalbau-Freigaben werden nicht verbindlich ans Backoffice geroutet.');
$meyCc = krMeyOperationalCc([
    'issuer_confirmed'=>true,
    'company_relation'=>'issuer',
    'company'=>'Mey Generalbau GmbH',
    'contact_people'=>[
        ['name'=>'Berta Bau', 'role'=>'Bauleiterin', 'email'=>'berta.bau@meygeneralbau.de', 'operational'=>true],
        ['name'=>'Achim Mey', 'role'=>'Geschäftsführer', 'email'=>'achim@meygeneralbau.de', 'operational'=>false],
    ],
], ['sanierer_ansprechpartner'=>'Peter Projekt', 'sanierer_funktion'=>'Projektleiter', 'sanierer_email'=>'peter.projekt@meygeneralbau.de']);
if ($meyCc !== ['berta.bau@meygeneralbau.de', 'peter.projekt@meygeneralbau.de']) throw new RuntimeException('Operative Mey-Ansprechpartner werden nicht korrekt in CC übernommen.');

$checks = [
    'Firma editierbar'=>!str_contains($page, 'id="vf-kva-company" readonly'),
    'KVA-Nummer editierbar'=>!str_contains($page, 'id="vf-kva-number" readonly'),
    'Netto editierbar'=>!str_contains($page, 'id="vf-kva-net" readonly'),
    'Brutto editierbar'=>!str_contains($page, 'id="vf-kva-gross" readonly'),
    'Betreff editierbar'=>!str_contains($page, 'id="vf-kva-subject" readonly'),
    'Freigabetext synchronisiert'=>str_contains($page, 'function syncDraftText()'),
    'Geprüfte Werte werden versendet'=>str_contains($page, 'token,...values'),
    'Mey-Empfänger im Portal gesperrt'=>str_contains($page, "readOnly=!!x.recipient_locked"),
    'Mey-Backoffice serverseitig erzwungen'=>str_contains($core, "KR_MEY_GENERALBAU_RECIPIENT = 'backoffice@meygeneralbau.de'") && str_contains($core, "unset(\$cc['kontakt@meygeneralbau.de'])"),
];
$failed = array_keys(array_filter($checks, static fn(bool $passed): bool => !$passed));
if ($failed !== []) throw new RuntimeException("Fehlgeschlagen:\n- ".implode("\n- ", $failed));

echo "Manuelle KVA-Prüffelder und Textsynchronisierung sind aktiv.\n";
