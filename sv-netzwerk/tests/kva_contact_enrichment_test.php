<?php
declare(strict_types=1);

require_once __DIR__.'/../public/intern/api/kva-contact-merge.php';

function checkContact(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$detected = kvaDetectedCaseContacts([
    'company'=>'Mey Generalbau GmbH',
    'contact_people'=>[
        ['name'=>'Andreas Gärtner', 'role'=>'Bauleiter'],
        ['name'=>'Achim Mey', 'role'=>'Geschäftsführer'],
        ['name'=>'Andreas Trauschweizer', 'role'=>'Geschäftsführer'],
    ],
    'street'=>'Au Ost 5', 'postal_code'=>'72072', 'city'=>'Tübingen',
    'email'=>'kontakt@meygeneralbau.de', 'phone'=>'0 70 71 / 97 96 2-0',
    'fax'=>'0 70 71 / 97 96 2-10', 'website'=>'www.meygeneralbau.de',
]);

checkContact($detected['sanierer_firma'] === 'Mey Generalbau GmbH', 'Firma fehlt.');
checkContact(str_contains($detected['sanierer_ansprechpartner'], 'Andreas Gärtner') && str_contains($detected['sanierer_ansprechpartner'], 'Achim Mey') && str_contains($detected['sanierer_ansprechpartner'], 'Andreas Trauschweizer'), 'Nicht alle Ansprechpartner wurden übernommen.');
checkContact(str_contains($detected['sanierer_funktion'], 'Bauleiter') && str_contains($detected['sanierer_funktion'], 'Geschäftsführer'), 'Rollen fehlen.');
checkContact($detected['sanierer_strasse'] === 'Au Ost 5' && $detected['sanierer_plz'] === '72072' && $detected['sanierer_ort'] === 'Tübingen', 'Anschrift unvollständig.');
checkContact($detected['sanierer_telefon'] === '0 70 71 / 97 96 2-0' && $detected['sanierer_fax'] === '0 70 71 / 97 96 2-10', 'Telefon oder Fax fehlt.');
checkContact($detected['sanierer_email'] === 'kontakt@meygeneralbau.de' && $detected['sanierer_website'] === 'www.meygeneralbau.de', 'E-Mail oder Website fehlt.');
checkContact(!array_key_exists('email', $detected) && !array_key_exists('telefon', $detected), 'Saniererdaten dürfen nicht in VN-Felder geschrieben werden.');

$first = kvaMergeCaseContacts(['sanierer_email'=>'bestehend@example.test'], $detected, 'AN2629355.pdf', 'AN2629355', '2026-08-28T10:00:00Z');
checkContact($first['case']['sanierer_email'] === 'bestehend@example.test', 'Vorhandene Daten wurden überschrieben.');
checkContact($first['case']['sanierer_firma'] === 'Mey Generalbau GmbH', 'Fehlende Firma wurde nicht ergänzt.');
checkContact($first['conflicts']['sanierer_email']['detected'] === 'kontakt@meygeneralbau.de', 'Abweichende E-Mail wurde nicht als Hinweis festgehalten.');
checkContact(count($first['case']['sanierer_kva_hinweise']) === 1, 'Abweichungshinweis fehlt.');

$second = kvaMergeCaseContacts($first['case'], $detected, 'AN2629355.pdf', 'AN2629355', '2026-08-28T10:05:00Z');
checkContact(count($second['case']['sanierer_kva_hinweise']) === 1, 'Identischer Hinweis wurde doppelt gespeichert.');

$endpoint = file_get_contents(__DIR__.'/../public/intern/api/kva-release.php');
$drive = file_get_contents(__DIR__.'/../public/intern/api/google-drive-sync.php');
checkContact(is_string($endpoint) && str_contains($endpoint, 'Verwechsle Empfänger, Versicherungsnehmer, Versicherer oder Regulierer niemals mit dem Absender/Sanierer.'), 'Trennregel im Kontaktprompt fehlt.');
checkContact(is_string($drive) && str_contains($drive, "case'patch_case_contacts'"), 'Atomare Fallergänzung fehlt.');

echo "KVA-Saniererkontakte werden vollständig und verlustfrei ergänzt.\n";
