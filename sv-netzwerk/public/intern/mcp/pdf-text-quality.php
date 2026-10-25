<?php
declare(strict_types=1);
function mcpPdfTextUsable(string $text): bool {
    $visible=preg_replace('/\s+/u','',$text);
    if($visible===null||mb_strlen($visible)<40)return false;
    // A small amount of broken font mapping already corrupts prices and words.
    // Normal tabs, line breaks and PDF page form-feeds are allowed.
    $controls=preg_match_all('/[\x00-\x08\x0B\x0E-\x1F\x7F]/',$text);
    return $controls<=max(2,mb_strlen($visible)*0.005);
}
