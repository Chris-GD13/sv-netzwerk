<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
commonHeaders();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') oauthError('invalid_request', 'POST erforderlich.', 405);
oauthSchema();
$body = requestBody();
$uris = is_array($body['redirect_uris'] ?? null) ? array_values(array_unique(array_map('strval', $body['redirect_uris']))) : [];
if (!$uris || count($uris) > 10) oauthError('invalid_redirect_uri', 'Mindestens eine Redirect-URI ist erforderlich.');
foreach ($uris as $uri) {
    $parts = parse_url($uri);
    $host = strtolower((string)($parts['host'] ?? ''));
    if (($parts['scheme'] ?? '') !== 'https' || !in_array($host, ['chatgpt.com','www.chatgpt.com'], true)) {
        oauthError('invalid_redirect_uri', 'Für diese interne Anbindung sind ausschließlich HTTPS-Callbacks von ChatGPT zulässig.');
    }
}
$method = (string)($body['token_endpoint_auth_method'] ?? 'none');
if ($method !== 'none') oauthError('invalid_client_metadata', 'Nur öffentliche PKCE-Clients werden unterstützt.');
$clientId = 'svnet_'.oauthToken(24);
$name = trim((string)($body['client_name'] ?? 'ChatGPT Work')) ?: 'ChatGPT Work';
db()->prepare('INSERT INTO oauth_clients(client_id,client_name,redirect_uris_json,created_at) VALUES(:id,:name,:uris,UTC_TIMESTAMP())')
    ->execute([':id'=>$clientId, ':name'=>mb_substr($name,0,255), ':uris'=>json_encode($uris, JSON_UNESCAPED_SLASHES)]);
oauthJson([
    'client_id'=>$clientId,
    'client_id_issued_at'=>time(),
    'client_name'=>$name,
    'redirect_uris'=>$uris,
    'grant_types'=>['authorization_code','refresh_token'],
    'response_types'=>['code'],
    'token_endpoint_auth_method'=>'none',
]);
