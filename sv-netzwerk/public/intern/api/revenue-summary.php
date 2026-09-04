<?php
declare(strict_types=1);

require_once __DIR__.'/config.php';
require_once __DIR__.'/profile-routing.php';

const RS_SOURCE_URL = 'https://sv1schuett-my.sharepoint.com/:x:/r/personal/ws_sv-schuett_eu/_layouts/15/Doc.aspx?sourcedoc=%7B368ACA1B-6D24-402A-816C-B7B40827CF07%7D&file=CL%20Umsatzaufstellung%20W%25u00e4chter.xlsx&fromShare=true&action=default&mobileredirect=true';
const RS_GOOGLE_FILE_ID = '1OSL9jQow1C0azdi1NlbVSWXpbxfBZej9';
const RS_MONTHS = ['januar'=>1,'februar'=>2,'maerz'=>3,'mrz'=>3,'april'=>4,'mai'=>5,'juni'=>6,'juli'=>7,'august'=>8,'september'=>9,'oktober'=>10,'november'=>11,'dezember'=>12];
const RS_MONTH_LABELS = [1=>'Jan.',2=>'Feb.',3=>'März',4=>'April',5=>'Mai',6=>'Juni',7=>'Juli',8=>'Aug.',9=>'Sept.',10=>'Okt.',11=>'Nov.',12=>'Dez.'];

commonHeaders();

