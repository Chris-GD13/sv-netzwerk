<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/profile-routing.php';
commonHeaders();
$user = requireAuth();
$profile = svnetUserProfile($user);
$roots = [
    'christian' => getenv('MS_SHAREPOINT_CHRISTIAN_PRIVATE_PATH') ?: 'VS Schäden/Christian/Privatgutachten_NL Süd',
    'marc' => getenv('MS_SHAREPOINT_MARC_PRIVATE_PATH') ?: 'VS Schäden/Marc/Privatgutachten',
];
if (!isset($roots[$profile])) apiError(403, 'Für dieses Benutzerprofil sind keine Privataufträge freigegeben.');
$action = (string)($_GET['action'] ?? 'list');

function poConfig(string $key, string $default = ''): string { $v = getenv($key); return $v === false || trim($v) === '' ? $default : trim($v); }
function poToken(): string {
    static $token = null; if (is_string($token) && $token !== '') return $token;
    $tenant = poConfig('MS_TENANT_ID'); $client = poConfig('MS_CLIENT_ID'); $secret = poConfig('MS_CLIENT_SECRET');
    if ($tenant === '' || $client === '' || $secret === '') apiError(503, 'Die SharePoint-Verbindung ist auf dem Server noch nicht vollständig eingerichtet.');
    $ch = curl_init('https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/token');
    curl_setopt_array($ch, [CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded'],CURLOPT_POSTFIELDS=>http_build_query(['client_id'=>$client,'client_secret'=>$secret,'scope'=>'https://graph.microsoft.com/.default','grant_type'=>'client_credentials'])]);
    $body = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); $json = is_string($body) ? json_decode($body, true) : null;
    if ($status < 200 || $status >= 300 || !is_array($json) || empty($json['access_token'])) apiError(503, 'Microsoft-Anmeldung für die Privataufträge fehlgeschlagen.');
    return $token = (string)$json['access_token'];
}
function poRequest(string $url, bool $binary = false): array|string {
    $ch = curl_init($url); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>$binary?120:45,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.poToken()]]);
    $body = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE); curl_close($ch);
    if ($status < 200 || $status >= 300 || !is_string($body)) apiError(503, 'Privataufträge konnten nicht aus OneDrive gelesen werden (HTTP '.$status.').');
    if ($binary) return ['body'=>$body,'content_type'=>$type]; $json = json_decode($body, true); if (!is_array($json)) apiError(503, 'OneDrive hat eine ungültige Antwort geliefert.'); return $json;
}
function poRequestJson(string $method, string $url, array $payload): array {
    $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_TIMEOUT=>60,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.poToken(),'Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);$json=is_string($body)?json_decode($body,true):null;
    if($status<200||$status>=300||!is_array($json)) apiError(503,'Privatauftrag konnte in OneDrive nicht angelegt werden (HTTP '.$status.').'); return $json;
}
function poRequestUpload(string $url,string $name,string $bytes,string $mime): array {
    $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>'PUT',CURLOPT_TIMEOUT=>180,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.poToken(),'Content-Type'=>($mime!==''?$mime:'application/octet-stream'),'Content-Length'=>strlen($bytes)],CURLOPT_POSTFIELDS=>$bytes]);$body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);$json=is_string($body)?json_decode($body,true):null;if($status<200||$status>=300||!is_array($json))apiError(503,'Datei konnte nicht in den Privatauftrag hochgeladen werden (HTTP '.$status.').');return $json;
}
function poDriveId(): string { static $id=null; if (is_string($id)&&$id!=='') return $id; if (($id=poConfig('MS_SHAREPOINT_DRIVE_ID'))!=='') return $id; $host=poConfig('MS_SHAREPOINT_HOST','sv1schuett.sharepoint.com'); $path=poConfig('MS_SHAREPOINT_SITE_PATH','/sites/SVBroSchtt'); $site=poRequest('https://graph.microsoft.com/v1.0/sites/'.rawurlencode($host).':'.str_replace('%2F','/',rawurlencode($path)).'?$select=id'); $d=poRequest('https://graph.microsoft.com/v1.0/sites/'.rawurlencode((string)($site['id']??'')).'/drive?$select=id'); $id=(string)($d['id']??''); if($id==='')apiError(503,'Die SharePoint-Dokumentbibliothek wurde nicht gefunden.'); return $id; }
function poItemByPath(string $path): array { $parent=''; $match=null; foreach(array_values(array_filter(explode('/',trim($path,'/')),fn($p)=>$p!=='')) as $part){$url='https://graph.microsoft.com/v1.0/drives/'.rawurlencode(poDriveId()).($parent===''?'/root/children':'/items/'.rawurlencode($parent).'/children'). '?$select=id,name,size,file,folder,createdDateTime,lastModifiedDateTime,webUrl&$top=200'; $match=null; do{$page=poRequest($url);foreach(($page['value']??[]) as $item){if(is_array($item)&&strcasecmp((string)($item['name']??''),$part)===0){$match=$item;break 2;}}$url=(string)($page['@odata.nextLink']??'');}while($url!==''); if(!is_array($match)||empty($match['id']))apiError(404,'Privatauftragsordner nicht gefunden: '.$part);$parent=(string)$match['id'];} return $match?:[]; }
function poTree(string $id,string $path='',int $depth=0): array { if($depth>8)return []; $url='https://graph.microsoft.com/v1.0/drives/'.rawurlencode(poDriveId()).'/items/'.rawurlencode($id).'/children?$select=id,name,size,file,folder,createdDateTime,lastModifiedDateTime,webUrl&$top=200';$out=[];do{$page=poRequest($url);foreach(($page['value']??[]) as $item){if(!is_array($item)||empty($item['id']))continue;$name=(string)($item['name']??'');$row=['id'=>(string)$item['id'],'name'=>$name,'path'=>ltrim($path.'/'.$name,'/'),'folder'=>isset($item['folder']),'size'=>(int)($item['size']??0),'modified'=>(string)($item['lastModifiedDateTime']??''),'webUrl'=>(string)($item['webUrl']??'')];if($row['folder'])$row['children']=poTree($row['id'],$row['path'],$depth+1);$out[]=$row;}$url=(string)($page['@odata.nextLink']??'');}while($url!=='');usort($out,fn($a,$b)=>[$b['folder'],$a['name']]<=>[$a['folder'],$b['name']]);return $out; }
function poFind(array $rows,string $id): ?array { foreach($rows as $row){ if(hash_equals((string)($row['id']??''),$id)) return $row; if(!empty($row['folder'])){ $found=poFind((array)($row['children']??[]),$id); if($found)return $found; } } return null; }
function poStandardFolders(): array { return ['Auftrag','Fotos','Gutachten','Angebot','Rechnungen','E-Mails','Bilder','Dateien','Sonstige Unterlagen','Eingang KI']; }
function poEnsureFolders(array $item): void {
    if (empty($item['id']) || empty($item['folder'])) return;
    $existing = [];
    foreach ((array)($item['children'] ?? []) as $child) if (!empty($child['folder'])) $existing[mb_strtolower((string)($child['name'] ?? ''), 'UTF-8')] = true;
    foreach (poStandardFolders() as $name) {
        if (isset($existing[mb_strtolower($name, 'UTF-8')])) continue;
        try {
            poRequestJson('POST','https://graph.microsoft.com/v1.0/drives/'.rawurlencode(poDriveId()).'/items/'.rawurlencode((string)$item['id']).'/children',['name'=>$name,'folder'=>new stdClass(),'@microsoft.graph.conflictBehavior'=>'fail']);
        } catch (Throwable $e) {
            // Leserechte genügen für die Aktenansicht; die Liste darf bei einem
            // schreibgeschützten OneDrive nicht mit HTTP 403 abbrechen.
            continue;
        }
    }
}
$root=poItemByPath($roots[$profile]);
if ($action==='list') {
    $items = poTree((string)$root['id']);
    apiJson(['ok'=>true,'profile'=>$profile,'root'=>['name'=>$root['name']??basename($roots[$profile]),'path'=>$roots[$profile]],'items'=>$items,'standard_folders'=>poStandardFolders()]);
}
if ($action==='ensure' && $_SERVER['REQUEST_METHOD']==='POST') {
    $input=json_decode((string)file_get_contents('php://input'),true);$id=trim((string)($input['id']??''));
    $tree=poTree((string)$root['id']);$item=poFind($tree,$id);
    if(!$item||empty($item['folder'])||preg_match('/^20\\d{2}$/',(string)($item['name']??'')))apiError(403,'Ungültiger Auftragsordner.');
    poEnsureFolders($item);apiJson(['ok'=>true]);
}
if ($action==='file') { $id=trim((string)($_GET['id']??'')); if($id==='')apiError(400,'Datei-ID fehlt.'); $tree=poTree((string)$root['id']); $item=poFind($tree,$id); if(!$item||!empty($item['folder']))apiError(403,'Diese Datei gehört nicht zum eigenen Privatauftragsbereich.'); $r=poRequest('https://graph.microsoft.com/v1.0/drives/'.rawurlencode(poDriveId()).'/items/'.rawurlencode($id).'/content',true); header('Content-Type: '.($r['content_type']?:'application/octet-stream')); header('Content-Disposition: inline; filename="'.str_replace('"','',basename((string)$item['name'])).'"'); echo $r['body']; exit; }
if ($action==='create' && $_SERVER['REQUEST_METHOD']==='POST') { $input=json_decode((string)file_get_contents('php://input'),true);$name=trim((string)($input['name']??''));$parent=trim((string)($input['parent_id']??$root['id']));if($name===''||mb_strlen($name)>120||preg_match('/[\\\/\x00-\x1F]/u',$name))apiError(400,'Bitte einen gültigen Auftragsnamen eingeben.');$tree=poTree((string)$root['id']);if($parent!==(string)$root['id']&&!poFind($tree,$parent))apiError(403,'Der Zielordner gehört nicht zum eigenen Privatauftragsbereich.');$created=poRequestJson('POST','https://graph.microsoft.com/v1.0/drives/'.rawurlencode(poDriveId()).'/items/'.rawurlencode($parent).'/children',['name'=>$name,'folder'=>new stdClass(),'@microsoft.graph.conflictBehavior'=>'rename']);foreach(['Auftrag','Fotos','Gutachten','Angebot','Rechnungen','E-Mails','Bilder','Dateien','Sonstige Unterlagen','Eingang KI'] as $sub)poRequestJson('POST','https://graph.microsoft.com/v1.0/drives/'.rawurlencode(poDriveId()).'/items/'.rawurlencode((string)($created['id']??'')).'/children',['name'=>$sub,'folder'=>new stdClass(),'@microsoft.graph.conflictBehavior'=>'fail']);apiJson(['ok'=>true,'item'=>$created]);}
if ($action==='upload' && $_SERVER['REQUEST_METHOD']==='POST') { $parent=trim((string)($_POST['folder_id']??''));if($parent==='')apiError(400,'Zielordner fehlt.');$tree=poTree((string)$root['id']);$folder=poFind($tree,$parent);if(!$folder||empty($folder['folder']))apiError(403,'Der Zielordner gehört nicht zum eigenen Privatauftragsbereich.');$file=$_FILES['file']??null;if(!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)apiError(400,'Keine gültige Datei empfangen.');if((int)($file['size']??0)>4194304)apiError(413,'Dateien bis 4 MB können direkt abgelegt werden.');$name=basename((string)($file['name']??'Datei'));if($name===''||preg_match('/[\\\/\x00-\x1F]/u',$name))apiError(400,'Ungültiger Dateiname.');$bytes=file_get_contents((string)$file['tmp_name']);if(!is_string($bytes))apiError(400,'Datei konnte nicht gelesen werden.');$mime=(string)($file['type']??'application/octet-stream');$uploaded=poRequestUpload('https://graph.microsoft.com/v1.0/drives/'.rawurlencode(poDriveId()).'/items/'.rawurlencode($parent).':/'.rawurlencode($name).':/content',$name,$bytes,$mime);apiJson(['ok'=>true,'item'=>['id'=>(string)($uploaded['id']??''),'name'=>(string)($uploaded['name']??$name)]]);}
apiError(404,'Unbekannte Aktion für Privataufträge.');

