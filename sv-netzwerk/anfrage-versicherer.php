<?php
declare(strict_types=1);

$to = 'info@sv-netzwerk.eu';
$from = 'info@sv-netzwerk.eu';
$subject = 'SV-Netzwerk: Anfrage / Beauftragung für Versicherer';

function field(string $key): string {
    $value = $_POST[$key] ?? '';
    if (is_array($value)) {
        $value = implode(', ', array_map('trim', $value));
    }
    $value = trim((string)$value);
    $value = str_replace(["\r", "\n"], ' ', $value);
    return $value;
}

function multiline(string $key): string {
    $value = trim((string)($_POST[$key] ?? ''));
    return str_replace(["\r\n", "\r"], "\n", $value);
}

function list_field(string $key): string {
    $value = $_POST[$key] ?? [];
    if (!is_array($value)) {
        $value = [$value];
    }
    $clean = [];
    foreach ($value as $item) {
        $item = trim(strip_tags((string)$item));
        if ($item !== '') {
            $clean[] = $item;
        }
    }
    return $clean ? implode(', ', $clean) : 'Keine Angabe';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: https://www.sv-netzwerk.eu/versicherer/');
    exit;
}

// Honeypot gegen einfache Spam-Bots.
if (!empty($_POST['website'] ?? '')) {
    header('Location: https://www.sv-netzwerk.eu/versicherer/?gesendet=1#anfrage');
    exit;
}

$vorgangsart = field('Vorgangsart');
$auftraggeber = field('Auftraggeber');
$ansprechpartner = field('Ansprechpartner');
$email = field('E-Mail');
$telefon = field('Telefon');
$schadennummer = field('Schadennummer');
$schadenort = field('Schadenort');
$kurzbeschreibung = multiline('Kurzbeschreibung');
$schadenbereich = list_field('Schadenbereich');
$schadenart = list_field('Schadenart');

$errors = [];
foreach ([
    'Vorgangsart' => $vorgangsart,
    'Auftraggeber' => $auftraggeber,
    'Ansprechpartner' => $ansprechpartner,
    'E-Mail' => $email,
    'Schadennummer' => $schadennummer,
    'Schadenort' => $schadenort,
    'Kurzbeschreibung' => $kurzbeschreibung,
] as $label => $value) {
    if (trim($value) === '') {
        $errors[] = $label;
    }
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'gueltige E-Mail';
}

if ($errors) {
    header('Location: https://www.sv-netzwerk.eu/versicherer/?fehler=1#anfrage');
    exit;
}

$body = "Neue Anfrage / Beauftragung über sv-netzwerk.eu\n\n";
$body .= "Vorgangsart: {$vorgangsart}\n";
$body .= "Auftraggeber: {$auftraggeber}\n";
$body .= "Ansprechpartner: {$ansprechpartner}\n";
$body .= "E-Mail: {$email}\n";
$body .= "Telefon: " . ($telefon !== '' ? $telefon : 'Keine Angabe') . "\n";
$body .= "Schadennummer: {$schadennummer}\n";
$body .= "Schadenort: {$schadenort}\n\n";
$body .= "Schadenbereich: {$schadenbereich}\n";
$body .= "Schadenart: {$schadenart}\n\n";
$body .= "Kurzbeschreibung:\n{$kurzbeschreibung}\n\n";
$body .= "---\n";
$body .= "Gesendet am: " . date('d.m.Y H:i:s') . "\n";
$body .= "IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unbekannt') . "\n";

$headers = [];
$headers[] = 'From: SV-Netzwerk Formular <' . $from . '>';
$headers[] = 'Reply-To: ' . $ansprechpartner . ' <' . $email . '>';
$headers[] = 'MIME-Version: 1.0';
$headers[] = 'Content-Type: text/plain; charset=UTF-8';
$headers[] = 'X-Mailer: PHP/' . phpversion();

$sent = mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers), '-f' . $from);

if ($sent) {
    header('Location: https://www.sv-netzwerk.eu/versicherer/?gesendet=1#anfrage');
    exit;
}

header('Location: https://www.sv-netzwerk.eu/versicherer/?fehler=mail#anfrage');
exit;
