<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$page = file_get_contents($root . '/src/pages/intern/versicherungsfaelle/index.astro');
$extractor = file_get_contents($root . '/public/intern/api/insurance-case-extract.php');

if ($page === false || $extractor === false) {
    fwrite(STDERR, "Portaldateien konnten nicht gelesen werden.\n");
    exit(1);
}

$uiChecks = [
    'VN-Telefon' => 'Telefonfeld ist nicht eindeutig dem VN zugeordnet.',
    'VN-Mobil' => 'Mobilfeld für den VN fehlt.',
    'VN-E-Mail' => 'E-Mail-Feld ist nicht eindeutig dem VN zugeordnet.',
    'Kontaktdaten des Sanierers' => 'Eigener Sanierer-Kontaktblock fehlt.',
    'id="vf-sanierer-firma"' => 'Sanierer-Firma fehlt.',
    'id="vf-sanierer-ansprechpartner"' => 'Sanierer-Ansprechpartner fehlt.',
    'id="vf-sanierer-funktion"' => 'Funktion des Sanierer-Ansprechpartners fehlt.',
    'id="vf-sanierer-telefon"' => 'Sanierer-Telefon fehlt.',
    'id="vf-sanierer-mobil"' => 'Sanierer-Mobil fehlt.',
    'id="vf-sanierer-email"' => 'Sanierer-E-Mail fehlt.',
    "sanierer_firma:'vf-sanierer-firma'" => 'Sanierer-Firma wird nicht gespeichert.',
    "sanierer_ansprechpartner:'vf-sanierer-ansprechpartner'" => 'Sanierer-Ansprechpartner wird nicht gespeichert.',
    "sanierer_funktion:'vf-sanierer-funktion'" => 'Sanierer-Funktion wird nicht gespeichert.',
    "sanierer_telefon:'vf-sanierer-telefon'" => 'Sanierer-Telefon wird nicht gespeichert.',
    "sanierer_mobil:'vf-sanierer-mobil'" => 'Sanierer-Mobil wird nicht gespeichert.',
    "sanierer_email:'vf-sanierer-email'" => 'Sanierer-E-Mail wird nicht gespeichert.',
];

foreach ($uiChecks as $needle => $message) {
    if (!str_contains($page, $needle)) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$extractorChecks = [
    'telefon, mobil und email sind ausschließlich Kontaktdaten des Versicherungsnehmers' => 'VN-Zuordnungsregel fehlt.',
    'Niemals Kontaktdaten des Versicherers, der Sparkassenversicherung, eines Regulierers' => 'Ausschluss fremder Kontakte fehlt.',
    'Ist die Zuordnung zum VN nicht eindeutig belegt, telefon, mobil und email leer lassen.' => 'Leerlassen-Regel bei unklarer Zuordnung fehlt.',
    'Sanierer-Kontaktdaten ausschließlich in die sanierer_*-Felder eintragen.' => 'Sanierer-Zuordnungsregel fehlt.',
    "'sanierer_firma'" => 'Sanierer-Firma wird von der API nicht ausgegeben.',
    "'sanierer_ansprechpartner'" => 'Sanierer-Ansprechpartner wird von der API nicht ausgegeben.',
    "'sanierer_funktion'" => 'Sanierer-Funktion wird von der API nicht ausgegeben.',
    "'sanierer_telefon'" => 'Sanierer-Telefon wird von der API nicht ausgegeben.',
    "'sanierer_mobil'" => 'Sanierer-Mobil wird von der API nicht ausgegeben.',
    "'sanierer_email'" => 'Sanierer-E-Mail wird von der API nicht ausgegeben.',
];

foreach ($extractorChecks as $needle => $message) {
    if (!str_contains($extractor, $needle)) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

echo "VN- und Sanierer-Kontaktfelder sind getrennt und vollständig verdrahtet.\n";
