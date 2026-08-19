<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
commonHeaders();
requireAuth();

function spCfg(string $key, string $default=''): string {
    $v=getenv($key); return $v===false || trim($v)==='' ? $default : trim($v);
}
function spToken(): string {
    static $token=''; if ($token!=='') return $token;
    $tenant=spCfg('MS_TENANT_ID'); $client=spCfg('MS_CLIENT_ID'); $secret=spCfg('MS_CLIENT_SECRET');
    if ($tenant===''||$client===''||$secret==='') apiError(503,'SharePoint-Verbindung ist nicht vollständig eingerichtet.');
    $ch=curl_init('https://login.microsoftonline.com/'.rawurlencode($tenant).'/oauth2/v2.0/token');
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded'],CURLOPT_POSTFIELDS=>http_build_query(['client_id'=>$client,'client_secret'=>$secret,'scope'=>'https://graph.microsoft.com/.default','grant_type'=>'client_credentials'])]);
    $body=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    $j=is_string($body)?json_decode($body,true):null;
    if ($status<200||$status>=300||!is_array($j)||empty($j['access_token'])) apiError(503,'Microsoft-Anmeldung für SharePoint fehlgeschlagen.');
    return $token=(string)$j['access_token'];
}
function spReq(string $url,bool $binary=false): array|string {
    $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>$binary?180:60,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.spToken()]]);
    $body=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $type=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE); curl_close($ch);
    if ($status<200||$status>=300||!is_string($body)) apiError(503,'SharePoint konnte nicht gelesen werden (HTTP '.$status.').');
    if ($binary) return ['body'=>$body,'content_type'=>$type];
    $j=json_decode($body,true); if(!is_array($j)) apiError(503,'Ungültige SharePoint-Antwort.'); return $j;
}
function spSiteId(): string {
    $configured=spCfg('MS_SHAREPOINT_SITE_ID'); if($configured!=='') return $configured;
    $host=spCfg('MS_SHAREPOINT_HOST','sv1schuett.sharepoint.com'); $path=spCfg('MS_SHAREPOINT_SITE_PATH','/sites/SVBroSchtt');
    $j=spReq('https://graph.microsoft.com/v1.0/sites/'.rawurlencode($host).':'.str_replace('%2F','/',rawurlencode($path)).'?$select=id');
    $id=(string)($j['id']??''); if($id==='') apiError(503,'SharePoint-Site nicht gefunden.'); return $id;
}
function spDriveId(): string {
    $configured=spCfg('MS_SHAREPOINT_DRIVE_ID'); if($configured!=='') return $configured;
    $j=spReq('https://graph.microsoft.com/v1.0/sites/'.rawurlencode(spSiteId()).'/drive?$select=id');
    $id=(string)($j['id']??''); if($id==='') apiError(503,'SharePoint-Dokumentbibliothek nicht gefunden.'); return $id;
}
function spItemByPath(string $path): array {
    $segments=array_values(array_filter(explode('/',trim($path,'/')),fn($x)=>$x!=='')); $parent=''; $matched=null;
    foreach($segments as $segment){
        $url='https://graph.microsoft.com/v1.0/drives/'.rawurlencode(spDriveId()).($parent===''?'/root/children':'/items/'.rawurlencode($parent).'/children').'?%24select=id,name,file,folder,lastModifiedDateTime&%24top=200';
        $matched=null;
        while($url!==''){
            $page=spReq($url); foreach(($page['value']??[]) as $item){ if(is_array($item)&&strcasecmp((string)($item['name']??''),$segment)===0){$matched=$item;break 2;}}
            $url=(string)($page['@odata.nextLink']??'');
        }
        if(!is_array($matched)||empty($matched['id'])) apiError(404,'SharePoint-Projektpfad nicht gefunden: '.$segment);
        $parent=(string)$matched['id'];
    }
    return $matched??[];
}
function spTree(string $rootId): array {
    $out=[]; $queue=[[$rootId,'']];
    while($queue){ [$pid,$rel]=array_shift($queue); $url='https://graph.microsoft.com/v1.0/drives/'.rawurlencode(spDriveId()).'/items/'.rawurlencode($pid).'/children?%24select=id,name,file,folder,lastModifiedDateTime,size&%24top=200';
        while($url!==''){ $page=spReq($url); foreach(($page['value']??[]) as $item){ if(!is_array($item))continue; $name=(string)($item['name']??''); $item['relative_path']=ltrim($rel.'/'.$name,'/'); $out[]=$item; if(!empty($item['folder'])&&!empty($item['id']))$queue[]=[(string)$item['id'],$item['relative_path']]; } $url=(string)($page['@odata.nextLink']??''); }
    }
    return $out;
}

$excelPath=spCfg('MS_SHAREPOINT_EXCEL_PATH','VS Schäden/Marc/Privatgutachten/2026/Bundesministerium Verteidigung_Bonn/BW fesnterprüfung.xlsx');
$projectPath=spCfg('MS_SHAREPOINT_PROJECT_PATH',dirname($excelPath));
$folder=spItemByPath($projectPath); $folderId=(string)($folder['id']??''); if($folderId==='') apiError(404,'SharePoint-Projektordner nicht gefunden.');
$requested=mb_strtolower(trim((string)($_GET['name']??'')),'UTF-8');
$candidates=[];
foreach(spTree($folderId) as $item){
    if(empty($item['file'])||empty($item['id'])) continue;
    $name=(string)($item['name']??'');
    if(!preg_match('/\.(doc|docx|html|htm)$/i',$name)) continue;
    $score=0; $lower=mb_strtolower($name,'UTF-8');
    if($requested!=='' && $lower===$requested) $score+=1000;
    if($requested!=='' && str_contains($lower,preg_replace('/\.docx?$/i','',$requested))) $score+=500;
    if(str_contains($lower,'gutachten')) $score+=200;
    if(str_contains($lower,'fenster')) $score+=100;
    $candidates[]=['item'=>$item,'score'=>$score,'modified'=>(string)($item['lastModifiedDateTime']??'')];
}
if(!$candidates) apiError(404,'Im SharePoint-Projektordner wurde keine Word-/HTML-Gutachtendatei gefunden.');
usort($candidates,fn($a,$b)=>($b['score']<=>$a['score']) ?: strcmp($b['modified'],$a['modified']));
$chosen=$candidates[0]['item'];
$download=spReq('https://graph.microsoft.com/v1.0/drives/'.rawurlencode(spDriveId()).'/items/'.rawurlencode((string)$chosen['id']).'/content',true);
$name=basename((string)($chosen['name']??'Gutachten.doc'));
header('Content-Type: '.((string)($download['content_type']??'application/octet-stream')));
header('Content-Disposition: attachment; filename="'.addcslashes($name,'"\\').'"');
header('Cache-Control: private, no-store');
header('X-SharePoint-Source: '.rawurlencode((string)($chosen['relative_path']??$name)));
echo (string)$download['body'];
