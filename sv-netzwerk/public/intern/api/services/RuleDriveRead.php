<?php
declare(strict_types=1);
/** Existing portal credential scheme, restricted to read-only Drive file access. No model transport. */
function svRuleB64url(string $data): string { return rtrim(strtr(base64_encode($data), '+/', '-_'), '='); }
function svRuleSetting(string $key,string $default=''):string{
    try{$stmt=db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key=:k LIMIT 1');$stmt->execute([':k'=>$key]);$value=$stmt->fetchColumn();return$value===false?$default:(string)$value;}catch(Throwable){return$default;}
}
function svRuleHttp(string $method,string $url,array $headers=[],?string $body=null,bool $auth=true): array {
    $host=parse_url($url,PHP_URL_HOST);$path=parse_url($url,PHP_URL_PATH);
    if(!(($host==='www.googleapis.com'&&$method==='GET'&&str_starts_with($path,'/drive/v3/files/'))||($host==='oauth2.googleapis.com'&&$method==='POST'&&$path==='/token')))throw new RuntimeException('RULE_NETWORK_TARGET_FORBIDDEN');
    if ($auth) $headers[]='Authorization: Bearer '.svRuleToken();
    $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>12,CURLOPT_TIMEOUT=>20,CURLOPT_FOLLOWLOCATION=>false]);
    if($body!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,$body);$resp=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
    if($resp===false||$err!=='')throw new RuntimeException('RULE_DRIVE_UNAVAILABLE');return ['status'=>$status,'body'=>(string)$resp];
}
function svRuleToken(): string {
    static $token=null;if($token!==null)return $token;
    $serviceJson=trim(env('GOOGLE_DRIVE_SERVICE_ACCOUNT_JSON',''));
    if($serviceJson!==''){
        if(!str_starts_with($serviceJson,'{')){$decoded=base64_decode($serviceJson,true);if($decoded!==false)$serviceJson=$decoded;}
        $svc=json_decode($serviceJson,true);
        if(is_array($svc)&&!empty($svc['client_email'])&&!empty($svc['private_key'])){
            $now=time();$header=svRuleB64url(json_encode(['alg'=>'RS256','typ'=>'JWT']));$claims=svRuleB64url(json_encode(['iss'=>$svc['client_email'],'scope'=>'https://www.googleapis.com/auth/drive.readonly','aud'=>'https://oauth2.googleapis.com/token','iat'=>$now,'exp'=>$now+3500]));$input=$header.'.'.$claims;$sig='';
            if(openssl_sign($input,$sig,$svc['private_key'],OPENSSL_ALGO_SHA256)){
                $r=svRuleHttp('POST','https://oauth2.googleapis.com/token',['Content-Type: application/x-www-form-urlencoded'],http_build_query(['grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer','assertion'=>$input.'.'.svRuleB64url($sig)]),false);$j=json_decode($r['body'],true);if($r['status']===200&&!empty($j['access_token']))return $token=(string)$j['access_token'];
            }
        }
    }
    $clientId=env('GOOGLE_DRIVE_CLIENT_ID',svRuleSetting('google_drive_client_id'));$clientSecret=env('GOOGLE_DRIVE_CLIENT_SECRET',svRuleSetting('google_drive_client_secret'));$refresh=env('GOOGLE_DRIVE_REFRESH_TOKEN',svRuleSetting('google_drive_refresh_token'));
    if($clientId!==''&&$clientSecret!==''&&$refresh!==''){
        $r=svRuleHttp('POST','https://oauth2.googleapis.com/token',['Content-Type: application/x-www-form-urlencoded'],http_build_query(['client_id'=>$clientId,'client_secret'=>$clientSecret,'refresh_token'=>$refresh,'grant_type'=>'refresh_token']),false);$j=json_decode($r['body'],true);if($r['status']===200&&!empty($j['access_token']))return $token=(string)$j['access_token'];
    }
    throw new RuntimeException('RULE_DRIVE_NOT_CONNECTED');
}
