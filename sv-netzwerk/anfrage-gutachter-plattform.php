<?php
declare(strict_types=1);

$to = 'info@gutachter-plattform.de';
$from = 'info@sv-netzwerk.eu';

function clean_text(string $value, bool $singleLine = true): string {
    $value = strip_tags(trim($value));
    $value = str_replace(["
", ""], "
", $value);
    if ($singleLine) {
        $value = str_replace("
", ' ', $value);
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

$return = 'https://www.sv-netzwerk.eu/gutachter-plattform/anfrage/';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $return);
    exit;
}

if (!empty($_POST['website'] ?? '')) {
    header('Location: ' . $return . '?gesendet=1');
    exit;
}

$formular = post_value('Formular');
$herkunft = post_value('Herkunft');
$name = post_value('Name');
$unternehmen = post_value('Unternehmen');
$rolle = post_value('Rolle');
$email = post_value('E-Mail');
$telefon = post_value('Telefon');
$thema = post_value('Thema');
$nachricht = post_value('Nachricht', false);
$datenschutz = post_value('Datenschutz');

$errors = [];
foreach (['Name' => $name, 'E-Mail' => $email, 'Rolle' => $rolle, 'Thema' => $thema, 'Nachricht' => $nachricht, 'Datenschutz' => $datenschutz] as $label => $value) {
    if (trim($value) === '') {
        $errors[] = $label;
    }
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'gueltige E-Mail';
}

if ($errors) {
    header('Location: ' . $return . '?fehler=1');
    exit;
}

$subject = 'Gutachter-Plattform Anfrage über SV-Netzwerk - ' . ($unternehmen !== '' ? $unternehmen : $name);
$body = "Neue Anfrage zur Gutachter-Plattform

";
$body .= "WICHTIGER HERKUNFTS- UND PROVISIONSHINWEIS:
";
$body .= "Diese Kontaktanfrage wurde über sv-netzwerk.eu / SV-Netzwerk vermittelt. Bitte bei der weiteren Bearbeitung und Zuordnung entsprechend berücksichtigen.

";
$body .= "Empfänger / Plattformbetreiber:
";
$body .= "GASD GmbH, vertreten durch den Geschäftsführer Goran Krstic
";
$body .= "Hauptstr. 7, 76571 Gaggenau, Deutschland
";
$body .= "E-Mail: info@gutachter-plattform.de

";
$body .= "Formular: " . ($formular !== '' ? $formular : 'Gutachter-Plattform Anfrage') . "
";
$body .= "Herkunft: " . ($herkunft !== '' ? $herkunft : 'SV-Netzwerk.eu') . "
";
$body .= "Name: {$name}
";
$body .= "Unternehmen / Organisation: " . ($unternehmen !== '' ? $unternehmen : 'Keine Angabe') . "
";
$body .= "Rolle / Zielgruppe: {$rolle}
";
$body .= "E-Mail: {$email}
";
$body .= "Telefon: " . ($telefon !== '' ? $telefon : 'Keine Angabe') . "
";
$body .= "Thema: {$thema}

";
$body .= "Nachricht:
{$nachricht}

";
$body .= "---
";
$body .= "Gesendet über: https://www.sv-netzwerk.eu/gutachter-plattform/anfrage/
";
$body .= "Gesendet am: " . date('d.m.Y H:i:s') . "
";
$body .= "IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unbekannt') . "
";

$headers = [];
$headers[] = 'From: SV-Netzwerk Plattform-Anfrage <' . $from . '>';
$headers[] = 'Reply-To: ' . $name . ' <' . $email . '>';
$headers[] = 'MIME-Version: 1.0';
$headers[] = 'Content-Type: text/plain; charset=UTF-8';
$headers[] = 'X-Mailer: PHP/' . phpversion();

$sent = mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("
", $headers), '-f' . $from);

if ($sent) {
    header('Location: ' . $return . '?gesendet=1');
    exit;
}

header('Location: ' . $return . '?fehler=mail');
exit;
