<?php
declare(strict_types=1);

require_once __DIR__ . '/form-handler-core.php';

$base = 'https://www.sv-netzwerk.eu/schaden-melden/';

sv_process_form([
    'form_key' => 'schadenmeldung',
    'to' => 'info@sv-netzwerk.eu',
    'subject' => 'SV-Netzwerk: Schadenmeldung',
    'intro' => 'Neue Schadenmeldung ueber sv-netzwerk.eu',
    'return_url' => $base,
    'success_url' => $base . '?gesendet=1',
    'error_url' => $base . '?fehler=1',
    'mail_error_url' => $base . '?fehler=mail',
    'reply_name_field' => 'Name',
    'reply_email_field' => 'E-Mail',
    'upload_field' => 'Dateien',
    'fields' => [
        ['name' => 'Schadenart', 'label' => 'Schadenart', 'required' => true],
        ['name' => 'Schadenort', 'label' => 'Schadenort', 'required' => true],
        ['name' => 'Objektart', 'label' => 'Objektart', 'required' => true],
        ['name' => 'Schadennummer', 'label' => 'Schadennummer'],
        ['name' => 'Name', 'label' => 'Name', 'required' => true],
        ['name' => 'Unternehmen', 'label' => 'Unternehmen / Auftraggeber'],
        ['name' => 'E-Mail', 'label' => 'E-Mail', 'required' => true],
        ['name' => 'Telefon', 'label' => 'Telefon'],
        ['name' => 'Schadendatum', 'label' => 'Schadendatum', 'required' => true],
        ['name' => 'Kurzbeschreibung', 'label' => 'Kurzbeschreibung', 'required' => true, 'mode' => 'multiline'],
        ['name' => 'Dringlichkeit', 'label' => 'Dringlichkeit', 'required' => true],
        ['name' => 'Datenschutz', 'label' => 'Datenschutz', 'required' => true],
    ],
]);
