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
    $isChatGpt = ($parts['scheme'] ?? '') === 'https'
        && in_array($host, ['chatgpt.com','www.chatgpt.com'], true);
    $isCodexLoopback = ($parts['scheme'] ?? '') === 'http'
        && in_array($host, ['127.0.0.1','localhost'], true)
        && isset($parts['port']) && (int)$parts['port'] > 0
        && preg_match('#^/callback(?:/[A-Za-z0-9_-]{12})?$#', (string)($parts['path'] ?? '')) === 1
        && !isset($parts['query']) && !isset($parts['fragment'])
        && !isset($parts['user']) && !isset($parts['pass']);
    if (!$isChatGpt && !$isCodexLoopback) {
        oauthError('invalid_redirect_uri', 'Zulässig sind ausschließlich ChatGPT-HTTPS-Callbacks oder lokale Codex-PKCE-Callbacks.');
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
