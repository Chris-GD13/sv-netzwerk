<?php
declare(strict_types=1);

$source = file_get_contents(__DIR__.'/../public/intern/api/kva-release.php');
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

foreach (['krEvidenceId', 'krSameTotalsBlock', 'krSameOfferIdentity', 'krMoney', 'krTotalsValid'] as $function) loadFunction($source, $function);
$core = file_get_contents(__DIR__.'/../public/intern/api/kva-release-core-v2.php');
if ($core === false) exit(1);
loadFunction($core, 'krMergeContacts');

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$offer = ['quote_number'=>'AN2629355', 'document_type'=>'Angebot', 'total_page'=>3, 'totals_evidence'=>'Nettowarenwert 1.160,00 EUR | MwSt. 220,40 EUR | Gesamtbetrag 1.380,40 EUR'];
$same = [$offer, $offer, $offer];
check(krSameOfferIdentity(...$same), 'Offer identity must match across all reads');
check(krSameTotalsBlock(...$same), 'Total block must match across all reads');
check(abs((float)krMoney('1.380,40 EUR') - 1380.40) < 0.001, 'Regression total must parse as 1,380.40');
check(krTotalsValid(1160.00, 220.40, 1380.40, ['totals_confident'=>true]), 'Confirmed regression total must validate');

$differentPage = $offer;
$differentPage['total_page'] = 4;
check(!krSameTotalsBlock($offer, $offer, $differentPage), 'Different total pages must block approval');
$differentEvidence = $offer;
$differentEvidence['totals_evidence'] = 'Gesamtbetrag 8.750,99 EUR';
check(!krSameTotalsBlock($offer, $offer, $differentEvidence), 'Different evidence must block approval');
$differentNumber = $offer;
$differentNumber['quote_number'] = 'AN9999999';
check(!krSameOfferIdentity($offer, $offer, $differentNumber), 'Different offer numbers must block approval');
check(!krTotalsValid(1160.00, null, 1380.40, ['totals_confident'=>true]), 'Missing VAT must block approval');
$contacts = krMergeContacts(['sanierer_firma'=>'Bestehende Firma', 'sanierer_email'=>'alt@example.test'], ['company'=>'Mey Generalbau GmbH', 'street'=>'Au Ost 5', 'email'=>'kontakt@meygeneralbau.de', 'phone'=>'0 70 71 / 97 96 2-0']);
check($contacts['case_contact_updates']['sanierer_strasse'] === 'Au Ost 5', 'Missing case contacts must be supplemented');
check($contacts['contact_hints']['sanierer_firma'] === 'Mey Generalbau GmbH', 'Conflicting case contacts must be retained as hints');
check($contacts['contacts']['email'] === 'alt@example.test', 'Existing case contacts must not be overwritten');

echo "KVA total validation safeguards passed.\n";
