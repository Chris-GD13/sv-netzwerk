<?php
declare(strict_types=1);

$to = 'info@sv-netzwerk.eu';
$from = 'info@sv-netzwerk.eu';

function clean_text(string $value, bool $singleLine = true): string {
    $value = strip_tags(trim($value));
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    if ($singleLine) {
        $value = str_replace("\n", ' ', $value);
    }
    return $value;
}

function post_value(string $key, bool $singleLine = true): string {
    $value = $_POST[$key] ?? '';
    if (is_array($value)) {
        $value = implode(', ', array_map('trim', $value));
    }
    return clean_text((string)$value, $singleLine);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: https://www.sv-netzwerk.eu/kontakt/');
    exit;
}

if (!empty($_POST['website'] ?? '')) {
    header('Location: https://www.sv-netzwerk.eu/kontakt/?gesendet=1');
    exit;
}

$formular = post_value('Formular');
$name = post_value('Name');
$email = post_value('E-Mail');
$telefon = post_value('Telefon');
$thema = post_value('Thema');
$nachricht = post_value('Nachricht', false);

$errors = [];
foreach (['Name' => $name, 'E-Mail' => $email, 'Thema' => $thema, 'Nachricht' => $nachricht] as $label => $value) {
    if (trim($value) === '') {
        $errors[] = $label;
    }
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'gueltige E-Mail';
}

$return = ($formular === 'Terminvereinbarung') ? 'https://www.sv-netzwerk.eu/termin-vereinbaren/' : 'https://www.sv-netzwerk.eu/kontakt/';
if ($errors) {
    header('Location: ' . $return . '?fehler=1');
    exit;
}

$subject = 'SV-Netzwerk: ' . ($formular !== '' ? $formular : 'Kontaktanfrage');
$body = "Neue Anfrage ueber sv-netzwerk.eu\n\n";
$body .= "Formular: " . ($formular !== '' ? $formular : 'Kontaktformular') . "\n";
$body .= "Name / Auftraggeber: {$name}\n";
$body .= "E-Mail: {$email}\n";
$body .= "Telefon: " . ($telefon !== '' ? $telefon : 'Keine Angabe') . "\n";
$body .= "Thema: {$thema}\n\n";
$body .= "Nachricht:\n{$nachricht}\n\n";
$body .= "---\n";
$body .= "Gesendet am: " . date('d.m.Y H:i:s') . "\n";
$body .= "IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unbekannt') . "\n";

$headers = [];
$headers[] = 'From: SV-Netzwerk Formular <' . $from . '>';
$headers[] = 'Reply-To: ' . $name . ' <' . $email . '>';
$headers[] = 'MIME-Version: 1.0';
$headers[] = 'Content-Type: text/plain; charset=UTF-8';
$headers[] = 'X-Mailer: PHP/' . phpversion();

$sent = mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers), '-f' . $from);

if ($sent) {
    header('Location: ' . $return . '?gesendet=1');
    exit;
}

header('Location: ' . $return . '?fehler=mail');
exit;
