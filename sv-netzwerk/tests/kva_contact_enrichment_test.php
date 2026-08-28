<?php
declare(strict_types=1);

require_once __DIR__.'/../public/intern/api/kva-contact-persistence.php';

function checkContact(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function issuer(array $data): array
{
    return ['issuer_confirmed'=>true, 'company_relation'=>'issuer', 'company'=>'Mey Generalbau GmbH'] + $data;
}

// 1. Projektleitung hat Vorrang vor Bauleitung und Geschäftsführung.
$projectLead = kvaDetectedCaseContacts(issuer(['contact_people'=>[
    ['name'=>'Berta Bau', 'role'=>'Bauleiterin', 'operational'=>true],
    ['name'=>'Peter Projekt', 'role'=>'Projektleiter', 'operational'=>true],
    ['name'=>'Gerd Geschäftsführer', 'role'=>'Geschäftsführer', 'operational'=>false],
]]));
checkContact($projectLead['sanierer_ansprechpartner'] === 'Peter Projekt' && $projectLead['sanierer_funktion'] === 'Projektleiter', 'Projektleitung muss vor Bauleitung priorisiert werden.');

// 2. Bauleitung hat Vorrang vor sonstigem operativem Kontakt und Geschäftsführung.
$siteLead = kvaDetectedCaseContacts(issuer(['contact_people'=>[
    ['name'=>'Olga Objekt', 'role'=>'Objektleiterin', 'operational'=>true],
    ['name'=>'Berta Bau', 'role'=>'Bauleiterin', 'operational'=>true],
    ['name'=>'Gerd Geschäftsführer', 'role'=>'Geschäftsführer', 'operational'=>false],
]]));
checkContact($siteLead['sanierer_ansprechpartner'] === 'Berta Bau', 'Bauleitung muss vor sonstigen operativen Kontakten priorisiert werden.');

// 3. Andere konkrete operative Ansprechpartner werden übernommen.
$otherOperational = kvaDetectedCaseContacts(issuer(['contact_people'=>[
    ['name'=>'Olga Objekt', 'role'=>'Objektleiterin', 'operational'=>true],
    ['name'=>'Gerd Geschäftsführer', 'role'=>'Geschäftsführer', 'operational'=>false],
]]));
checkContact($otherOperational['sanierer_ansprechpartner'] === 'Olga Objekt' && $otherOperational['sanierer_funktion'] === 'Objektleiterin', 'Konkreter operativer Kontakt wurde verworfen.');

// 4. Nur Geschäftsführung: Kontakt und Rolle ausdrücklich leeren.
$managementOnly = kvaDetectedCaseContacts(issuer(['contact_people'=>[
    ['name'=>'Achim Mey', 'role'=>'Geschäftsführer', 'operational'=>false],
    ['name'=>'Andreas Trauschweizer', 'role'=>'Inhaber', 'operational'=>false],
]]));
checkContact($managementOnly['sanierer_ansprechpartner'] === '' && $managementOnly['sanierer_funktion'] === '', 'Reine Leitungsangaben müssen ausdrücklich leer bleiben.');

// 5. Alte Geschäftsführung wird durch gültige Bauleitung ersetzt.
$detected = kvaDetectedCaseContacts(issuer([
    'contact_people'=>[['name'=>'Andreas Gärtner', 'role'=>'Bauleiter', 'operational'=>true]],
    'street'=>'Au Ost 5', 'postal_code'=>'72072', 'city'=>'Tübingen',
    'email'=>'kontakt@meygeneralbau.de', 'phone'=>'07071979620', 'mobile'=>'01711234567',
    'fax'=>'070719796210', 'website'=>'https://www.meygeneralbau.de',
]));
$replaced = kvaMergeCaseContacts(['sanierer_ansprechpartner'=>'Achim Mey','sanierer_funktion'=>'Geschäftsführer'], $detected, 'AN2629355.pdf', 'AN2629355');
checkContact($replaced['case']['sanierer_ansprechpartner'] === 'Andreas Gärtner' && $replaced['case']['sanierer_funktion'] === 'Bauleiter', 'Alte Geschäftsführung wurde nicht durch Bauleitung ersetzt.');

// 6. Vollständige Ausstellerdaten landen ausschließlich in Saniererfeldern.
foreach (['sanierer_firma','sanierer_strasse','sanierer_plz','sanierer_ort','sanierer_email','sanierer_telefon','sanierer_mobil','sanierer_fax','sanierer_website'] as $field) checkContact(($detected[$field] ?? '') !== '', 'Ausstellerfeld fehlt: '.$field);
foreach (['vn_objekt','strasse','plz','ort','telefon','mobil','email'] as $field) checkContact(!array_key_exists($field, $detected), 'Saniererdaten dürfen nicht in VN-Felder geschrieben werden: '.$field);

// 7. Empfänger/Versicherer ohne eindeutig bestätigten Aussteller wird vollständig abgewiesen.
$wrongCompany = kvaDetectedCaseContacts(['issuer_confirmed'=>false, 'company_relation'=>'insurer', 'company'=>'Sparkassenversicherung', 'email'=>'service@example.test']);
checkContact($wrongCompany === [], 'Nicht bestätigte oder fremde Firma wurde als Sanierer übernommen.');

// 8. Echter Persistenz-Roundtrip: leere Werte löschen alte Geschäftsführung dauerhaft.
$tmp = tempnam(sys_get_temp_dir(), 'kva-persist-');
file_put_contents($tmp, json_encode(['sanierer_ansprechpartner'=>'Achim Mey','sanierer_funktion'=>'Geschäftsführer','sanierer_email'=>'bestehend@example.test']));
$load = static fn(): array => json_decode((string)file_get_contents($tmp), true, 512, JSON_THROW_ON_ERROR);
$save = static function(array $case) use ($tmp): void { file_put_contents($tmp, json_encode($case, JSON_THROW_ON_ERROR)); };
$persisted = kvaPersistCaseContacts($load, $save, $managementOnly, 'AN2629355.pdf', 'AN2629355', '2026-08-28T10:00:00Z');
checkContact(array_key_exists('sanierer_ansprechpartner', $persisted['case']) && $persisted['case']['sanierer_ansprechpartner'] === '', 'Leerer Ansprechpartner wurde beim Speichern als keine Änderung behandelt.');
checkContact(array_key_exists('sanierer_funktion', $persisted['case']) && $persisted['case']['sanierer_funktion'] === '', 'Leere Funktion wurde nach Neuladen nicht beibehalten.');
checkContact($persisted['case']['sanierer_email'] === 'bestehend@example.test', 'Korrekte vorhandene Kontaktdaten wurden überschrieben.');
@unlink($tmp);

$conflict = kvaMergeCaseContacts(['sanierer_email'=>'bestehend@example.test'], $detected, 'AN2629355.pdf', 'AN2629355', '2026-08-28T10:00:00Z');
checkContact($conflict['case']['sanierer_email'] === 'bestehend@example.test' && isset($conflict['conflicts']['sanierer_email']), 'Abweichende korrekte Bestandsangabe wurde nicht geschützt.');

$endpoint = file_get_contents(__DIR__.'/../public/intern/api/kva-release.php');
$drive = file_get_contents(__DIR__.'/../public/intern/api/google-drive-sync.php');
checkContact(is_string($endpoint) && str_contains($endpoint, 'Nur diese Ausstellerfirma ist der Sanierer/Auftragnehmer.'), 'Verbindliche Ausstellerregel im Kontaktprompt fehlt.');
checkContact(is_string($drive) && str_contains($drive, 'kvaPersistCaseContacts'), 'Produktiver Persistenz-Roundtrip fehlt.');

echo "KVA-Aussteller und operative Betriebskontakte werden autoritativ erkannt, gespeichert und neu geladen.\n";
