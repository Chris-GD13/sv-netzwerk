<?php
declare(strict_types=1);

require_once __DIR__ . '/form-handler-core.php';

$base = 'https://www.sv-netzwerk.eu/versicherer/beauftragungswege/';
$channels = ['GDV Schaden-Service', 'BiPRO', 'KUSS-ServicePortal', 'Sonstiges Auftragssystem'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !in_array(sv_field('Beauftragungsweg'), $channels, true)) {
    sv_redirect($base . '?fehler=1#verknuepfung');
}

sv_process_form([
    'form_key' => 'dienstleister-verknuepfung',
    'to' => 'info@sv-netzwerk.eu',
    'subject' => 'SV-Netzwerk: Dienstleister-Verknüpfung',
    'intro' => 'Anfrage zur Dienstleister-Verknüpfung. Eine technische Aktivierung ist damit noch nicht erfolgt.',
    'return_url' => $base,
    'success_url' => $base . '?gesendet=1#verknuepfung',
    'error_url' => $base . '?fehler=1#verknuepfung',
    'mail_error_url' => $base . '?fehler=mail#verknuepfung',
    'reply_name_field' => 'Name',
    'reply_email_field' => 'E-Mail',
    'fields' => [
        ['name' => 'Beauftragungsweg', 'label' => 'Beauftragungsweg', 'required' => true],
        ['name' => 'Unternehmen', 'label' => 'Versicherer / Unternehmen', 'required' => true],
        ['name' => 'Name', 'label' => 'Ansprechpartner', 'required' => true],
        ['name' => 'E-Mail', 'label' => 'E-Mail', 'required' => true],
        ['name' => 'Telefon', 'label' => 'Telefon'],
        ['name' => 'Nachricht', 'label' => 'Auftragssystem und gewünschte Verknüpfung', 'required' => true, 'mode' => 'multiline'],
        ['name' => 'Datenschutz', 'label' => 'Datenschutz', 'required' => true],
    ],
]);
