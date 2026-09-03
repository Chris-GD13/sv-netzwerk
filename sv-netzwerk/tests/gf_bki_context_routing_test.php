<?php
declare(strict_types=1);

$source = file_get_contents(__DIR__.'/../public/intern/api/gf-ai-generate.php');
if (!is_string($source) || $source === '') {
    throw new RuntimeException('KI-Runtime konnte nicht gelesen werden.');
}

$calculationOnly = <<<'PHP_CODE'
$calculationBkiReplacement = '$bkiRequested=in_array(\'kalkulation\',$outputs,true);';
PHP_CODE;
$instructionTriggered = <<<'PHP_CODE'
$calculationBkiReplacement = '$bkiRequested=in_array(\'kalkulation\',$outputs,true)||preg_match(\'/\\bBKI\\b/ui\',$instructions)===1;';
PHP_CODE;

if (!str_contains($source, $calculationOnly)) {
    throw new RuntimeException('BKI-Vollquellen sind nicht auf Kalkulationsausgaben begrenzt.');
}
if (str_contains($source, $instructionTriggered)) {
    throw new RuntimeException('Eine bloße BKI-Erwähnung lädt weiterhin die vollständigen BKI-Quellen.');
}

echo "GF BKI context routing tests passed\n";
