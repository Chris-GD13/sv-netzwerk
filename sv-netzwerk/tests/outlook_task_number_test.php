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
];
foreach ($examples as $text => $expected) {
    $actual = otCaseNumber($text);
    if ($actual !== $expected) throw new RuntimeException("$text: expected '$expected', got '$actual'");
}
echo "Outlook damage-number formats: " . count($examples) . " cases passed\n";
