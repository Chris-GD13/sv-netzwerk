<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
commonHeaders();
$user = requireAuth();
if (!in_array($user['role'] ?? '', ['administrator','projektleiter','pruefer','sachverstaendiger'], true)) apiError(403,'Keine Berechtigung.');

function cbB64url(string $data): string { return rtrim(strtr(base64_encode($data), '+/', '-_'), '='); }
function cbHttp(string $method,string $url,array $headers=[],?string $body=null,bool $auth=true): array {
    if ($auth) $headers[]='Authorization: Bearer '.cbToken();
    $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>12,CURLOPT_TIMEOUT=>60,CURLOPT_FOLLOWLOCATION=>true]);
    if($body!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,$body);$resp=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
    if($resp===false||$err!=='')apiError(503,'Google-Drive-Verbindung fehlgeschlagen.');return ['status'=>$status,'body'=>(string)$resp];
}
function cbToken(): string {
    static $token=null;if($token!==null)return $token;
    $serviceJson=trim(env('GOOGLE_DRIVE_SERVICE_ACCOUNT_JSON',''));
    if($serviceJson!==''){
        if(!str_starts_with($serviceJson,'{')){$decoded=base64_decode($serviceJson,true);if($decoded!==false)$serviceJson=$decoded;}
        $svc=json_decode($serviceJson,true);
        if(is_array($svc)&&!empty($svc['client_email'])&&!empty($svc['private_key'])){
            $now=time();$header=cbB64url(json_encode(['alg'=>'RS256','typ'=>'JWT']));$claims=cbB64url(json_encode(['iss'=>$svc['client_email'],'scope'=>'https://www.googleapis.com/auth/drive.readonly','aud'=>'https://oauth2.googleapis.com/token','iat'=>$now,'exp'=>$now+3500]));$input=$header.'.'.$claims;$sig='';
            if(openssl_sign($input,$sig,$svc['private_key'],OPENSSL_ALGO_SHA256)){
                $r=cbHttp('POST','https://oauth2.googleapis.com/token',['Content-Type: application/x-www-form-urlencoded'],http_build_query(['grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer','assertion'=>$input.'.'.cbB64url($sig)]),false);$j=json_decode($r['body'],true);if($r['status']===200&&!empty($j['access_token']))return $token=(string)$j['access_token'];
            }
        }
    }
    $clientId=env('GOOGLE_DRIVE_CLIENT_ID','');$clientSecret=env('GOOGLE_DRIVE_CLIENT_SECRET','');$refresh=env('GOOGLE_DRIVE_REFRESH_TOKEN','');
    if($clientId!==''&&$clientSecret!==''&&$refresh!==''){
        $r=cbHttp('POST','https://oauth2.googleapis.com/token',['Content-Type: application/x-www-form-urlencoded'],http_build_query(['client_id'=>$clientId,'client_secret'=>$clientSecret,'refresh_token'=>$refresh,'grant_type'=>'refresh_token']),false);$j=json_decode($r['body'],true);if($r['status']===200&&!empty($j['access_token']))return $token=(string)$j['access_token'];
    }
    apiError(503,'Google Drive ist nicht verbunden.');
}
function cbList(string $parentId): array {
    $q="'".str_replace("'","\\'",$parentId)."' in parents and trashed=false";$url='https://www.googleapis.com/drive/v3/files?'.http_build_query(['q'=>$q,'fields'=>'files(id,name,mimeType,modifiedTime,size,webViewLink)','pageSize'=>1000,'orderBy'=>'folder,name_natural','supportsAllDrives'=>'true','includeItemsFromAllDrives'=>'true']);
    $r=cbHttp('GET',$url);if($r['status']!==200)apiError(503,'Fallunterlagen konnten nicht geladen werden.');$j=json_decode($r['body'],true);return is_array($j['files']??null)?$j['files']:[];
}
function cbTree(string $folderId,int $depth=0): array {
    if($depth>8)return [];$out=[];
    foreach(cbList($folderId) as $f){$isFolder=(($f['mimeType']??'')==='application/vnd.google-apps.folder');$item=['id'=>(string)$f['id'],'name'=>(string)$f['name'],'mimeType'=>(string)($f['mimeType']??''),'modifiedTime'=>(string)($f['modifiedTime']??''),'size'=>(int)($f['size']??0),'webViewLink'=>(string)($f['webViewLink']??''),'folder'=>$isFolder];if($isFolder)$item['children']=cbTree((string)$f['id'],$depth+1);$out[]=$item;}
    return $out;
}
$folderId=trim((string)($_GET['folder_id']??''));if($folderId==='')apiError(400,'Kein aktiver Fall übergeben.');
$metaUrl='https://www.googleapis.com/drive/v3/files/'.rawurlencode($folderId).'?'.http_build_query(['fields'=>'id,name,webViewLink','supportsAllDrives'=>'true']);$mr=cbHttp('GET',$metaUrl);$meta=json_decode($mr['body'],true)?:[];
echo json_encode(['ok'=>true,'folder'=>['id'=>$folderId,'name'=>(string)($meta['name']??'Fallakte'),'webViewLink'=>(string)($meta['webViewLink']??'')],'items'=>cbTree($folderId)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
