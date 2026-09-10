<?php
declare(strict_types=1);

$core = file_get_contents(__DIR__ . '/../public/intern/api/gf-ai-generate-core.php');
if (!is_string($core)) {
    throw new RuntimeException('GF generator core could not be read.');
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(
    str_contains($core, 'VERBINDLICHE FORTSCHREIBUNGSREGEL FÜR ZWISCHENBERICHTE'),
    'The interim-report continuation rule is missing.'
);
$assert(
    str_contains($core, 'Eine E-Mail, ein Kostenvoranschlag oder die bloße Nennung einer Person belegt weder eine Besprechung noch eine Teilnahme.'),
    'Email senders and mentioned people must not become meeting participants.'
);
$assert(
    str_contains($core, 'Unterscheide strikt zwischen bislang gezahlt, neu befürwortet, Kostenprognose, Kostenvoranschlag und Reserve.'),
    'Payment, forecast, quote, and reserve values must remain distinct.'
);
$assert(
    str_contains($core, "if(str_contains(gfNorm(\$heading),'reserve'))\$body=trim((string)(\$meta['reserve']??\$body));"),
    'The DOCX reserve must stay bound to the manually confirmed case reserve.'
);
$assert(
    str_contains($core, "if(count(\$sections)===count(\$expected)){foreach(\$expected as\$i=>\$heading){\$sections[\$i]['heading']=\$heading;}"),
    'A complete AI result must be normalized to the exact approved template headings before Engel validation.'
);
$assert(
    str_contains($core, 'function gfDocxFillTableRow(')
    && str_contains($core, "'Letzte Besprechung am'=>(string)(\$form['letzte_besprechung_am']??'')")
    && str_contains($core, "'Mietausfall'=>'mietausfall'"),
    'Interim-report meeting date and payment table must be transferred to the Word template.'
);
$assert(
    str_contains($core, 'keine Zahlungsempfehlung als bereits gezahlt ausgeben'),
    'Recommended payments must not be written into the already-paid column.'
);

echo "gf_interim_report_continuity_test: ok\n";
