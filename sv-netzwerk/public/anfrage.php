<?php
declare(strict_types=1);

require_once __DIR__ . '/form-handler-core.php';

$formular = sv_field('Formular');
$isTermin = $formular === 'Terminvereinbarung';
$returnUrl = $isTermin ? 'https://www.sv-netzwerk.eu/termin-vereinbaren/' : 'https://www.sv-netzwerk.eu/kontakt/';
$anchor = '';

sv_process_form([
    'form_key' => 'kontakt',
    'to' => 'info@sv-netzwerk.eu',
    'subject' => 'SV-Netzwerk: ' . ($formular !== '' ? $formular : 'Kontaktanfrage'),
    'intro' => 'Neue Kontaktanfrage ueber sv-netzwerk.eu',
    'return_url' => $returnUrl,
    'success_url' => $returnUrl . '?gesendet=1' . $anchor,
    'error_url' => $returnUrl . '?fehler=1' . $anchor,
    'mail_error_url' => $returnUrl . '?fehler=mail' . $anchor,
    'reply_name_field' => 'Name',
    'reply_email_field' => 'E-Mail',
    'fields' => [
        ['name' => 'Formular', 'label' => 'Formular'],
        ['name' => 'Name', 'label' => 'Name / Auftraggeber', 'required' => true],
        ['name' => 'E-Mail', 'label' => 'E-Mail', 'required' => true],
        ['name' => 'Telefon', 'label' => 'Telefon'],
        ['name' => 'Thema', 'label' => 'Thema', 'required' => true],
        ['name' => 'Nachricht', 'label' => 'Nachricht', 'required' => true, 'mode' => 'multiline'],
    ],
]);
