<?php
declare(strict_types=1);
require_once __DIR__ . '/lib.php';
commonHeaders();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') oauthError('invalid_request', 'POST erforderlich.', 405);
oauthSchema();
$grant = (string)($_POST['grant_type'] ?? '');
$clientId = trim((string)($_POST['client_id'] ?? ''));
$client = oauthClient($clientId);
if (!$client) oauthError('invalid_client', 'OAuth-Client ist ungültig.', 401);
if ($grant === 'refresh_token') {
    $refresh = trim((string)($_POST['refresh_token'] ?? ''));
    $stmt = db()->prepare('SELECT * FROM oauth_refresh_tokens WHERE token_hash=:hash AND client_id=:client AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP() LIMIT 1');
    $stmt->execute([':hash'=>hash('sha256',$refresh), ':client'=>$clientId]);
    $row = $stmt->fetch();
    if (!$row) oauthError('invalid_grant', 'Die dauerhafte Verbindung ist ungültig oder abgelaufen.');
    $requestedResource = trim((string)($_POST['resource'] ?? ''));
    if ($requestedResource !== '' && !hash_equals((string)$row['resource_uri'], $requestedResource)) oauthError('invalid_target', 'Ungültige MCP-Ressource.');
    $access = oauthToken(48);
    db()->beginTransaction();
    try {
        db()->prepare('INSERT INTO oauth_access_tokens(token_hash,client_id,user_id,resource_uri,scope,expires_at,created_at) VALUES(:hash,:client,:user,:resource,:scope,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 8 HOUR),UTC_TIMESTAMP())')
          ->execute([':hash'=>hash('sha256',$access),':client'=>$clientId,':user'=>(int)$row['user_id'],':resource'=>(string)$row['resource_uri'],':scope'=>(string)$row['scope']]);
        db()->prepare('UPDATE oauth_refresh_tokens SET last_used_at=UTC_TIMESTAMP() WHERE token_hash=:hash')->execute([':hash'=>hash('sha256',$refresh)]);
        db()->commit();
    } catch (Throwable) {
        if (db()->inTransaction()) db()->rollBack();
        oauthError('invalid_grant', 'Die Verbindung konnte nicht erneuert werden.');
    }
    oauthJson(['access_token'=>$access,'token_type'=>'Bearer','expires_in'=>28800,'scope'=>(string)$row['scope'],'resource'=>(string)$row['resource_uri']]);
}
if ($grant !== 'authorization_code') oauthError('unsupported_grant_type', 'Unterstützt werden authorization_code und refresh_token.');
$code = trim((string)($_POST['code'] ?? ''));
$redirectUri = trim((string)($_POST['redirect_uri'] ?? ''));
$resource = trim((string)($_POST['resource'] ?? ''));
$verifier = trim((string)($_POST['code_verifier'] ?? ''));
if (!$client || !oauthRedirectAllowed($client, $redirectUri)) oauthError('invalid_client', 'OAuth-Client ist ungültig.', 401);
$stmt = db()->prepare('SELECT * FROM oauth_authorization_codes WHERE code_hash=:hash AND client_id=:client AND used_at IS NULL AND expires_at>UTC_TIMESTAMP() LIMIT 1');
$stmt->execute([':hash'=>hash('sha256',$code), ':client'=>$clientId]);
$row = $stmt->fetch();
if (!$row || !hash_equals((string)$row['redirect_uri'],$redirectUri) || !hash_equals((string)$row['resource_uri'],$resource)) oauthError('invalid_grant', 'Autorisierungscode ist ungültig oder abgelaufen.');
$calculated = rtrim(strtr(base64_encode(hash('sha256',$verifier,true)), '+/', '-_'), '=');
if (!hash_equals((string)$row['code_challenge'],$calculated)) oauthError('invalid_grant', 'PKCE-Prüfung fehlgeschlagen.');
db()->beginTransaction();
try {
    $updated = db()->prepare('UPDATE oauth_authorization_codes SET used_at=UTC_TIMESTAMP() WHERE code_hash=:hash AND used_at IS NULL');
    $updated->execute([':hash'=>hash('sha256',$code)]);
    if ($updated->rowCount() !== 1) throw new RuntimeException('Code bereits verwendet.');
    $access = oauthToken(48);
    $refresh = oauthToken(64);
    db()->prepare('INSERT INTO oauth_access_tokens(token_hash,client_id,user_id,resource_uri,scope,expires_at,created_at) VALUES(:hash,:client,:user,:resource,:scope,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 8 HOUR),UTC_TIMESTAMP())')
      ->execute([':hash'=>hash('sha256',$access),':client'=>$clientId,':user'=>(int)$row['user_id'],':resource'=>$resource,':scope'=>(string)$row['scope']]);
    db()->prepare('INSERT INTO oauth_refresh_tokens(token_hash,client_id,user_id,resource_uri,scope,expires_at,created_at) VALUES(:hash,:client,:user,:resource,:scope,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 90 DAY),UTC_TIMESTAMP())')
      ->execute([':hash'=>hash('sha256',$refresh),':client'=>$clientId,':user'=>(int)$row['user_id'],':resource'=>$resource,':scope'=>(string)$row['scope']]);
    db()->commit();
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    oauthError('invalid_grant', 'Autorisierungscode konnte nicht eingelöst werden.');
}
oauthJson(['access_token'=>$access,'refresh_token'=>$refresh,'token_type'=>'Bearer','expires_in'=>28800,'scope'=>(string)$row['scope'],'resource'=>$resource]);
