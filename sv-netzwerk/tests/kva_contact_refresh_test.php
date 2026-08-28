<?php
declare(strict_types=1);

function checkContactRefresh(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$page = file_get_contents(__DIR__.'/../src/pages/intern/versicherungsfaelle/index.astro');
$core = file_get_contents(__DIR__.'/../public/intern/api/kva-release-core-v2.php');

checkContactRefresh(is_string($page) && str_contains($page, 'id="vf-sanierer-refresh"'), 'Button zum erneuten Einlesen fehlt.');
checkContactRefresh(str_contains($page, 'Saniererdaten aus KVA neu einlesen'), 'Button ist nicht eindeutig beschriftet.');
checkContactRefresh(str_contains($page, 'action=refresh_contacts'), 'Kontaktaktualisierung wird nicht aufgerufen.');
checkContactRefresh(str_contains($page, 'action=save_case'), 'Manuelle Falldaten werden vor der Kontaktaktualisierung nicht gespeichert.');
checkContactRefresh(str_contains($page, 'action=patch_case_contacts'), 'Erkannte Kontakte werden nicht verlustfrei ergänzt.');

checkContactRefresh(is_string($core) && str_contains($core, "if (\$action === 'refresh_contacts')"), 'Kontaktaktualisierungs-Endpunkt fehlt.');
checkContactRefresh(str_contains($core, '$selected = $kvas[0]'), 'Der neueste KVA wird nicht ausgewählt.');
checkContactRefresh(str_contains($core, 'kvaMergeCaseContacts(krCaseContacts'), 'Vorhandene Saniererdaten werden nicht vor Überschreiben geschützt.');
checkContactRefresh(str_contains($core, "'contact_hints'=>\$contactMerge['conflicts']"), 'Abweichende KVA-Angaben werden nicht als Hinweise geliefert.');

echo "Saniererdaten können aus dem neuesten KVA verlustfrei neu eingelesen werden.\n";
