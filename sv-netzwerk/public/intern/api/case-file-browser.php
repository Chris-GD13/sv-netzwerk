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
            $now=time();$header=cbB64url(json_encode(['alg'=>'RS256','typ'=>'JWT']));$claims=cbB64url(json_encode(['iss'=>$svc['client_email'],'scope'=>'https://www.googleapis.com/auth/drive','aud'=>'https://oauth2.googleapis.com/token','iat'=>$now,'exp'=>$now+3500]));$input=$header.'.'.$claims;$sig='';
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
    foreach(cbList($folderId) as $f){$isFolder=(($f['mimeType']??'')==='application/vnd.google-apps.folder');$item=['id'=>(string)$f['id'],'name'=>(string)$f['name'],'mimeType'=>(string)($f['mimeType']??''),'modifiedTime'=>(string)($f['modifiedTime']??''),'size'=>(int)($f['size']??0),'folder'=>$isFolder,'parentId'=>$folderId];if($isFolder)$item['children']=cbTree((string)$f['id'],$depth+1);$out[]=$item;}
    return $out;
}
$folderId=trim((string)($_GET['folder_id']??''));requireCaseFolderAccess($folderId,$user);
function cbFindInTree(array $items,string $fileId):?array{foreach($items as$item){if(!$item['folder']&&hash_equals((string)$item['id'],$fileId))return$item;if($item['folder']){$found=cbFindInTree((array)($item['children']??[]),$fileId);if($found)return$found;}}return null;}
function cbFindFolderInTree(array $items,string $folderId):?array{foreach($items as$item){if($item['folder']&&hash_equals((string)$item['id'],$folderId))return$item;if($item['folder']){$found=cbFindFolderInTree((array)($item['children']??[]),$folderId);if($found)return$found;}}return null;}
function cbSafeName(string $name):string{$name=preg_replace('/[\x00-\x1F\x7F"\\\\\/]+/u','_',basename($name))??'Datei';return trim($name)!==''?$name:'Datei';}
function cbDocxPreview(string $bytes,string $name):string{
    $tmp=tempnam(sys_get_temp_dir(),'svnet-docx-');if($tmp===false)return'';file_put_contents($tmp,$bytes);$zip=new ZipArchive();if($zip->open($tmp)!==true){@unlink($tmp);return'';}$xml=$zip->getFromName('word/document.xml');$zip->close();@unlink($tmp);if(!is_string($xml)||$xml==='')return'';
    $dom=new DOMDocument('1.0','UTF-8');if(!@$dom->loadXML($xml))return'';$xp=new DOMXPath($dom);$xp->registerNamespace('w','http://schemas.openxmlformats.org/wordprocessingml/2006/main');$parts=[];
    foreach($xp->query('//w:body/w:p|//w:body/w:tbl//w:tr')?:[]as$node){$text='';foreach($xp->query('.//w:t',$node)?:[]as$textNode)$text.=(string)$textNode->textContent;$text=trim($text);if($text!=='')$parts[]='<p>'.htmlspecialchars($text,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'</p>';}
    if(!$parts)return'';$title=htmlspecialchars($name,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');return'<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.$title.'</title><style>body{font-family:Arial,sans-serif;font-size:16px;line-height:1.55;margin:0;padding:20px;color:#172f45;background:#fff}h1{font-size:20px;line-height:1.3;margin:0 0 22px;overflow-wrap:anywhere}p{margin:0 0 12px;white-space:pre-wrap} @media(min-width:760px){body{max-width:850px;margin:auto;padding:40px}}</style></head><body><h1>'.$title.'</h1>'.implode('',$parts).'</body></html>';
}
function cbStreamFile(array $file,bool $forceDownload=false):never{
    $id=(string)$file['id'];$mime=(string)($file['mimeType']??'application/octet-stream');$name=cbSafeName((string)($file['name']??'Datei'));
    $export=['application/vnd.google-apps.document'=>['application/vnd.openxmlformats-officedocument.wordprocessingml.document','.docx'],'application/vnd.google-apps.spreadsheet'=>['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','.xlsx'],'application/vnd.google-apps.presentation'=>['application/vnd.openxmlformats-officedocument.presentationml.presentation','.pptx']];
    if(isset($export[$mime])){[$outMime,$ext]=$export[$mime];if(!str_ends_with(strtolower($name),$ext))$name.=$ext;$url='https://www.googleapis.com/drive/v3/files/'.rawurlencode($id).'/export?'.http_build_query(['mimeType'=>$outMime]);}
    else{$outMime=$mime;$url='https://www.googleapis.com/drive/v3/files/'.rawurlencode($id).'?alt=media&supportsAllDrives=true';}
    $r=cbHttp('GET',$url);if($r['status']!==200)apiError(503,'Datei konnte nicht aus der Fallakte geladen werden.');
    $isDocx=$outMime==='application/vnd.openxmlformats-officedocument.wordprocessingml.document'||str_ends_with(strtolower($name),'.docx');
    if($isDocx&&!$forceDownload){$preview=cbDocxPreview($r['body'],$name);if($preview!==''){header('Content-Type: text/html; charset=utf-8');header('Content-Length: '.strlen($preview));header("Content-Disposition: inline; filename*=UTF-8''".rawurlencode($name.'.html'));echo$preview;exit;}}
    // Allgemeine Portalberichte werden aus Kompatibilitätsgründen als .doc
    // gespeichert, enthalten technisch aber HTML. Im Browser müssen sie daher
    // als HTML angezeigt werden; application/msword erzwingt insbesondere auf
    // iPhone/iPad einen unbrauchbaren Download.
    $isHtmlDoc=$mime==='application/msword'&&preg_match('/^\s*(?:<!doctype\s+html|<html\b)/i',$r['body'])===1;
    if($isHtmlDoc)$outMime='text/html; charset=utf-8';
    $inline=!$forceDownload&&($isHtmlDoc||preg_match('#^(?:application/pdf|image/|text/)#i',$outMime)===1);header('Content-Type: '.$outMime);header('Content-Length: '.strlen($r['body']));header('Content-Disposition: '.($inline?'inline':'attachment')."; filename*=UTF-8''".rawurlencode($name));echo$r['body'];exit;
}
$tree=cbTree($folderId);$action=(string)($_GET['action']??'list');
if($action==='file'||$action==='download'){$fileId=trim((string)($_GET['file_id']??''));$file=cbFindInTree($tree,$fileId);if(!$file)apiError(404,'Die Datei gehört nicht zu diesem Schadenfall.');cbStreamFile($file,$action==='download');}
if($action==='delete_selected'){
    if($_SERVER['REQUEST_METHOD']!=='POST')apiError(405,'POST erforderlich.');
    $body=requestBody();$fileIds=is_array($body['file_ids']??null)?array_values(array_unique(array_filter(array_map(static fn($id):string=>trim((string)$id),$body['file_ids'])))):[];
    if(!$fileIds)apiError(400,'Bitte mindestens eine Datei auswählen.');
    if(count($fileIds)>100)apiError(400,'Es können höchstens 100 Dateien gleichzeitig gelöscht werden.');
    $files=[];
    foreach($fileIds as$fileId){$file=cbFindInTree($tree,$fileId);if(!$file)apiError(404,'Mindestens eine ausgewählte Datei gehört nicht zu diesem Schadenfall.');if(($file['folder']??false)===true)apiError(400,'Ordner können hier nicht gelöscht werden.');if(strcasecmp((string)($file['name']??''),'00_Falldaten.json')===0)apiError(403,'Die zentrale Falldaten-Datei darf nicht gelöscht werden.');$files[]=$file;}
    $deleted=[];
    foreach($files as$file){$fileId=(string)$file['id'];$r=cbHttp('PATCH','https://www.googleapis.com/drive/v3/files/'.rawurlencode($fileId).'?supportsAllDrives=true',['Content-Type: application/json'],json_encode(['trashed'=>true],JSON_UNESCAPED_SLASHES));if($r['status']<200||$r['status']>=300)apiError(503,'Nicht alle ausgewählten Dateien konnten in den Papierkorb verschoben werden.');$deleted[]=['id'=>$fileId,'name'=>(string)($file['name']??'Datei')];}
    apiJson(['ok'=>true,'deleted_count'=>count($deleted),'deleted'=>$deleted,'recoverable'=>true]);
}
if($action==='move_selected'){
    if($_SERVER['REQUEST_METHOD']!=='POST')apiError(405,'POST erforderlich.');
    $body=requestBody();$fileIds=is_array($body['file_ids']??null)?array_values(array_unique(array_filter(array_map(static fn($id):string=>trim((string)$id),$body['file_ids'])))):[];$targetId=trim((string)($body['target_folder_id']??''));
    if(!$fileIds)apiError(400,'Bitte mindestens eine Datei auswählen.');
    if(count($fileIds)>100)apiError(400,'Es können höchstens 100 Dateien gleichzeitig verschoben werden.');
    if($targetId==='')apiError(400,'Bitte einen Zielordner auswählen.');
    $target=$targetId===$folderId?['id'=>$folderId,'name'=>'Fallakte','folder'=>true]:cbFindFolderInTree($tree,$targetId);
    if(!$target||($target['folder']??false)!==true)apiError(403,'Der Zielordner gehört nicht zu diesem Schadenfall.');
    $files=[];
    foreach($fileIds as$fileId){$file=cbFindInTree($tree,$fileId);if(!$file)apiError(404,'Mindestens eine ausgewählte Datei gehört nicht zu diesem Schadenfall.');if(($file['folder']??false)===true)apiError(400,'Ordner können hier nicht verschoben werden.');if(strcasecmp((string)($file['name']??''),'00_Falldaten.json')===0)apiError(403,'Die zentrale Falldaten-Datei darf nicht verschoben werden.');$files[]=$file;}
    $moved=[];$skipped=[];
    foreach($files as$file){$fileId=(string)$file['id'];$sourceId=trim((string)($file['parentId']??''));if($sourceId===$targetId){$skipped[]=['id'=>$fileId,'name'=>(string)($file['name']??'Datei')];continue;}$query=http_build_query(['supportsAllDrives'=>'true','addParents'=>$targetId,'removeParents'=>$sourceId,'fields'=>'id,name,parents']);$r=cbHttp('PATCH','https://www.googleapis.com/drive/v3/files/'.rawurlencode($fileId).'?'.$query,['Content-Type: application/json'],'{}');if($r['status']<200||$r['status']>=300)apiError(503,'Nicht alle ausgewählten Dateien konnten verschoben werden.');$moved[]=['id'=>$fileId,'name'=>(string)($file['name']??'Datei')];}
    apiJson(['ok'=>true,'moved_count'=>count($moved),'moved'=>$moved,'skipped_count'=>count($skipped),'target'=>['id'=>$targetId,'name'=>(string)$target['name']]]);
}
if($action==='delete'){
    if($_SERVER['REQUEST_METHOD']!=='POST')apiError(405,'POST erforderlich.');
    $body=requestBody();$fileId=trim((string)($body['file_id']??''));$file=cbFindInTree($tree,$fileId);
    if(!$file)apiError(404,'Die Datei gehört nicht zu diesem Schadenfall.');
    $name=(string)($file['name']??'');
    if(!preg_match('/(?:Bericht|Erstbericht|Zwischenbericht|Schlussbericht|Schlusserkl|Nachtrag|Stellungnahme|Zahlungsbef|Rückfrage|Rueckfrage|Dokumentenindex|Rechnungsregister|Schadenprotokoll)/ui',$name)||!preg_match('/\d{4}-\d{2}-\d{2}_\d{6}/',$name))apiError(403,'Nur im Portal erzeugte Ausgabedokumente dürfen hier gelöscht werden.');
    $r=cbHttp('DELETE','https://www.googleapis.com/drive/v3/files/'.rawurlencode($fileId).'?supportsAllDrives=true');
    if($r['status']!==204)apiError(503,'Dokument konnte nicht gelöscht werden.');
    apiJson(['ok'=>true,'deleted'=>$fileId]);
}
$metaUrl='https://www.googleapis.com/drive/v3/files/'.rawurlencode($folderId).'?'.http_build_query(['fields'=>'id,name','supportsAllDrives'=>'true']);$mr=cbHttp('GET',$metaUrl);$meta=json_decode($mr['body'],true)?:[];
echo json_encode(['ok'=>true,'folder'=>['id'=>$folderId,'name'=>(string)($meta['name']??'Fallakte')],'items'=>$tree],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
