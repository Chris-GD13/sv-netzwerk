<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
commonHeaders();
oauthSchema();

$input = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$clientId = trim((string)($input['client_id'] ?? ''));
$redirectUri = trim((string)($input['redirect_uri'] ?? ''));
$state = (string)($input['state'] ?? '');
$resource = trim((string)($input['resource'] ?? ''));
$scope = oauthNormalizeScope((string)($input['scope'] ?? 'cases:read'));
$challenge = trim((string)($input['code_challenge'] ?? ''));
$method = (string)($input['code_challenge_method'] ?? '');
$client = oauthClient($clientId);
if (!$client || !oauthRedirectAllowed($client, $redirectUri)) oauthError('invalid_client', 'OAuth-Client oder Redirect-URI ist ungültig.');
if (!hash_equals(SV_MCP_RESOURCE, $resource)) oauthSafeRedirect($redirectUri, ['error'=>'invalid_target','error_description'=>'Ungültige MCP-Ressource.','state'=>$state,'iss'=>SV_OAUTH_ISSUER]);
if (($input['response_type'] ?? '') !== 'code' || $method !== 'S256' || !preg_match('/^[A-Za-z0-9_-]{43,128}$/', $challenge)) {
    oauthSafeRedirect($redirectUri, ['error'=>'invalid_request','error_description'=>'Authorization Code mit PKCE S256 erforderlich.','state'=>$state,'iss'=>SV_OAUTH_ISSUER]);
}
$returnPath = '/intern/oauth/authorize.php?'.http_build_query($_GET);
$user = currentUser();
if (!$user) {
    header('Location: /intern/login/?'.http_build_query(['return'=>$returnPath]));
    exit;
}

startSession();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string)($_SESSION['oauth_csrf'] ?? ''), (string)($input['csrf'] ?? ''))) oauthError('invalid_request', 'Sicherheitsprüfung fehlgeschlagen.', 403);
    if (($input['decision'] ?? '') !== 'allow') oauthSafeRedirect($redirectUri, ['error'=>'access_denied','state'=>$state,'iss'=>SV_OAUTH_ISSUER]);
    $code = oauthToken(36);
    db()->prepare('INSERT INTO oauth_authorization_codes(code_hash,client_id,user_id,redirect_uri,resource_uri,scope,code_challenge,expires_at,created_at) VALUES(:hash,:client,:user,:redirect,:resource,:scope,:challenge,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE),UTC_TIMESTAMP())')
      ->execute([':hash'=>hash('sha256',$code),':client'=>$clientId,':user'=>(int)$user['id'],':redirect'=>$redirectUri,':resource'=>$resource,':scope'=>$scope,':challenge'=>$challenge]);
    db()->prepare('UPDATE oauth_clients SET last_used_at=UTC_TIMESTAMP() WHERE client_id=:id')->execute([':id'=>$clientId]);
    unset($_SESSION['oauth_csrf']);
    oauthSafeRedirect($redirectUri, ['code'=>$code,'state'=>$state,'iss'=>SV_OAUTH_ISSUER]);
}

$_SESSION['oauth_csrf'] = oauthToken(24);
$safeName = htmlspecialchars((string)$client['client_name'], ENT_QUOTES, 'UTF-8');
$safeUser = htmlspecialchars((string)($user['full_name'] ?? $user['email']), ENT_QUOTES, 'UTF-8');
?><!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ChatGPT Work verbinden</title><style>body{margin:0;background:#f3f5f7;color:#18324a;font:16px/1.45 Arial,sans-serif}.box{max-width:620px;margin:8vh auto;background:#fff;border:1px solid #ccd7e0;padding:32px}h1{margin-top:0}.scope{background:#eef3f6;border-left:5px solid #ff9700;padding:14px;margin:22px 0}button{padding:12px 20px;border:1px solid #18324a;background:#18324a;color:#fff;font-weight:700;margin-right:10px}.deny{background:#fff;color:#18324a}</style></head><body><main class="box"><p style="color:#d97800;font-weight:700">SV-NETZWERK PRÜFPORTAL</p><h1>ChatGPT Work verbinden</h1><p><strong><?= $safeName ?></strong> möchte auf das Prüfportal als <strong><?= $safeUser ?></strong> zugreifen.</p><div class="scope"><strong>Freigabe:</strong><br>Eigene Versicherungsfälle suchen und die zugehörigen Falldaten lesen. Fälle anderer Benutzer bleiben gesperrt.</div><form method="post"><?php foreach ($input as $key=>$value) { if (is_scalar($value) && $key!=='csrf' && $key!=='decision') echo '<input type="hidden" name="'.htmlspecialchars((string)$key,ENT_QUOTES,'UTF-8').'" value="'.htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8').'">'; } ?><input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['oauth_csrf'],ENT_QUOTES,'UTF-8') ?>"><button name="decision" value="allow">Verbinden</button><button class="deny" name="decision" value="deny">Ablehnen</button></form></main></body></html>
