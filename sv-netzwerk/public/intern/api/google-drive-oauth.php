<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
commonHeaders();

function gdOauthEnsureTable(): void {
    db()->exec("CREATE TABLE IF NOT EXISTS app_settings (
        setting_key VARCHAR(190) PRIMARY KEY,
        setting_value MEDIUMTEXT NULL,
        updated_at DATETIME NOT NULL,
        updated_by VARCHAR(190) NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function gdSettingGet(string $key, string $default=''): string {
    gdOauthEnsureTable();
    $stmt=db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key=:k LIMIT 1');
    $stmt->execute([':k'=>$key]);
    $value=$stmt->fetchColumn();
    return $value===false ? $default : (string)$value;
}

function gdSettingSet(string $key, string $value, array $user): void {
    gdOauthEnsureTable();
    $stmt=db()->prepare('INSERT INTO app_settings(setting_key,setting_value,updated_at,updated_by)
        VALUES(:k,:v,:u,:b)
        ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=VALUES(updated_at),updated_by=VALUES(updated_by)');
    $stmt->execute([':k'=>$key,':v'=>$value,':u'=>nowUtc(),':b'=>(string)($user['email']??$user['full_name']??'')]);
}

function gdSettingDelete(string $key): void {
    gdOauthEnsureTable();
    db()->prepare('DELETE FROM app_settings WHERE setting_key=:k')->execute([':k'=>$key]);
}

function gdOauthClientId(): string { return env('GOOGLE_DRIVE_CLIENT_ID', gdSettingGet('google_drive_client_id')); }
function gdOauthClientSecret(): string { return env('GOOGLE_DRIVE_CLIENT_SECRET', gdSettingGet('google_drive_client_secret')); }
function gdOauthRefreshToken(): string { return env('GOOGLE_DRIVE_REFRESH_TOKEN', gdSettingGet('google_drive_refresh_token')); }
function gdOauthRedirectUri(): string {
    $configured=env('GOOGLE_DRIVE_REDIRECT_URI','');
    if($configured!=='') return $configured;
    $scheme=(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') ? 'https' : 'http';
    $host=$_SERVER['HTTP_HOST'] ?? 'www.sv-netzwerk.eu';
    return $scheme.'://'.$host.'/intern/api/google-drive-oauth.php?action=callback';
}

function gdOauthHttp(string $url, array $fields): array {
    $ch=curl_init($url);
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_POST=>true,
        CURLOPT_POSTFIELDS=>http_build_query($fields),
        CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_CONNECTTIMEOUT=>12,
        CURLOPT_TIMEOUT=>45,
    ]);
    $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
    if($body===false || $err!=='') apiError(503,'Google-OAuth-Verbindung fehlgeschlagen: '.($err?:'unbekannter Fehler'));
    return ['status'=>$status,'body'=>(string)$body];
}

function gdOauthB64UrlEncode(string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function gdOauthB64UrlDecode(string $value): string {
    $pad = strlen($value) % 4;
    if ($pad > 0) $value .= str_repeat('=', 4 - $pad);
    $decoded = base64_decode(strtr($value, '-_', '+/'), true);
    return $decoded === false ? '' : $decoded;
}

function gdOauthCreateState(array $user): string {
    $secret=gdOauthClientSecret();
    if($secret==='') apiError(503,'Google-OAuth-Client ist noch nicht konfiguriert.');
    $payload=json_encode([
        'uid'=>(int)($user['id']??0),
        'iat'=>time(),
        'nonce'=>bin2hex(random_bytes(16)),
    ], JSON_UNESCAPED_SLASHES);
    $encoded=gdOauthB64UrlEncode((string)$payload);
    $signature=hash_hmac('sha256',$encoded,$secret,true);
    return $encoded.'.'.gdOauthB64UrlEncode($signature);
}

function gdOauthValidateState(string $state): array {
    $parts=explode('.',$state,2);
    if(count($parts)!==2) apiError(400,'Ungültiger OAuth-Status.');
    [$encoded,$signatureEncoded]=$parts;
    $secret=gdOauthClientSecret();
    if($secret==='') apiError(503,'Google-OAuth-Client ist nicht konfiguriert.');
    $expected=hash_hmac('sha256',$encoded,$secret,true);
    $received=gdOauthB64UrlDecode($signatureEncoded);
    if($received==='' || !hash_equals($expected,$received)) apiError(400,'Ungültiger OAuth-Status.');
    $payload=json_decode(gdOauthB64UrlDecode($encoded),true);
    if(!is_array($payload) || empty($payload['uid']) || empty($payload['iat'])) apiError(400,'Ungültiger OAuth-Status.');
    if(abs(time()-(int)$payload['iat'])>900) apiError(400,'OAuth-Anmeldung ist abgelaufen. Bitte erneut starten.');
    return $payload;
}

function gdOauthUserById(int $userId): array {
    $stmt=db()->prepare('SELECT id,email,full_name,role,is_active FROM users WHERE id=:id AND is_active=1 LIMIT 1');
    $stmt->execute([':id'=>$userId]);
    $user=$stmt->fetch();
    if(!$user) apiError(403,'Der Portalbenutzer für diese OAuth-Anmeldung ist nicht mehr verfügbar.');
    return $user;
}

$action=(string)($_GET['action'] ?? 'status');

// Der Google-Callback kommt von accounts.google.com zurück. Durch das bewusst
// strikte SameSite-Session-Cookie steht dort keine Portal-Session zur Verfügung.
// Deshalb wird der Callback über einen kurzlebigen, HMAC-signierten State dem
// zuvor angemeldeten Portalbenutzer zugeordnet. Alle anderen Aktionen bleiben
// weiterhin an die normale Portal-Anmeldung gebunden.
if($action==='callback'){
    $state=(string)($_GET['state']??'');
    if($state==='') apiError(400,'OAuth-Status fehlt.');
    $stateData=gdOauthValidateState($state);
    $user=gdOauthUserById((int)$stateData['uid']);

    if(isset($_GET['error'])){
        header('Location: /intern/versicherungsfaelle/?google_drive=denied',true,302);exit;
    }
    $code=trim((string)($_GET['code']??''));
    if($code==='') apiError(400,'Google-Autorisierungscode fehlt.');
    $resp=gdOauthHttp('https://oauth2.googleapis.com/token',[
        'client_id'=>gdOauthClientId(),
        'client_secret'=>gdOauthClientSecret(),
        'code'=>$code,
        'grant_type'=>'authorization_code',
        'redirect_uri'=>gdOauthRedirectUri(),
    ]);
    $data=json_decode($resp['body'],true);
    if($resp['status']!==200 || !is_array($data) || empty($data['access_token'])){
        error_log('[google-drive-oauth] token exchange failed '.$resp['status'].' '.substr($resp['body'],0,1200));
        apiError(503,'Google-Drive-Autorisierung konnte nicht abgeschlossen werden.');
    }
    if(!empty($data['refresh_token'])) gdSettingSet('google_drive_refresh_token',(string)$data['refresh_token'],$user);
    if(gdOauthRefreshToken()==='') apiError(503,'Google hat keinen dauerhaften Refresh-Token geliefert. Bitte Verbindung erneut herstellen.');
    gdSettingSet('google_drive_connected_at',gmdate('c'),$user);
    header('Location: /intern/versicherungsfaelle/?google_drive=connected',true,302);exit;
}

$user = requireAuth();

switch($action){
    case 'status':
        $clientId=gdOauthClientId();$secret=gdOauthClientSecret();$refresh=gdOauthRefreshToken();
        apiJson([
            'ok'=>true,
            'client_configured'=>$clientId!=='' && $secret!=='',
            'connected'=>$refresh!=='',
            'redirect_uri'=>gdOauthRedirectUri(),
            'can_configure'=>(($user['role']??'')==='administrator'),
        ]);

    case 'save_client':
        if(($user['role']??'')!=='administrator') apiError(403,'Nur Administratoren dürfen Google-OAuth konfigurieren.');
        if($_SERVER['REQUEST_METHOD']!=='POST') apiError(405,'POST erforderlich.');
        $body=requestBody();
        $clientId=trim((string)($body['client_id']??''));$secret=trim((string)($body['client_secret']??''));
        if($clientId==='' || $secret==='') apiError(400,'Client-ID und Client-Secret sind erforderlich.');
        gdSettingSet('google_drive_client_id',$clientId,$user);
        gdSettingSet('google_drive_client_secret',$secret,$user);
        apiJson(['ok'=>true,'saved'=>true,'redirect_uri'=>gdOauthRedirectUri()]);

    case 'start':
        $clientId=gdOauthClientId();$secret=gdOauthClientSecret();
        if($clientId==='' || $secret==='') apiError(503,'Google-OAuth-Client ist noch nicht konfiguriert.');
        $state=gdOauthCreateState($user);
        $url='https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id'=>$clientId,
            'redirect_uri'=>gdOauthRedirectUri(),
            'response_type'=>'code',
            'scope'=>'https://www.googleapis.com/auth/drive',
            'access_type'=>'offline',
            'prompt'=>'consent',
            'include_granted_scopes'=>'true',
            'state'=>$state,
        ]);
        header('Location: '.$url, true, 302);exit;

    case 'disconnect':
        if($_SERVER['REQUEST_METHOD']!=='POST') apiError(405,'POST erforderlich.');
        gdSettingDelete('google_drive_refresh_token');
        gdSettingDelete('google_drive_connected_at');
        apiJson(['ok'=>true,'connected'=>false]);

    default:
        apiError(400,'Unbekannte OAuth-Aktion.');
}
