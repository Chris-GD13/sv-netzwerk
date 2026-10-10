<?php
declare(strict_types=1);

/** Describe provider failures without exposing credentials or private response text. */
function kvaOpenAiFailure(array $response, string $fallback): RuntimeException
{
    $body = json_decode((string)($response['body'] ?? ''), true);
    $error = is_array($body['error'] ?? null) ? $body['error'] : [];
    $code = strtolower(trim((string)($error['code'] ?? '')));
    $status = (int)($response['status'] ?? 0);
    $message = match ($code) {
        'account_deactivated' => 'Die KVA-Auswertung ist derzeit nicht verfügbar: Das OpenAI-Konto des hinterlegten API-Zugangs ist deaktiviert (account_deactivated). Bitte den API-Kontozugang wiederherstellen. Ein erneuter Datei-Upload behebt diesen Zugangsfehler nicht.',
        'invalid_api_key' => 'Die KVA-Auswertung ist derzeit nicht verfügbar: Der hinterlegte OpenAI-API-Zugang ist ungültig. Bitte die Serverkonfiguration des API-Zugangs prüfen.',
        'credit_balance_exhausted' => 'Die KVA-Auswertung ist derzeit nicht verfügbar: Das OpenAI-API-Guthaben ist aufgebraucht. Bitte im zugehörigen API-Konto Guthaben nachladen.',
        'insufficient_quota' => 'Die KVA-Auswertung ist derzeit nicht verfügbar: Das OpenAI-API-Guthaben oder Nutzungslimit ist ausgeschöpft. Bitte Guthaben und Limits des API-Kontos prüfen.',
        'rate_limit_exceeded' => 'Die KVA-Auswertung ist vorübergehend nicht verfügbar: Das OpenAI-Anfragelimit wurde erreicht. Bitte später erneut prüfen.',
        default => match (true) {
            $status === 401 || $status === 403 => 'Die KVA-Auswertung ist derzeit nicht verfügbar: OpenAI hat den hinterlegten API-Zugang abgewiesen (HTTP '.$status.'). Bitte dessen Berechtigung prüfen.',
            $status === 429 => 'Die KVA-Auswertung ist vorübergehend nicht verfügbar: OpenAI hat die Anfrage begrenzt (HTTP 429). Bitte API-Limits prüfen.',
            $status >= 500 => 'Die KVA-Auswertung ist vorübergehend nicht verfügbar: Der OpenAI-Dienst meldet eine Störung (HTTP '.$status.'). Bitte später erneut prüfen.',
            default => $fallback.($status >= 400 ? ' Der Dokumentendienst hat die Anfrage abgewiesen (HTTP '.$status.').' : ''),
        },
    };
    return new RuntimeException($message, 503);
}