function rsText(mixed $value):string {
    return trim(strtr(mb_strtolower((string)$value,'UTF-8'),['ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss'])," \t\n\r\0\x0B:");
}
function rsCanRefresh(array $user):bool {
    $identity=rsText((string)($user['email']??'').' '.(string)($user['full_name']??''));
    return str_contains($identity,'susanne')||str_contains($identity,'ws@sv-schuett.eu');
}
function rsVisible(array $user):bool {
    return svnetUserProfile($user)==='christian'||rsCanRefresh($user);
}
function rsEnsureTable():void {
    db()->exec("CREATE TABLE IF NOT EXISTS portal_revenue_summary(profile VARCHAR(30) PRIMARY KEY,payload_json MEDIUMTEXT NOT NULL,source_modified_at VARCHAR(40) NULL,updated_by VARCHAR(255) NOT NULL,updated_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function rsStored():?array {
    rsEnsureTable();$stmt=db()->prepare("SELECT payload_json FROM portal_revenue_summary WHERE profile='christian' LIMIT 1");$stmt->execute();$decoded=json_decode((string)($stmt->fetchColumn()?:''),true);return is_array($decoded)?$decoded:null;
}
function rsFallback():array {
    $data=require __DIR__.'/revenue-summary-fallback.php';return is_array($data)?$data:[];
}
function rsCfg(string $key):string {
    $value=getenv($key);return $value===false?'':trim($value);
}
function rsSetting(string $key):string {
    try{db()->exec("CREATE TABLE IF NOT EXISTS app_settings(setting_key VARCHAR(190) PRIMARY KEY,setting_value MEDIUMTEXT NULL,updated_at DATETIME NOT NULL,updated_by VARCHAR(190) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");$stmt=db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key=:key LIMIT 1');$stmt->execute([':key'=>$key]);$value=$stmt->fetchColumn();return$value===false?'':trim((string)$value);}catch(Throwable){return'';}
}
function rsHttp(string $method,string $url,array $headers=[],?string $body=null,int $timeout=60):array {
    $ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>$timeout,CURLOPT_HTTPHEADER=>$headers]);if($body!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,$body);$response=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);if(!is_string($response))throw new RuntimeException('SharePoint-Verbindung fehlgeschlagen'.($error!==''?': '.$error:'.'));return['status'=>$status,'body'=>$response];
}
function rsToken():string {
    static $token=null;if($token!==null)return$token;$tenant=rsCfg('MS_TENANT_ID');$client=rsCfg('MS_CLIENT_ID');$secret=rsCfg('MS_CLIENT_SECRET');if($tenant===''||$client===''||$secret==='')throw new RuntimeException('Die Microsoft-Verbindung ist nicht vollständig eingerichtet.');$response=rsHttp('POST','https://login.microsoftonline.com/'.rawurlencode($tenant).'/oauth2/v2.0/token',['Content-Type: application/x-www-form-urlencoded'],http_build_query(['client_id'=>$client,'client_secret'=>$secret,'scope'=>'https://graph.microsoft.com/.default','grant_type'=>'client_credentials']),30);$json=json_decode($response['body'],true);if($response['status']!==200||!is_array($json)||empty($json['access_token']))throw new RuntimeException('Die Microsoft-Anmeldung ist fehlgeschlagen.');$token=(string)$json['access_token'];return$token;
}
function rsShareId():string {return'u!'.rtrim(strtr(base64_encode(RS_SOURCE_URL),'+/','-_'),'=');}
function rsB64(string $value):string{return rtrim(strtr(base64_encode($value),'+/','-_'),'=');}
function rsGoogleToken():string {
    static$token=null;if($token!==null)return$token;$client=rsCfg('GOOGLE_DRIVE_CLIENT_ID')?:rsSetting('google_drive_client_id');$secret=rsCfg('GOOGLE_DRIVE_CLIENT_SECRET')?:rsSetting('google_drive_client_secret');$refresh=rsCfg('GOOGLE_DRIVE_REFRESH_TOKEN')?:rsSetting('google_drive_refresh_token');if($client!==''&&$secret!==''&&$refresh!==''){$response=rsHttp('POST','https://oauth2.googleapis.com/token',['Content-Type: application/x-www-form-urlencoded'],http_build_query(['client_id'=>$client,'client_secret'=>$secret,'refresh_token'=>$refresh,'grant_type'=>'refresh_token']),30);$json=json_decode($response['body'],true);if($response['status']===200&&is_array($json)&&!empty($json['access_token']))return$token=(string)$json['access_token'];}
    $raw=rsCfg('GOOGLE_DRIVE_SERVICE_ACCOUNT_JSON');if($raw!==''&&!str_starts_with($raw,'{'))$raw=(string)(base64_decode($raw,true)?:$raw);$service=json_decode($raw,true);if(is_array($service)&&!empty($service['client_email'])&&!empty($service['private_key'])){$now=time();$header=rsB64(json_encode(['alg'=>'RS256','typ'=>'JWT']));$claims=rsB64(json_encode(['iss'=>$service['client_email'],'scope'=>'https://www.googleapis.com/auth/drive.readonly','aud'=>'https://oauth2.googleapis.com/token','iat'=>$now,'exp'=>$now+3500]));$input=$header.'.'.$claims;$signature='';if(openssl_sign($input,$signature,$service['private_key'],OPENSSL_ALGO_SHA256)){$response=rsHttp('POST','https://oauth2.googleapis.com/token',['Content-Type: application/x-www-form-urlencoded'],http_build_query(['grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer','assertion'=>$input.'.'.rsB64($signature)]),30);$json=json_decode($response['body'],true);if($response['status']===200&&is_array($json)&&!empty($json['access_token']))return$token=(string)$json['access_token'];}}
    throw new RuntimeException('Google Drive ist für den Umsatzabgleich nicht verbunden.');
}
function rsGoogleSource():array {
    $headers=['Authorization: Bearer '.rsGoogleToken()];$id=rawurlencode(RS_GOOGLE_FILE_ID);$metaResponse=rsHttp('GET','https://www.googleapis.com/drive/v3/files/'.$id.'?supportsAllDrives=true&fields=id,name,mimeType,modifiedTime',$headers,null,60);$meta=json_decode($metaResponse['body'],true);if($metaResponse['status']!==200||!is_array($meta))throw new RuntimeException('Die Google-Umsatzdatei ist für den Server nicht lesbar (HTTP '.$metaResponse['status'].').');$fileResponse=rsHttp('GET','https://www.googleapis.com/drive/v3/files/'.$id.'?alt=media&supportsAllDrives=true',$headers,null,180);if($fileResponse['status']!==200)throw new RuntimeException('Die Google-Umsatzdatei konnte nicht geladen werden (HTTP '.$fileResponse['status'].').');return[['name'=>(string)($meta['name']??'CL Umsatzaufstellung Wächter.xlsx'),'lastModifiedDateTime'=>(string)($meta['modifiedTime']??'')],$fileResponse['body']];
}
function rsGraph(string $path,bool $binary=false):array|string {
    $response=rsHttp('GET','https://graph.microsoft.com/v1.0/'.$path,['Authorization: Bearer '.rsToken()],null,$binary?180:60);if($response['status']<200||$response['status']>=300)throw new RuntimeException('SharePoint konnte nicht gelesen werden (HTTP '.$response['status'].').');if($binary)return$response['body'];$json=json_decode($response['body'],true);if(!is_array($json))throw new RuntimeException('SharePoint hat keine gültigen Dateiinformationen geliefert.');return$json;
}
function rsGraphMaybe(string $path,bool $binary=false):array|string|null {
    $response=rsHttp('GET','https://graph.microsoft.com/v1.0/'.$path,['Authorization: Bearer '.rsToken()],null,$binary?180:60);if($response['status']<200||$response['status']>=300)return null;if($binary)return$response['body'];$json=json_decode($response['body'],true);return is_array($json)?$json:null;
}
function rsSourceFile():array {
    return rsGoogleSource();
}
function rsWorkbook(string $path):array {
    $zip=new ZipArchive();if($zip->open($path)!==true)throw new RuntimeException('Die Umsatzdatei ist keine lesbare Excel-Arbeitsmappe.');$shared=[];$sharedXml=$zip->getFromName('xl/sharedStrings.xml');if($sharedXml!==false&&($xml=simplexml_load_string((string)$sharedXml))!==false){foreach($xml->si as$si){$parts=[];if(isset($si->t))$parts[]=(string)$si->t;foreach($si->r as$run)if(isset($run->t))$parts[]=(string)$run->t;$shared[]=implode('',$parts);}}$bookXml=$zip->getFromName('xl/workbook.xml');$relsXml=$zip->getFromName('xl/_rels/workbook.xml.rels');$book=$bookXml!==false?simplexml_load_string((string)$bookXml):false;$rels=$relsXml!==false?simplexml_load_string((string)$relsXml):false;if($book===false||$rels===false){$zip->close();throw new RuntimeException('Die Tabellenstruktur der Umsatzdatei fehlt.');}$relMap=[];foreach($rels->Relationship as$rel)$relMap[(string)$rel['Id']]=(string)$rel['Target'];$namespaces=$book->getNamespaces(true);$rns=$namespaces['r']??null;$sheets=[];
    foreach($book->sheets->sheet as$sheet){$name=trim((string)$sheet['name']);$attrs=$rns?$sheet->attributes($rns):null;$rid=$attrs?(string)($attrs['id']??''):'';$target=$relMap[$rid]??'';if($target==='')continue;$sheetPath=ltrim($target,'/');if(!str_starts_with($sheetPath,'xl/'))$sheetPath='xl/'.$sheetPath;$raw=$zip->getFromName($sheetPath);if($raw===false)continue;$xml=simplexml_load_string((string)$raw);if($xml===false)continue;$cells=[];$formulas=[];foreach($xml->sheetData->row as$row)foreach($row->c as$cell){$ref=(string)$cell['r'];$type=(string)$cell['t'];$value='';if($type==='s')$value=$shared[(int)((string)$cell->v)]??'';elseif($type==='inlineStr'){$parts=[];if(isset($cell->is->t))$parts[]=(string)$cell->is->t;foreach($cell->is->r as$run)if(isset($run->t))$parts[]=(string)$run->t;$value=implode('',$parts);}else{$rawValue=(string)$cell->v;$value=$rawValue!==''&&is_numeric($rawValue)?(float)$rawValue:$rawValue;}if($value!==''&&$value!==null)$cells[$ref]=$value;if(isset($cell->f))$formulas[$ref]='='.(string)$cell->f;}$sheets[$name]=['cells'=>$cells,'formulas'=>$formulas];}
    $zip->close();return$sheets;
}
function rsRef(int $row,int $column):string {$letters='';for($n=$column;$n>0;$n=intdiv($n-1,26))$letters=chr(65+(($n-1)%26)).$letters;return$letters.$row;}
function rsCell(array $sheet,int $row,int $column):mixed {return$sheet['cells'][rsRef($row,$column)]??null;}
function rsMaxRow(array $sheet):int {$max=0;foreach(array_keys($sheet['cells'])as$ref)if(preg_match('/(\d+)$/',$ref,$m))$max=max($max,(int)$m[1]);return$max;}
function rsMonthGroups(array $sheet):array {
    $headers=[];$max=rsMaxRow($sheet);for($row=1;$row<=$max;$row++){$month=RS_MONTHS[rsText((string)rsCell($sheet,$row,1))]??0;if($month)$headers[]=[$row,$month];}$groups=[];foreach($headers as$index=>[$start,$month]){$end=$headers[$index+1][0]??($max+1);for($row=$start+1;$row<$end;$row++)if(rsText((string)rsCell($sheet,$row,1))==='gesamt'&&is_numeric(rsCell($sheet,$row,9)))$groups[$month][]=$row;}return$groups;
}
function rsCount(array $sheet,int $row):int {
    $total=(float)rsCell($sheet,$row,9);$average=rsCell($sheet,$row,13);if(is_numeric($average)&&(float)$average>0)return max(1,(int)round($total/(float)$average));$formula=(string)($sheet['formulas']['I'.$row]??'');if(!preg_match('/^=SUM\(I(\d+):I(\d+)\)$/i',$formula,$m))return 0;$count=0;for($source=(int)$m[1];$source<=(int)$m[2];$source++){$label=rsText((string)rsCell($sheet,$source,1));$amount=rsCell($sheet,$source,9);if(!in_array($label,['praemie','uebernachtungskosten'],true)&&is_numeric($amount)&&(float)$amount>0)$count++;}return$count;
}
function rsPeriod(array $sheet,int $year,int $through):array {
    $groups=rsMonthGroups($sheet);$rows=[];for($month=1;$month<=$through;$month++)foreach(($groups[$month]??[])as$row)$rows[]=$row;$total=0.0;$count=0;foreach($rows as$row){$total+=(float)rsCell($sheet,$row,9);$count+=rsCount($sheet,$row);}return['year'=>$year,'period'=>'Jan.–'.RS_MONTH_LABELS[$through].' '.$year,'months'=>$through,'ytd_net'=>round($total,2),'annualized_net'=>round($total/$through*12,2),'average_net'=>$count?round($total/$count,2):0.0];
}
function rsPrivate(array $sheets,array $years):array {
    $sheet=null;foreach($sheets as$name=>$candidate)if(str_starts_with(rsText($name),'privatauftr')){$sheet=$candidate;break;}if(!$sheet)throw new RuntimeException('Das Blatt Privataufträge fehlt.');$totals=array_fill_keys($years,0.0);$active=null;$max=rsMaxRow($sheet);for($row=1;$row<=$max;$row++){$a=rsCell($sheet,$row,1);if(is_numeric($a)&&isset($totals[(int)$a])){$active=(int)$a;continue;}if($active===null||rsText((string)$a)==='gesamt')continue;$amount=rsCell($sheet,$row,4);if(is_numeric($amount))$totals[$active]+=(float)$amount;}return array_map(fn($value)=>round((float)$value,2),$totals);
}
function rsRefresh(array $user):array {
    [$meta,$bytes]=rsSourceFile();$tmp=tempnam(sys_get_temp_dir(),'revenue-');if($tmp===false||file_put_contents($tmp,$bytes)===false)throw new RuntimeException('Die Umsatzdatei konnte nicht zwischengespeichert werden.');try{$sheets=rsWorkbook($tmp);}finally{@unlink($tmp);}$currentYear=(int)date('Y');$previousYear=$currentYear-1;if(!isset($sheets[(string)$currentYear],$sheets[(string)$previousYear]))throw new RuntimeException('Aktuelles oder vorheriges Jahresblatt fehlt.');$groups=rsMonthGroups($sheets[(string)$currentYear]);$completed=array_keys(array_filter($groups));if(!$completed)throw new RuntimeException('Im aktuellen Jahresblatt fehlt eine blaue Gesamtzeile.');$through=max($completed);$current=rsPeriod($sheets[(string)$currentYear],$currentYear,$through);$previous=rsPeriod($sheets[(string)$previousYear],$previousYear,$through);$private=rsPrivate($sheets,[$currentYear,$previousYear]);$current['private_gross']=$private[$currentYear];$previous['private_gross']=$private[$previousYear];$payload=['source'=>(string)($meta['name']??'CL Umsatzaufstellung Wächter.xlsx'),'source_updated_at'=>isset($meta['lastModifiedDateTime'])?date('d.m.Y H:i',strtotime((string)$meta['lastModifiedDateTime'])):date('d.m.Y H:i'),'comparison'=>'gleicher Zeitraum','current'=>$current,'previous'=>$previous];rsEnsureTable();$stmt=db()->prepare("INSERT INTO portal_revenue_summary(profile,payload_json,source_modified_at,updated_by,updated_at) VALUES('christian',:payload,:source,:user,NOW()) ON DUPLICATE KEY UPDATE payload_json=VALUES(payload_json),source_modified_at=VALUES(source_modified_at),updated_by=VALUES(updated_by),updated_at=NOW()");$stmt->execute([':payload'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),':source'=>(string)($meta['lastModifiedDateTime']??''),':user'=>(string)($user['email']??'')]);return$payload;
}

