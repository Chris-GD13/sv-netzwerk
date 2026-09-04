<?php
declare(strict_types=1);

require_once __DIR__.'/config.php';
require_once __DIR__.'/profile-routing.php';

const RS_SOURCE_URL = 'https://sv1schuett-my.sharepoint.com/:x:/r/personal/ws_sv-schuett_eu/_layouts/15/Doc.aspx?sourcedoc=%7B368ACA1B-6D24-402A-816C-B7B40827CF07%7D&file=CL%20Umsatzaufstellung%20W%25u00e4chter.xlsx&fromShare=true&action=default&mobileredirect=true';
const RS_HOLGER_SOURCE_URL = 'https://1drv.ms/u/c/b09ce03dd5dcb502/IQD54mN-TXeFQ6hhpGj7ewM8AWXVLYIoZfTcMXHNL1Sf2nA?e=j6Fi3Z';
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
function rsIsKatja(array $user):bool {
    $identity=rsText((string)($user['email']??'').' '.(string)($user['full_name']??''));
    return str_contains($identity,'katja')||str_contains($identity,'schaefer');
}
function rsVisible(array $user):bool {
    return in_array(svnetUserProfile($user),['christian','marc'],true)||rsCanRefresh($user)||rsIsKatja($user);
}
function rsCanViewProfile(array $user,string $profile):bool {
    if(rsCanRefresh($user)||svnetUserProfile($user)==='christian')return true;
    return str_starts_with($profile,'rekon_')&&(rsIsKatja($user)||svnetUserProfile($user)==='marc');
}
function rsCanRefreshProfile(array $user,string $profile):bool {
    return rsCanRefresh($user)||(str_starts_with($profile,'rekon_')&&rsIsKatja($user));
}
function rsEnsureTable():void {
    db()->exec("CREATE TABLE IF NOT EXISTS portal_revenue_summary(profile VARCHAR(30) PRIMARY KEY,payload_json MEDIUMTEXT NOT NULL,source_modified_at VARCHAR(40) NULL,updated_by VARCHAR(255) NOT NULL,updated_at DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function rsStored(string $profile='christian'):?array {
    rsEnsureTable();$stmt=db()->prepare('SELECT payload_json FROM portal_revenue_summary WHERE profile=:profile LIMIT 1');$stmt->execute([':profile'=>$profile]);$decoded=json_decode((string)($stmt->fetchColumn()?:''),true);return is_array($decoded)?$decoded:null;
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
function rsSharePointSource():array {
    $share=rawurlencode(rsShareId());$meta=rsGraphMaybe('shares/'.$share.'/driveItem?%24select=id,name,lastModifiedDateTime');$bytes=is_array($meta)?rsGraphMaybe('shares/'.$share.'/driveItem/content',true):null;
    if(is_array($meta)&&is_string($bytes)){$meta['sourceProvider']='SharePoint / OneDrive';return[$meta,$bytes];}
    $site=rsGraphMaybe('sites/sv1schuett-my.sharepoint.com:/personal/ws_sv-schuett_eu:?%24select=id');$siteId=is_array($site)?(string)($site['id']??''):'';$file=rawurlencode('CL Umsatzaufstellung Wächter.xlsx');
    if($siteId!=='')foreach(['Desktop/'.$file,$file]as$path){$base='sites/'.rawurlencode($siteId).'/drive/root:/'.$path;$meta=rsGraphMaybe($base.':?%24select=id,name,lastModifiedDateTime');$bytes=is_array($meta)?rsGraphMaybe($base.':/content',true):null;if(is_array($meta)&&is_string($bytes)){$meta['sourceProvider']='SharePoint / OneDrive';return[$meta,$bytes];}}
    throw new RuntimeException('Die von Susanne bearbeitete SharePoint-Umsatzdatei ist für den Portalserver nicht lesbar.');
}
function rsRekonSharePointSources():array {
    $site=rsGraphMaybe('sites/sv1schuett.sharepoint.com:/sites/SVBroSchtt:?%24select=id');$siteId=is_array($site)?(string)($site['id']??''):'';
    if($siteId==='')throw new RuntimeException('Der SharePoint-Bereich Rekon ist für den Portalserver nicht lesbar.');
    $sources=[];foreach([(int)date('Y')-1,(int)date('Y')]as$year){$folder='Buchhaltung Intern/Rekon/'.$year.'/Schütt';$listing=rsGraphMaybe('sites/'.rawurlencode($siteId).'/drive/root:/'.str_replace('%2F','/',rawurlencode($folder)).':/children?%24select=id,name,lastModifiedDateTime,parentReference');if(!is_array($listing))continue;foreach(($listing['value']??[])as$item){$name=(string)($item['name']??'');if(!preg_match('/einzelposten.*\.(xlsx|xlsm)$/iu',$name))continue;$driveId=(string)($item['parentReference']['driveId']??'');$itemId=(string)($item['id']??'');if($driveId===''||$itemId==='')continue;$bytes=rsGraphMaybe('drives/'.rawurlencode($driveId).'/items/'.rawurlencode($itemId).'/content',true);if(is_string($bytes)&&$bytes!==''){$item['sourceProvider']='SharePoint Rekon';$sources[]=[$item,$bytes];}}}
    if(!$sources)throw new RuntimeException('Im Rekon-Ordner wurde keine lesbare Einzelpostenliste gefunden.');return$sources;
}
function rsSourceFile():array {
    return rsSharePointSource();
}
function rsUploadedSource():array {
    $upload=$_FILES['workbook']??null;
    if(!is_array($upload))throw new RuntimeException('Bitte die aktuell gespeicherte Umsatzdatei auswählen.');
    $error=(int)($upload['error']??UPLOAD_ERR_NO_FILE);
    if($error!==UPLOAD_ERR_OK)throw new RuntimeException($error===UPLOAD_ERR_INI_SIZE||$error===UPLOAD_ERR_FORM_SIZE?'Die ausgewählte Umsatzdatei ist zu groß.':'Die ausgewählte Umsatzdatei konnte nicht hochgeladen werden.');
    $name=basename(trim((string)($upload['name']??'')));$tmp=(string)($upload['tmp_name']??'');$size=(int)($upload['size']??0);$extension=mb_strtolower(pathinfo($name,PATHINFO_EXTENSION),'UTF-8');
    if(!in_array($extension,['xlsx','xlsm'],true))throw new RuntimeException('Bitte eine Excel-Arbeitsmappe im Format XLSX oder XLSM auswählen.');
    if($size<=0||$size>25*1024*1024)throw new RuntimeException('Die ausgewählte Umsatzdatei ist leer oder größer als 25 MB.');
    if($tmp===''||!is_uploaded_file($tmp))throw new RuntimeException('Der Datei-Upload konnte nicht sicher bestätigt werden.');
    $bytes=file_get_contents($tmp);if(!is_string($bytes)||$bytes==='')throw new RuntimeException('Die ausgewählte Umsatzdatei konnte nicht gelesen werden.');
    return[['name'=>$name,'lastModifiedDateTime'=>date(DATE_ATOM),'sourceProvider'=>'Manuell eingelesene Originaldatei'], $bytes];
}
function rsUploadedSources():array {
    $uploads=$_FILES['workbooks']??null;if(!is_array($uploads)||!is_array($uploads['name']??null))return[rsUploadedSource()];$sources=[];
    foreach($uploads['name']as$index=>$rawName){$error=(int)($uploads['error'][$index]??UPLOAD_ERR_NO_FILE);if($error!==UPLOAD_ERR_OK)throw new RuntimeException('Mindestens eine Rekon-Datei konnte nicht hochgeladen werden.');$name=basename(trim((string)$rawName));$tmp=(string)($uploads['tmp_name'][$index]??'');$size=(int)($uploads['size'][$index]??0);$extension=mb_strtolower(pathinfo($name,PATHINFO_EXTENSION),'UTF-8');if(!in_array($extension,['xlsx','xlsm'],true))throw new RuntimeException('Bitte nur Rekon-Arbeitsmappen im Format XLSX oder XLSM auswählen.');if($size<=0||$size>25*1024*1024||$tmp===''||!is_uploaded_file($tmp))throw new RuntimeException('Eine ausgewählte Rekon-Datei ist leer, zu groß oder konnte nicht sicher bestätigt werden.');$bytes=file_get_contents($tmp);if(!is_string($bytes)||$bytes==='')throw new RuntimeException('Eine ausgewählte Rekon-Datei konnte nicht gelesen werden.');$sources[]=[['name'=>$name,'lastModifiedDateTime'=>date(DATE_ATOM),'sourceProvider'=>'Manuell eingelesene Rekon-Originaldatei'],$bytes];}
    if(!$sources)throw new RuntimeException('Bitte mindestens eine Rekon-Einzelpostenliste auswählen.');return$sources;
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
function rsExcelDate(mixed $value):string {
    if(is_numeric($value)&&(float)$value>20000)return gmdate('d.m.Y',(int)round(((float)$value-25569)*86400));
    return trim((string)$value);
}
function rsNullableMoney(mixed $value):?float {
    return is_numeric($value)?round((float)$value,2):null;
}
function rsDistanceKm(array $sheet,int $row):?float {
    $source=rsCell($sheet,$row,12);
    if(is_numeric($source))return round((float)$source,2);
    $formula=str_replace([',',' '],['.',''],(string)($sheet['formulas']['F'.$row]??''));
    if(!preg_match('/^=\d+(?:\.\d+)?(?:\*\d+(?:\.\d+)?)+$/',$formula))return null;
    $factors=array_map('floatval',explode('*',substr($formula,1)));$rate=null;$product=1.0;
    foreach($factors as$factor){$product*=$factor;if($factor>0&&$factor<2&&($rate===null||$factor<$rate))$rate=$factor;}
    $amount=rsCell($sheet,$row,6);
    if($rate===null||!is_numeric($amount)||abs($product-(float)$amount)>0.02)return null;
    return round($product/$rate,2);
}
function rsEntries(array $sheet,int $year,int $through):array {
    $entries=[];$seen=[];$groups=rsMonthGroups($sheet);
    for($month=1;$month<=$through;$month++)foreach(($groups[$month]??[])as$totalRow){
        $formula=(string)($sheet['formulas']['I'.$totalRow]??'');
        if(!preg_match('/^=SUM\(I(\d+):I(\d+)\)$/i',$formula,$match))continue;
        for($row=(int)$match[1];$row<=(int)$match[2];$row++){
            if(isset($seen[$row]))continue;$amount=rsCell($sheet,$row,9);if(!is_numeric($amount)||(float)$amount==0.0)continue;
            $seen[$row]=true;$payout=round((float)$amount,2);$actual=round($payout/0.60,2);
            $regulierer=round($actual-$payout,2);$kmAmount=rsNullableMoney(rsCell($sheet,$row,6));$workAmount=rsNullableMoney(rsCell($sheet,$row,7));$travelAmount=rsNullableMoney(rsCell($sheet,$row,8));$entries[]=['year'=>$year,'month'=>$month,'month_label'=>RS_MONTH_LABELS[$month],'order_no'=>trim((string)rsCell($sheet,$row,1)),'customer'=>trim((string)rsCell($sheet,$row,2)),'place'=>'','scheduled'=>rsExcelDate(rsCell($sheet,$row,3)),'performed'=>rsExcelDate(rsCell($sheet,$row,4)),'billed'=>rsExcelDate(rsCell($sheet,$row,5)),'insurer'=>trim((string)rsCell($sheet,$row,11)),'distance_km'=>rsDistanceKm($sheet,$row),'km_amount_net'=>$kmAmount,'km_amount_gross'=>$kmAmount===null?null:round($kmAmount*1.19,2),'work_time_net'=>$workAmount,'work_time_gross'=>$workAmount===null?null:round($workAmount*1.19,2),'travel_time_net'=>$travelAmount,'travel_time_gross'=>$travelAmount===null?null:round($travelAmount*1.19,2),'payout_net'=>$payout,'payout_gross'=>round($payout*1.19,2),'actual_order_net'=>$actual,'actual_order_gross'=>round($actual*1.19,2),'regulierer_net'=>$regulierer,'regulierer_gross'=>round($regulierer*1.19,2),'damage_amount'=>is_numeric(rsCell($sheet,$row,10))?round((float)rsCell($sheet,$row,10),2):null];
        }
    }
    usort($entries,fn($a,$b)=>[$b['year'],$b['month'],$b['billed'],$b['order_no']]<=>[$a['year'],$a['month'],$a['billed'],$a['order_no']]);return$entries;
}
function rsAddShares(array $period):array {
    $actual=round((float)$period['ytd_net']/0.60,2);$annualActual=round((float)$period['annualized_net']/0.60,2);
    $regulierer=round($actual-(float)$period['ytd_net'],2);$annualRegulierer=round($annualActual-(float)$period['annualized_net'],2);$period['share_rate']=0.60;$period['vat_rate']=0.19;$period['ytd_gross']=round((float)$period['ytd_net']*1.19,2);$period['actual_order_net']=$actual;$period['actual_order_gross']=round($actual*1.19,2);$period['regulierer_net']=$regulierer;$period['regulierer_gross']=round($regulierer*1.19,2);$period['annualized_actual_order_net']=$annualActual;$period['annualized_actual_order_gross']=round($annualActual*1.19,2);$period['annualized_regulierer_net']=$annualRegulierer;$period['annualized_regulierer_gross']=round($annualRegulierer*1.19,2);return$period;
}
function rsPrivate(array $sheets,array $years):array {
    $sheet=null;foreach($sheets as$name=>$candidate)if(str_starts_with(rsText($name),'privatauftr')){$sheet=$candidate;break;}if(!$sheet)throw new RuntimeException('Das Blatt Privataufträge fehlt.');$totals=array_fill_keys($years,0.0);$active=null;$max=rsMaxRow($sheet);for($row=1;$row<=$max;$row++){$a=rsCell($sheet,$row,1);if(is_numeric($a)&&isset($totals[(int)$a])){$active=(int)$a;continue;}if($active===null||rsText((string)$a)==='gesamt')continue;$amount=rsCell($sheet,$row,4);if(is_numeric($amount))$totals[$active]+=(float)$amount;}return array_map(fn($value)=>round((float)$value,2),$totals);
}
function rsPrivateEntries(array $sheets,array $years):array {
    $sheet=null;foreach($sheets as$name=>$candidate)if(str_starts_with(rsText($name),'privatauftr')){$sheet=$candidate;break;}if(!$sheet)return[];
    $allowed=array_fill_keys($years,true);$active=null;$entries=[];$max=rsMaxRow($sheet);
    for($row=1;$row<=$max;$row++){$a=rsCell($sheet,$row,1);if(is_numeric($a)&&isset($allowed[(int)$a])){$active=(int)$a;continue;}if($active===null||rsText((string)$a)==='gesamt')continue;$amount=rsCell($sheet,$row,4);if(!is_numeric($amount)||(float)$amount==0.0)continue;$entries[]=['year'=>$active,'customer'=>trim((string)$a),'place'=>trim((string)rsCell($sheet,$row,2)),'appointment'=>rsExcelDate(rsCell($sheet,$row,3)),'gross'=>round((float)$amount,2)];}
    usort($entries,fn($a,$b)=>[$b['year'],$b['appointment'],$b['customer']]<=>[$a['year'],$a['appointment'],$a['customer']]);return$entries;
}
function rsPersist(string $profile,array $payload,array $meta,array $user):array {
    rsEnsureTable();$stmt=db()->prepare('INSERT INTO portal_revenue_summary(profile,payload_json,source_modified_at,updated_by,updated_at) VALUES(:profile,:payload,:source,:user,NOW()) ON DUPLICATE KEY UPDATE payload_json=VALUES(payload_json),source_modified_at=VALUES(source_modified_at),updated_by=VALUES(updated_by),updated_at=NOW()');$stmt->execute([':profile'=>$profile,':payload'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),':source'=>(string)($meta['lastModifiedDateTime']??''),':user'=>(string)($user['email']??'')]);return$payload;
}
function rsRefresh(array $user,?array $source=null):array {
    [$meta,$bytes]=$source??rsSourceFile();$tmp=tempnam(sys_get_temp_dir(),'revenue-');
    if($tmp===false||file_put_contents($tmp,$bytes)===false)throw new RuntimeException('Die Umsatzdatei konnte nicht zwischengespeichert werden.');
    try{$sheets=rsWorkbook($tmp);}finally{@unlink($tmp);}
    $currentYear=(int)date('Y');$previousYear=$currentYear-1;
    if(!isset($sheets[(string)$currentYear],$sheets[(string)$previousYear]))throw new RuntimeException('Aktuelles oder vorheriges Jahresblatt fehlt.');
    $groups=rsMonthGroups($sheets[(string)$currentYear]);$completed=array_keys(array_filter($groups));
    if(!$completed)throw new RuntimeException('Im aktuellen Jahresblatt fehlt eine blaue Gesamtzeile.');
    $through=max($completed);$current=rsAddShares(rsPeriod($sheets[(string)$currentYear],$currentYear,$through));$previous=rsAddShares(rsPeriod($sheets[(string)$previousYear],$previousYear,$through));
    $private=rsPrivate($sheets,[$currentYear,$previousYear]);$current['private_gross']=$private[$currentYear];$previous['private_gross']=$private[$previousYear];
    $availableYears=[];$entries=[];
    foreach($sheets as$name=>$sheet){if(!preg_match('/^20\d{2}$/',trim((string)$name)))continue;$year=(int)$name;$yearGroups=rsMonthGroups($sheet);$yearMonths=array_keys(array_filter($yearGroups));if(!$yearMonths)continue;$availableYears[]=$year;$entries=array_merge($entries,rsEntries($sheet,$year,max($yearMonths)));}
    rsort($availableYears);$privateEntries=rsPrivateEntries($sheets,$availableYears);
    $payload=['source'=>(string)($meta['name']??'CL Umsatzaufstellung Wächter.xlsx'),'source_provider'=>(string)($meta['sourceProvider']??'SharePoint / OneDrive'),'source_updated_at'=>isset($meta['lastModifiedDateTime'])?date('d.m.Y H:i',strtotime((string)$meta['lastModifiedDateTime'])):date('d.m.Y H:i'),'comparison'=>'gleicher Zeitraum','share_rate'=>0.60,'vat_rate'=>0.19,'entry_schema_version'=>2,'current'=>$current,'previous'=>$previous,'available_years'=>$availableYears,'entries'=>$entries,'private_entries'=>$privateEntries];
    return rsPersist('christian',$payload,$meta,$user);
}
function rsHolgerRanges(array $sheet):array {
    $ranges=[];$max=rsMaxRow($sheet);
    for($row=1;$row<=$max;$row++){$label=rsText((string)rsCell($sheet,$row,1));if(preg_match('/^(20\d{2}) gesamtumsatz/',$label,$year)&&preg_match('/^=SUM\(D(\d+):D(\d+)\)$/i',(string)($sheet['formulas']['D'.$row]??''),$range))$ranges[(int)$year[1]]=[(int)$range[1],(int)$range[2]];}
    $headers=[];for($row=1;$row<=$max;$row++){$value=rsCell($sheet,$row,1);if(is_numeric($value)&&(int)$value>=2000&&(int)$value<=2100)$headers[]=[(int)$value,$row];}
    foreach($headers as$index=>[$year,$row])if(!isset($ranges[$year]))$ranges[$year]=[$row+1,($headers[$index+1][1]??($max+1))-1];
    ksort($ranges);return$ranges;
}
function rsHolgerEntries(array $sheet):array {
    $entries=[];foreach(rsHolgerRanges($sheet)as$year=>[$start,$end])for($row=$start;$row<=$end;$row++){
        $incomeNet=rsNullableMoney(rsCell($sheet,$row,3));$incomeGross=rsNullableMoney(rsCell($sheet,$row,4));if($incomeNet===null||$incomeGross===null||$incomeGross==0.0)continue;
        $date=rsExcelDate(rsCell($sheet,$row,2));$month=0;if(preg_match('/^\d{2}\.(\d{2})\.\d{4}$/',$date,$match))$month=(int)$match[1];if($month<1||$month>12)continue;
        $officeGross=rsNullableMoney(rsCell($sheet,$row,7))??0.0;$officeNet=round($officeGross/1.19,2);$ourNet=rsNullableMoney(rsCell($sheet,$row,5))??round($incomeNet*0.10,2);$ourGross=rsNullableMoney(rsCell($sheet,$row,6))??round($incomeGross*0.10,2);$billingGross=rsNullableMoney(rsCell($sheet,$row,8))??round($ourGross+$officeGross,2);$billingNet=round($ourNet+$officeNet,2);$actualNet=round($incomeNet/0.60,2);$actualGross=round($incomeGross/0.60,2);$reguliererNet=round($actualNet-$incomeNet,2);$reguliererGross=round($actualGross-$incomeGross,2);
        $entries[]=['year'=>$year,'month'=>$month,'month_label'=>RS_MONTH_LABELS[$month],'service_period'=>rsExcelDate(rsCell($sheet,$row,1)),'credit_date'=>$date,'income_net'=>$incomeNet,'income_gross'=>$incomeGross,'actual_order_net'=>$actualNet,'actual_order_gross'=>$actualGross,'regulierer_net'=>$reguliererNet,'regulierer_gross'=>$reguliererGross,'our_share_net'=>$ourNet,'our_share_gross'=>$ourGross,'office_net'=>$officeNet,'office_gross'=>$officeGross,'our_total_net'=>$billingNet,'our_total_gross'=>$billingGross,'holger_after_share_net'=>round($incomeNet-$ourNet,2),'holger_after_share_gross'=>round($incomeGross-$ourGross,2),'holger_after_total_net'=>round($incomeNet-$billingNet,2),'holger_after_total_gross'=>round($incomeGross-$billingGross,2)];
    }usort($entries,fn($a,$b)=>[$b['year'],$b['month'],$b['credit_date']]<=>[$a['year'],$a['month'],$a['credit_date']]);return$entries;
}
function rsHolgerPeriod(array $entries,int $year,int $through):array {
    $rows=array_values(array_filter($entries,fn($row)=>(int)$row['year']===$year&&(int)$row['month']<=$through));$sum=fn(string$key)=>round(array_sum(array_map(fn($row)=>(float)($row[$key]??0),$rows)),2);$count=count($rows);$actualNet=$sum('actual_order_net');$actualGross=$sum('actual_order_gross');
    return['year'=>$year,'period'=>'Jan.–'.RS_MONTH_LABELS[$through].' '.$year,'months'=>$through,'booking_count'=>$count,'average_actual_order_net'=>$count?round($actualNet/$count,2):0.0,'average_actual_order_gross'=>$count?round($actualGross/$count,2):0.0,'income_net'=>$sum('income_net'),'income_gross'=>$sum('income_gross'),'actual_order_net'=>$actualNet,'actual_order_gross'=>$actualGross,'regulierer_net'=>$sum('regulierer_net'),'regulierer_gross'=>$sum('regulierer_gross'),'our_share_net'=>$sum('our_share_net'),'our_share_gross'=>$sum('our_share_gross'),'office_net'=>$sum('office_net'),'office_gross'=>$sum('office_gross'),'our_total_net'=>$sum('our_total_net'),'our_total_gross'=>$sum('our_total_gross'),'holger_after_share_net'=>$sum('holger_after_share_net'),'holger_after_share_gross'=>$sum('holger_after_share_gross'),'holger_after_total_net'=>$sum('holger_after_total_net'),'holger_after_total_gross'=>$sum('holger_after_total_gross')];
}
function rsRefreshHolger(array $user,array $source):array {
    [$meta,$bytes]=$source;$tmp=tempnam(sys_get_temp_dir(),'revenue-holger-');if($tmp===false||file_put_contents($tmp,$bytes)===false)throw new RuntimeException('Holgers Umsatzdatei konnte nicht zwischengespeichert werden.');try{$sheets=rsWorkbook($tmp);}finally{@unlink($tmp);}if(!$sheets)throw new RuntimeException('Holgers Umsatzdatei enthält kein lesbares Tabellenblatt.');$sheet=array_values($sheets)[0];$entries=rsHolgerEntries($sheet);if(!$entries)throw new RuntimeException('In Holgers Umsatzdatei wurden keine abrechenbaren Gutschriften gefunden.');$years=array_values(array_unique(array_map(fn($row)=>(int)$row['year'],$entries)));rsort($years);$currentYear=(int)date('Y');$previousYear=$currentYear-1;$currentMonths=array_map(fn($row)=>(int)$row['month'],array_filter($entries,fn($row)=>(int)$row['year']===$currentYear));if(!$currentMonths)throw new RuntimeException('In Holgers Umsatzdatei fehlen Buchungen für das aktuelle Jahr.');$through=max($currentMonths);$payload=['profile'=>'holger','source'=>(string)($meta['name']??'Holger Roth.xlsx'),'source_provider'=>(string)($meta['sourceProvider']??'Manuell eingelesene Originaldatei'),'source_url'=>RS_HOLGER_SOURCE_URL,'source_updated_at'=>date('d.m.Y H:i',strtotime((string)($meta['lastModifiedDateTime']??'now'))),'comparison'=>'gleicher Zeitraum','share_rate'=>0.60,'office_share_rate'=>0.10,'vat_rate'=>0.19,'current'=>rsHolgerPeriod($entries,$currentYear,$through),'previous'=>rsHolgerPeriod($entries,$previousYear,$through),'available_years'=>$years,'entries'=>$entries];return rsPersist('holger',$payload,$meta,$user);
}
function rsHolgerEmpty():array {
    return['profile'=>'holger','source'=>'Holger Roth.xlsx','source_provider'=>'Noch nicht eingelesen','source_url'=>RS_HOLGER_SOURCE_URL,'source_updated_at'=>'–','comparison'=>'gleicher Zeitraum','share_rate'=>0.60,'office_share_rate'=>0.10,'vat_rate'=>0.19,'current'=>[],'previous'=>[],'available_years'=>[],'entries'=>[]];
}
function rsRekonIdentity(string $value):string {
    $text=rsText($value);if(str_contains($text,'holger')||str_contains($text,'roth'))return'rekon_holger';if(str_contains($text,'marc')||str_contains($text,'schuett'))return'rekon_marc';return'';
}
function rsRekonEntries(array $sources):array {
    $entries=[];foreach($sources as[$meta,$bytes]){$tmp=tempnam(sys_get_temp_dir(),'revenue-rekon-');if($tmp===false||file_put_contents($tmp,$bytes)===false)throw new RuntimeException('Eine Rekon-Datei konnte nicht zwischengespeichert werden.');try{$sheets=rsWorkbook($tmp);}finally{@unlink($tmp);}foreach($sheets as$sheetName=>$sheet){$month=RS_MONTHS[rsText((string)$sheetName)]??0;if(!$month&&preg_match('/^(\d{4})-(\d{2})/',trim((string)rsCell($sheet,9,3)),$dateMatch))$month=(int)$dateMatch[2];$period=trim((string)rsCell($sheet,9,3));$year=preg_match('/(20\d{2})/',$period,$yearMatch)?(int)$yearMatch[1]:0;if(!$year||$month<1||$month>12)continue;$max=rsMaxRow($sheet);for($row=1;$row<=$max;$row++){$id=trim((string)rsCell($sheet,$row,2));$payout=rsNullableMoney(rsCell($sheet,$row,6));$profile=rsRekonIdentity((string)rsCell($sheet,$row,5));if($id===''||$payout===null||$payout<=0||$profile==='')continue;$actual=round($payout/0.60,2);$rekon=round($actual-$payout,2);$entries[]=['profile'=>$profile,'year'=>$year,'month'=>$month,'month_label'=>RS_MONTH_LABELS[$month],'id'=>$id,'claim_no'=>trim((string)rsCell($sheet,$row,3)),'insurer'=>trim((string)rsCell($sheet,$row,4)),'regulator'=>trim((string)rsCell($sheet,$row,5)),'invoice_date'=>rsExcelDate(rsCell($sheet,$row,7)),'appointment'=>rsExcelDate(rsCell($sheet,$row,8)),'invoice_type'=>trim((string)rsCell($sheet,$row,10)),'payout_net'=>$payout,'payout_gross'=>round($payout*1.19,2),'actual_order_net'=>$actual,'actual_order_gross'=>round($actual*1.19,2),'rekon_net'=>$rekon,'rekon_gross'=>round($rekon*1.19,2),'source'=>(string)($meta['name']??'Rekon-Einzelpostenliste')];}}}
    usort($entries,fn($a,$b)=>[$b['year'],$b['month'],$b['invoice_date'],$b['id']]<=>[$a['year'],$a['month'],$a['invoice_date'],$a['id']]);return$entries;
}
function rsRekonPeriod(array $entries,int $year,int $through):array {
    $rows=array_values(array_filter($entries,fn($row)=>(int)$row['year']===$year&&(int)$row['month']<=$through));$sum=fn(string$key)=>round(array_sum(array_map(fn($row)=>(float)($row[$key]??0),$rows)),2);$count=count($rows);$payout=$sum('payout_net');
    return['year'=>$year,'period'=>'Jan.–'.RS_MONTH_LABELS[$through].' '.$year,'months'=>$through,'booking_count'=>$count,'payout_net'=>$payout,'payout_gross'=>$sum('payout_gross'),'actual_order_net'=>$sum('actual_order_net'),'actual_order_gross'=>$sum('actual_order_gross'),'rekon_net'=>$sum('rekon_net'),'rekon_gross'=>$sum('rekon_gross'),'average_net'=>$count?round($payout/$count,2):0.0,'annualized_payout_net'=>round($payout/$through*12,2),'annualized_payout_gross'=>round($sum('payout_gross')/$through*12,2)];
}
function rsRekonPayload(string $profile,array $entries,array $sources):array {
    $own=array_values(array_filter($entries,fn($row)=>$row['profile']===$profile));$years=array_values(array_unique(array_map(fn($row)=>(int)$row['year'],$own)));rsort($years);$currentYear=(int)date('Y');$previousYear=$currentYear-1;$months=array_map(fn($row)=>(int)$row['month'],array_filter($own,fn($row)=>(int)$row['year']===$currentYear));$through=$months?max($months):max(1,(int)date('n'));$names=array_values(array_unique(array_map(fn($source)=>(string)($source[0]['name']??'Rekon-Einzelpostenliste'),$sources)));$modified=array_values(array_filter(array_map(fn($source)=>(string)($source[0]['lastModifiedDateTime']??''),$sources)));sort($modified);return['profile'=>$profile,'provider'=>'rekon','person'=>$profile==='rekon_holger'?'Holger Roth':'Marc Schütt','source'=>implode(', ',$names),'source_provider'=>(string)($sources[0][0]['sourceProvider']??'Rekon'),'source_updated_at'=>$modified?date('d.m.Y H:i',strtotime((string)end($modified))):date('d.m.Y H:i'),'comparison'=>'gleicher Zeitraum','share_rate'=>0.60,'vat_rate'=>0.19,'current'=>rsRekonPeriod($own,$currentYear,$through),'previous'=>rsRekonPeriod($own,$previousYear,$through),'available_years'=>$years,'entries'=>$own];
}
function rsRefreshRekon(array $user,array $sources,string $requested=''):array {
    $entries=rsRekonEntries($sources);if(!$entries)throw new RuntimeException('In den Rekon-Dateien wurden keine abrechenbaren Einzelposten für Marc oder Holger gefunden.');$saved=[];foreach(['rekon_marc','rekon_holger']as$profile){$own=array_filter($entries,fn($row)=>$row['profile']===$profile);if(!$own)continue;$payload=rsRekonPayload($profile,$entries,$sources);$meta=['lastModifiedDateTime'=>date(DATE_ATOM)];rsPersist($profile,$payload,$meta,$user);$saved[$profile]=$payload;}if($requested!==''&&!isset($saved[$requested]))throw new RuntimeException($requested==='rekon_holger'?'In den ausgewählten Rekon-Dateien wurden noch keine Holger-Positionen gefunden.':'In den ausgewählten Rekon-Dateien wurden keine Marc-Positionen gefunden.');return$requested!==''?$saved[$requested]:$saved;
}
function rsRekonEmpty(string $profile):array {
    return['profile'=>$profile,'provider'=>'rekon','person'=>$profile==='rekon_holger'?'Holger Roth':'Marc Schütt','source'=>'Rekon-Einzelpostenlisten','source_provider'=>'Noch nicht eingelesen','source_updated_at'=>'–','comparison'=>'gleicher Zeitraum','share_rate'=>0.60,'vat_rate'=>0.19,'current'=>[],'previous'=>[],'available_years'=>[],'entries'=>[]];
}

$action=(string)($_GET['action']??'summary');
$profile=rsText((string)($_GET['profile']??'christian'));if(!in_array($profile,['christian','holger','rekon_marc','rekon_holger'],true))$profile='christian';
if($action==='scheduled'){
    if($_SERVER['REQUEST_METHOD']!=='POST')apiError(405,'POST erforderlich.');
    $expected=rsCfg('SETUP_KEY');$provided=trim((string)($_SERVER['HTTP_X_SVNET_SCHEDULE_KEY']??''));
    if($expected===''||$provided===''||!hash_equals($expected,$provided))apiError(403,'Automationsschlüssel ungültig.');
    try{$payload=rsRefresh(['email'=>'server-automation@sv-netzwerk.eu','full_name'=>'Server-Automation']);apiJson(['ok'=>true,'scheduled'=>true,...$payload]);}
    catch(Throwable $error){error_log('[revenue-summary scheduled] '.$error->getMessage());apiError(503,$error->getMessage());}
}
if($action==='scheduled_rekon'){
    if($_SERVER['REQUEST_METHOD']!=='POST')apiError(405,'POST erforderlich.');$expected=rsCfg('SETUP_KEY');$provided=trim((string)($_SERVER['HTTP_X_SVNET_SCHEDULE_KEY']??''));if($expected===''||$provided===''||!hash_equals($expected,$provided))apiError(403,'Automationsschlüssel ungültig.');
    try{$payloads=rsRefreshRekon(['email'=>'server-automation@sv-netzwerk.eu','full_name'=>'Server-Automation'],rsRekonSharePointSources());apiJson(['ok'=>true,'scheduled'=>true,'profiles'=>array_keys($payloads)]);}catch(Throwable $error){error_log('[revenue-summary scheduled rekon] '.$error->getMessage());apiError(503,$error->getMessage());}
}
$user=requireAuth();
if($action==='access'){if(!rsVisible($user))apiJson(['ok'=>true,'visible'=>false]);if(rsCanViewProfile($user,'christian')){$payload=rsStored('christian')??rsFallback();unset($payload['entries'],$payload['private_entries'],$payload['available_years']);apiJson(['ok'=>true,'visible'=>true,'show_summary'=>true,'can_refresh'=>rsCanRefreshProfile($user,'christian'),...$payload]);}apiJson(['ok'=>true,'visible'=>true,'show_summary'=>false,'default_profile'=>'rekon_marc']);}
if(!rsVisible($user)||!rsCanViewProfile($user,$profile))apiJson(['ok'=>true,'visible'=>false,'default_profile'=>(rsIsKatja($user)||svnetUserProfile($user)==='marc')?'rekon_marc':null]);
try{
    if($action==='refresh'){if($_SERVER['REQUEST_METHOD']!=='POST')apiError(405,'POST erforderlich.');if(!rsCanRefreshProfile($user,$profile))apiError(403,str_starts_with($profile,'rekon_')?'Nur Katja oder Susanne dürfen Rekon manuell aktualisieren.':'Nur Susanne darf die Umsatzdatei manuell aktualisieren.');if(str_starts_with($profile,'rekon_'))$payload=rsRefreshRekon($user,rsUploadedSources(),$profile);else{$source=rsUploadedSource();$payload=$profile==='holger'?rsRefreshHolger($user,$source):rsRefresh($user,$source);}apiJson(['ok'=>true,'visible'=>true,'can_refresh'=>true,...$payload]);}
    $payload=rsStored($profile)??($profile==='holger'?rsHolgerEmpty():(str_starts_with($profile,'rekon_')?rsRekonEmpty($profile):rsFallback()));if($action!=='settlement'){unset($payload['entries'],$payload['private_entries'],$payload['available_years']);}apiJson(['ok'=>true,'visible'=>true,'can_refresh'=>$action==='settlement'&&rsCanRefreshProfile($user,$profile),...$payload]);
}catch(Throwable $error){error_log('[revenue-summary] '.$error->getMessage());apiError(503,$error->getMessage());}
