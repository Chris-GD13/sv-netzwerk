<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
commonHeaders();
$user = requireAuth();
if (!in_array($user['role'] ?? '', ['administrator','projektleiter','pruefer','sachverstaendiger'], true)) apiError(403,'Keine Berechtigung.');

function cbB64url(string $data): string { return rtrim(strtr(base64_encode($data), '+/', '-_'), '='); }
function cbSetting(string $key,string $default=''):string{
    try{$stmt=db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key=:k LIMIT 1');$stmt->execute([':k'=>$key]);$value=$stmt->fetchColumn();return$value===false?$default:(string)$value;}catch(Throwable){return$default;}
}
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
    $clientId=env('GOOGLE_DRIVE_CLIENT_ID',cbSetting('google_drive_client_id'));$clientSecret=env('GOOGLE_DRIVE_CLIENT_SECRET',cbSetting('google_drive_client_secret'));$refresh=env('GOOGLE_DRIVE_REFRESH_TOKEN',cbSetting('google_drive_refresh_token'));
    if($clientId!==''&&$clientSecret!==''&&$refresh!==''){
        $r=cbHttp('POST','https://oauth2.googleapis.com/token',['Content-Type: application/x-www-form-urlencoded'],http_build_query(['client_id'=>$clientId,'client_secret'=>$clientSecret,'refresh_token'=>$refresh,'grant_type'=>'refresh_token']),false);$j=json_decode($r['body'],true);if($r['status']===200&&!empty($j['access_token']))return $token=(string)$j['access_token'];
    }
    apiError(503,'Google Drive ist nicht verbunden.');
}
function cbList(string $parentId): array {
    $q="'".str_replace("'","\\'",$parentId)."' in parents and trashed=false";$url='https://www.googleapis.com/drive/v3/files?'.http_build_query(['q'=>$q,'fields'=>'files(id,name,mimeType,modifiedTime,size)','pageSize'=>1000,'orderBy'=>'folder,name_natural','supportsAllDrives'=>'true','includeItemsFromAllDrives'=>'true']);
    $r=cbHttp('GET',$url);if($r['status']!==200)apiError(503,'Fallunterlagen konnten nicht geladen werden.');$j=json_decode($r['body'],true);return is_array($j['files']??null)?$j['files']:[];
}
function cbTree(string $folderId,int $depth=0): array {
    if($depth>8)return [];$out=[];
    foreach(cbList($folderId) as $f){$isFolder=(($f['mimeType']??'')==='application/vnd.google-apps.folder');$item=['id'=>(string)$f['id'],'name'=>(string)$f['name'],'mimeType'=>(string)($f['mimeType']??''),'modifiedTime'=>(string)($f['modifiedTime']??''),'size'=>(int)($f['size']??0),'folder'=>$isFolder];if($isFolder)$item['children']=cbTree((string)$f['id'],$depth+1);$out[]=$item;}
    return $out;
}
$folderId=trim((string)($_GET['folder_id']??''));requireCaseFolderAccess($folderId,$user);
function cbFindInTree(array $items,string $fileId):?array{foreach($items as$item){if(!$item['folder']&&hash_equals((string)$item['id'],$fileId))return$item;if($item['folder']){$found=cbFindInTree((array)($item['children']??[]),$fileId);if($found)return$found;}}return null;}
function cbSafeName(string $name):string{$name=preg_replace('/[\x00-\x1F\x7F"\\\\\/]+/u','_',basename($name))??'Datei';return trim($name)!==''?$name:'Datei';}
function cbStreamFile(array $file):never{
    $id=(string)$file['id'];$mime=(string)($file['mimeType']??'application/octet-stream');$name=cbSafeName((string)($file['name']??'Datei'));
    $export=['application/vnd.google-apps.document'=>['application/vnd.openxmlformats-officedocument.wordprocessingml.document','.docx'],'application/vnd.google-apps.spreadsheet'=>['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','.xlsx'],'application/vnd.google-apps.presentation'=>['application/vnd.openxmlformats-officedocument.presentationml.presentation','.pptx']];
    if(isset($export[$mime])){[$outMime,$ext]=$export[$mime];if(!str_ends_with(strtolower($name),$ext))$name.=$ext;$url='https://www.googleapis.com/drive/v3/files/'.rawurlencode($id).'/export?'.http_build_query(['mimeType'=>$outMime]);}
    else{$outMime=$mime;$url='https://www.googleapis.com/drive/v3/files/'.rawurlencode($id).'?alt=media&supportsAllDrives=true';}
    $r=cbHttp('GET',$url);if($r['status']!==200)apiError(503,'Datei konnte nicht aus der Fallakte geladen werden.');
    // Allgemeine Portalberichte werden aus Kompatibilitätsgründen als .doc
    // gespeichert, enthalten technisch aber HTML. Im Browser müssen sie daher
    // als HTML angezeigt werden; application/msword erzwingt insbesondere auf
    // iPhone/iPad einen unbrauchbaren Download.
    $isHtmlDoc=$mime==='application/msword'&&preg_match('/^\s*(?:<!doctype\s+html|<html\b)/i',$r['body'])===1;
    if($isHtmlDoc)$outMime='text/html; charset=utf-8';
    $inline=$isHtmlDoc||preg_match('#^(?:application/pdf|image/|text/)#i',$outMime)===1;header('Content-Type: '.$outMime);header('Content-Length: '.strlen($r['body']));header('Content-Disposition: '.($inline?'inline':'attachment')."; filename*=UTF-8''".rawurlencode($name));echo$r['body'];exit;
}
$tree=cbTree($folderId);$action=(string)($_GET['action']??'list');
if($action==='file'){$fileId=trim((string)($_GET['file_id']??''));$file=cbFindInTree($tree,$fileId);if(!$file)apiError(404,'Die Datei gehört nicht zu diesem Schadenfall.');cbStreamFile($file);}
$metaUrl='https://www.googleapis.com/drive/v3/files/'.rawurlencode($folderId).'?'.http_build_query(['fields'=>'id,name','supportsAllDrives'=>'true']);$mr=cbHttp('GET',$metaUrl);$meta=json_decode($mr['body'],true)?:[];
echo json_encode(['ok'=>true,'folder'=>['id'=>$folderId,'name'=>(string)($meta['name']??'Fallakte')],'items'=>$tree],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
