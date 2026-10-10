<?php
declare(strict_types=1);
require __DIR__ . '/../public/intern/api/outlook-task-number.php';

$examples = [
    'Fwd: Schaden 26-165 840-0 / HC CAS-69406-F8F9P2' => '26-165840-0',
    'Schaden 26-165-840-0' => '26-165840-0',
    'Schaden 26-165840-0' => '26-165840-0',
    'Schaden 26 - 165 840 - 0' => '26-165840-0',
    "Schaden 26\u{2011}165\u{00A0}840\u{2011}0" => '26-165840-0',
    'Fwd: WG: Schadensnummer 61-1181377-91, Rechnung 2932-2026' => '61-1181377-91',
    'Schaden 61-118 1377-91' => '61-1181377-91',
    'Schaden 61-1181377' => '61-1181377',
    'Schaden 26-053198-8 (#7206947)' => '26-053198-8',
    "Signatur ohne Nummer\nSchaden-Nr.: 26-165 840-0" => '26-165840-0',
    'Tel: +49 (0)180 500 52 46' => '',
    'Rechnung 2932-2026 / HC CAS-69406-F8F9P2' => '',
    '2026-09-21' => '',
    '126-165840-0' => '',
    '26-16584000-0' => '',
    '61-1181377-912' => '',
    '26-165840-0-7' => '',
    'ABC26-165840-0XYZ' => '',
    'Fwd: Schaden-Nr: 00-031-404193-0001 / Aktenzeichen: 26/0351328 / Auftrags-ID: 450421' => '00-031-404193-0001',
    'Fwd: Schaden-Nr: 00-031-404193-0001 / Aktenzeichen: 26/0351328 / Auftrags-ID: 450421 (Ott Matthias)' => '00-031-404193-0001',
    'Fwd: Fwd: KUSS Service-Portal: Sie haben neue Unterlagen erhalten - 408-53-25000238-1' => '408-53-25000238-1',
    'R+V Allgemeine Versicherung AG 408-53-25000238-1' => '408-53-25000238-1',
    'Schaden-Nr.: 00 - 031 - 404193 - 0001' => '00-031-404193-0001',
    "Schaden-Nr.: 00\u{2011}031\u{2011}404193\u{2011}0001" => '00-031-404193-0001',
    'Schadensnummer: 123-45-67890123-0002' => '123-45-67890123-0002',
    'Unterlagen 123-45-67890123-0002' => '123-45-67890123-0002',
    'Unterlagen 00-031-404193-0002' => '00-031-404193-0002',
    "Schaden-Nr.: 00-031-404193-0001\nReferenz: 26-053198-8" => '00-031-404193-0001',
    "Referenz: 26-053198-8\nSchaden-Nr.: 00-031-404193-0001" => '00-031-404193-0001',
    'Tel: 0049-7367-393-9783' => '',
    'Fax: 0049-7367-393-9783' => '',
    'Auftrags-ID: 123-45-67890123-0002' => '',
    'Aktenzeichen: 123-45-67890123-0002' => '',
    'Rechnung: 123-45-67890123-0002' => '',
    'Keine Nummer, nur ein Name: Ott Matthias' => '',
];
foreach ($examples as $text => $expected) {
    $actual = otCaseNumber($text);
    if ($actual !== $expected) throw new RuntimeException("$text: expected '$expected', got '$actual'");
}
echo "Outlook damage-number formats: " . count($examples) . " cases passed\n";
