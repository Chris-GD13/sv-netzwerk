<?php
declare(strict_types=1);

function otCaseNumber(string $text): string {
    $text = str_replace(["\u{00AD}", "\u{2010}", "\u{2011}", "\u{2012}", "\u{2013}", "\u{2014}", "\u{2015}", "\u{2212}"], '-', $text);
    $space = '[\h]*';
    $separator = '(?:[\h]*-[\h]*|[\h]+)';
    $legacy = '(\d{2})' . $space . '-' . $space
        . '(\d{6,7}|\d{3}' . $separator . '\d{3,4})'
        . '(?:' . $space . '-' . $space . '(\d{1,2}))?';
    $multiPart = '\d{2,4}(?:' . $space . '-' . $space . '\d{2,8}){2,5}'
        . $space . '-' . $space . '\d{1,8}';
    $start = '(?<![\p{L}\p{N}+\-\/])';
    $end = '(?![\p{L}\p{N}]|' . $space . '-[\h]*\d)';
    $label = '(?:Schaden(?:s)?' . $space . '(?:-' . $space . ')?(?:Nr\.?|Nummer)|Schaden)'
        . $space . '[:#]?' . $space;

    // An explicit damage-number label takes precedence over unrelated references.
    if (preg_match('/' . $label . '(' . $multiPart . '|' . $legacy . ')' . $end . '/iu', $text, $match)) {
        $candidate = $match[1];
    } else {
        if (!preg_match_all('/' . $start . '(?:' . $legacy . '|' . $multiPart . ')' . $end . '/u', $text, $matches, PREG_OFFSET_CAPTURE)) return '';
        $candidate = '';
        foreach ($matches[0] as [$number, $offset]) {
            $prefix = substr($text, 0, $offset);
            if (preg_match('/(?:Tel(?:efon)?\.?|Mobil|Fax|Rechnung(?:snummer)?|Auftrags-ID|Aktenzeichen)[\h]*[:#]?[\h]*$/iu', $prefix)) continue;
            $candidate = $number;
            break;
        }
        if ($candidate === '') return '';
    }
    if (preg_match('/^' . $legacy . '$/u', $candidate, $match)) {
        $serial = preg_replace('/[\h-]+/u', '', $match[2]);
        return $match[1] . '-' . $serial . (isset($match[3]) && $match[3] !== '' ? '-' . $match[3] : '');
    }
    return preg_replace('/[\h]*-[\h]*/u', '-', $candidate);
}
