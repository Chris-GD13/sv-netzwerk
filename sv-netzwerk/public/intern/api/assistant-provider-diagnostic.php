<?php
declare(strict_types=1);
function svAssistantDiagnostic(array $detail): string {
    $parts=[];foreach(['code','type','param'] as $field){$value=$detail[$field]??null;if(is_string($value)&&preg_match('/^[a-zA-Z0-9_.-]{1,100}$/',$value))$parts[]=$field.'='.$value;}
    return implode(', ',$parts);
}
