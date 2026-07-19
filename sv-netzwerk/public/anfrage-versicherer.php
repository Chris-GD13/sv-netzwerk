<?php
declare(strict_types=1);

require_once __DIR__ . '/form-handler-core.php';

$base = 'https://www.sv-netzwerk.eu/versicherer/';

sv_process_form([
    'form_key' => 'versicherer',
    'to' => 'info@sv-netzwerk.eu',
    'subject' => 'SV-Netzwerk: Anfrage / Beauftragung fuer Versicherer',
    'intro' => 'Neue Anfrage / Beauftragung fuer Versicherer ueber sv-netzwerk.eu',
    'return_url' => $base,
    'success_url' => $base . '?gesendet=1#anfrage',
    'error_url' => $base . '?fehler=1#anfrage',
    'mail_error_url' => $base . '?fehler=mail#anfrage',
    'reply_name_field' => 'Ansprechpartner',
    'reply_email_field' => 'E-Mail',
    'fields' => [
        ['name' => 'Vorgangsart', 'label' => 'Vorgangsart', 'required' => true],
        ['name' => 'Auftraggeber', 'label' => 'Auftraggeber', 'required' => true],
        ['name' => 'Ansprechpartner', 'label' => 'Ansprechpartner', 'required' => true],
        ['name' => 'E-Mail', 'label' => 'E-Mail', 'required' => true],
        ['name' => 'Telefon', 'label' => 'Telefon'],
        ['name' => 'Schadennummer', 'label' => 'Schadennummer', 'required' => true],
        ['name' => 'Schadenort', 'label' => 'Schadenort', 'required' => true],
        ['name' => 'Schadenbereich[]', 'label' => 'Schadenbereich', 'mode' => 'list'],
        ['name' => 'Schadenart[]', 'label' => 'Schadenart', 'mode' => 'list'],
        ['name' => 'Kurzbeschreibung', 'label' => 'Kurzbeschreibung', 'required' => true, 'mode' => 'multiline'],
    ],
]);