$action=(string)($_GET['action']??'summary');
if($action==='scheduled'){
    if($_SERVER['REQUEST_METHOD']!=='POST')apiError(405,'POST erforderlich.');
    $expected=rsCfg('SETUP_KEY');$provided=trim((string)($_SERVER['HTTP_X_SVNET_SCHEDULE_KEY']??''));
    if($expected===''||$provided===''||!hash_equals($expected,$provided))apiError(403,'Automationsschlüssel ungültig.');
    try{$payload=rsRefresh(['email'=>'server-automation@sv-netzwerk.eu','full_name'=>'Server-Automation']);apiJson(['ok'=>true,'scheduled'=>true,...$payload]);}
    catch(Throwable $error){error_log('[revenue-summary scheduled] '.$error->getMessage());apiError(503,$error->getMessage());}
}
$user=requireAuth();
if(!rsVisible($user))apiJson(['ok'=>true,'visible'=>false]);
try{
    if($action==='refresh'){if($_SERVER['REQUEST_METHOD']!=='POST')apiError(405,'POST erforderlich.');if(!rsCanRefresh($user))apiError(403,'Nur Susanne darf die Umsatzdatei manuell aktualisieren.');$payload=rsRefresh($user);apiJson(['ok'=>true,'visible'=>true,'can_refresh'=>true,...$payload]);}
    $payload=rsStored()??rsFallback();apiJson(['ok'=>true,'visible'=>true,'can_refresh'=>rsCanRefresh($user),...$payload]);
}catch(Throwable $error){error_log('[revenue-summary] '.$error->getMessage());apiError(503,$error->getMessage());}
