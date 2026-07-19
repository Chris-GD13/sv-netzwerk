<?php
declare(strict_types=1);

require_once __DIR__ . '/form-handler-core.php';

$base = 'https://www.sv-netzwerk.eu/gutachter-plattform/anfrage/';

sv_process_form([
    'form_key' => 'gutachter-plattform',
    'to' => 'info@gutachter-plattform.de',
    'subject' => 'Gutachter-Plattform Anfrage ueber SV-Netzwerk',
    'intro' => "Neue Anfrage zur Gutachter-Plattform.\nHinweis: Anfrage ueber sv-netzwerk.eu vermittelt.",
    'return_url' => $base,
    'success_url' => $base . '?gesendet=1',
    'error_url' => $base . '?fehler=1',
    'mail_error_url' => $base . '?fehler=mail',
    'reply_name_field' => 'Name',
    'reply_email_field' => 'E-Mail',
    'fields' => [
        ['name' => 'Formular', 'label' => 'Formular'],
        ['name' => 'Herkunft', 'label' => 'Herkunft'],
        ['name' => 'Name', 'label' => 'Name', 'required' => true],
        ['name' => 'Unternehmen', 'label' => 'Unternehmen / Organisation'],
        ['name' => 'Rolle', 'label' => 'Rolle / Zielgruppe', 'required' => true],
        ['name' => 'E-Mail', 'label' => 'E-Mail', 'required' => true],
        ['name' => 'Telefon', 'label' => 'Telefon'],
        ['name' => 'Thema', 'label' => 'Thema', 'required' => true],
        ['name' => 'Nachricht', 'label' => 'Nachricht', 'required' => true, 'mode' => 'multiline'],
        ['name' => 'Datenschutz', 'label' => 'Datenschutz', 'required' => true],
    ],
]);
