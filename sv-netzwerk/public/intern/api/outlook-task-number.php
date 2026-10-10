<?php
declare(strict_types=1);

function otCaseNumber(string $text): string {
    $text = str_replace(["\u{00AD}", "\u{2010}", "\u{2011}", "\u{2012}", "\u{2013}", "\u{2014}", "\u{2015}", "\u{2212}"], '-', $text);
    $space = '[\h]*';
    $separator = '(?:[\h]*-[\h]*|[\h]+)';
    $pattern = '/(?<![\p{L}\p{N}-])(\d{2})' . $space . '-' . $space
        . '(\d{6,7}|\d{3}' . $separator . '\d{3,4})'
        . '(?:' . $space . '-' . $space . '(\d{1,2}))?'
        . '(?![\p{L}\p{N}]|' . $space . '-[\h]*\d)/u';
    if (!preg_match($pattern, $text, $match)) return '';
    $serial = preg_replace('/[\h-]+/u', '', $match[2]);
    return $match[1] . '-' . $serial . (isset($match[3]) && $match[3] !== '' ? '-' . $match[3] : '');
}
