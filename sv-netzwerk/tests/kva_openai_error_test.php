<?php
declare(strict_types=1);
require_once __DIR__.'/../public/intern/api/kva-openai-error.php';

function checkProviderError(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

foreach ([
    [401, 'account_deactivated', 'deaktiviert'],
    [401, 'invalid_api_key', 'ungültig'],
    [429, 'insufficient_quota', 'Guthaben'],
    [429, 'credit_balance_exhausted', 'Guthaben nachladen'],
    [429, 'rate_limit_exceeded', 'Anfragelimit'],
    [403, '', 'Berechtigung'],
    [503, '', 'Störung'],
    [400, 'unsupported_file', 'HTTP 400'],
] as [$status, $code, $expected]) {
    $failure = kvaOpenAiFailure(['status'=>$status, 'body'=>json_encode(['error'=>['code'=>$code,'message'=>'PRIVATE sk-secret file contents']])], 'KVA konnte nicht vorbereitet werden.');
    checkProviderError(str_contains($failure->getMessage(), $expected), 'Providerfehler nicht korrekt zugeordnet: '.$code);
    checkProviderError(!str_contains($failure->getMessage(), 'sk-secret') && !str_contains($failure->getMessage(), 'PRIVATE'), 'Provider-Rohtext darf nicht ausgegeben werden.');
    checkProviderError($failure->getCode() === 503, 'Externer Analysedienst muss als nicht verfügbar erkennbar sein.');
}
$malformed = kvaOpenAiFailure(['status'=>502, 'body'=>'<html>PRIVATE upstream</html>'], 'KVA fehlgeschlagen.');
checkProviderError(str_contains($malformed->getMessage(), 'Störung'), 'Nicht-JSON-Antwort muss sicher behandelt werden.');
echo "KVA-Providerfehler: Konto, Zugang, Limits und vertrauliche Fehlertexte geprüft.\n";
