<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
commonHeaders();
$user=requireAuth();
if(!in_array((string)($user['role']??''),['administrator','projektleiter','pruefer','sachverstaendiger'],true)) apiError(403,'Keine Berechtigung.');

const BKI_FOLDER_ID='1NQ3XO8qfHb92E6wqFU0kQjyIovsq98Ec';
const BKI_POSITIONS_ID='1X6IoAbQ0BKmlFGBb9ZOR4vh4nIJ_6r-p';
const BKI_BUILDINGS_ID='1mAAPIkhN1NqtezkDEiZ4rPzyJg5Cn3aE';

function bkSchema():void{
  db()->exec("CREATE TABLE IF NOT EXISTS bki_calculations(
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    folder_id VARCHAR(190) NULL,
    case_no VARCHAR(190) NULL,
    damage_type VARCHAR(190) NULL,
    object_name VARCHAR(500) NULL,
    location VARCHAR(300) NULL,
    note TEXT NULL,
    net_total DECIMAL(15,2) NOT NULL DEFAULT 0,
    vat_rate DECIMAL(8,3) NOT NULL DEFAULT 19,
    vat_total DECIMAL(15,2) NOT NULL DEFAULT 0,
    gross_total DECIMAL(15,2) NOT NULL DEFAULT 0,
    items_json LONGTEXT NOT NULL,
    created_by VARCHAR(190) NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_bki_folder(folder_id), INDEX idx_bki_case(case_no), INDEX idx_bki_created(created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function bkSettingGet(string $k,string $d=''):string{try{db()->exec("CREATE TABLE IF NOT EXISTS app_settings(setting_key VARCHAR(190) PRIMARY KEY,setting_value MEDIUMTEXT NULL,updated_at DATETIME NOT NULL,updated_by VARCHAR(190) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");$s=db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key=:k LIMIT 1');$s->execute([':k'=>$k]);$v=$s->fetchColumn();return$v===false?$d:(string)$v;}catch(Throwable){return$d;}}
function bkSettingSet(string $k,string $v):void{try{$s=db()->prepare('INSERT INTO app_settings(setting_key,setting_value,updated_at,updated_by) VALUES(:k,:v,NOW(),:u) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=NOW(),updated_by=VALUES(updated_by)');$s->execute([':k'=>$k,':v'=>$v,':u'=>'bki-calculator']);}catch(Throwable){}}
function bkB64url(string $s):string{return rtrim(strtr(base64_encode($s),'+/','-_'),'=');}
function bkHttp(string $method,string $url,array $headers=[],?string $body=null,int $timeout=240):array{$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>$timeout,CURLOPT_FOLLOWLOCATION=>true]);if($body!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,$body);$r=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);if($r===false||$err!=='')throw new RuntimeException('Verbindung fehlgeschlagen: '.($err?:'unbekannter Fehler'));return['status'=>$status,'body'=>(string)$r];}
function bkGoogleToken():string{static$t=null;if($t!==null)return$t;$svcJson=trim(env('GOOGLE_DRIVE_SERVICE_ACCOUNT_JSON',''));if($svcJson!==''){if(!str_starts_with($svcJson,'{')){$d=base64_decode($svcJson,true);if($d!==false)$svcJson=$d;}$svc=json_decode($svcJson,true);if(is_array($svc)&&!empty($svc['client_email'])&&!empty($svc['private_key'])){$now=time();$h=bkB64url(json_encode(['alg'=>'RS256','typ'=>'JWT']));$c=bkB64url(json_encode(['iss'=>$svc['client_email'],'scope'=>'https://www.googleapis.com/auth/drive','aud'=>'https://oauth2.googleapis.com/token','iat'=>$now,'exp'=>$now+3500]));$in=$h.'.'.$c;$sig='';if(openssl_sign($in,$sig,$svc['private_key'],OPENSSL_ALGO_SHA256)){$jwt=$in.'.'.bkB64url($sig);$r=bkHttp('POST','https://oauth2.googleapis.com/token',['Content-Type: application/x-www-form-urlencoded'],http_build_query(['grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer','assertion'=>$jwt]),90);$j=json_decode($r['body'],true);if($r['status']===200&&!empty($j['access_token']))return$t=(string)$j['access_token'];}}}$cid=env('GOOGLE_DRIVE_CLIENT_ID',bkSettingGet('google_drive_client_id'));$sec=env('GOOGLE_DRIVE_CLIENT_SECRET',bkSettingGet('google_drive_client_secret'));$ref=env('GOOGLE_DRIVE_REFRESH_TOKEN',bkSettingGet('google_drive_refresh_token'));if($cid!==''&&$sec!==''&&$ref!==''){$r=bkHttp('POST','https://oauth2.googleapis.com/token',['Content-Type: application/x-www-form-urlencoded'],http_build_query(['client_id'=>$cid,'client_secret'=>$sec,'refresh_token'=>$ref,'grant_type'=>'refresh_token']),90);$j=json_decode($r['body'],true);if($r['status']===200&&!empty($j['access_token']))return$t=(string)$j['access_token'];}throw new RuntimeException('Google Drive ist nicht verbunden.');}
function bkDriveMeta(string $id):array{$r=bkHttp('GET','https://www.googleapis.com/drive/v3/files/'.rawurlencode($id).'?'.http_build_query(['fields'=>'id,name,mimeType,modifiedTime,size,parents','supportsAllDrives'=>'true']),['Authorization: Bearer '.bkGoogleToken()],null,120);if($r['status']!==200)throw new RuntimeException('BKI-Datei konnte nicht gelesen werden.');$j=json_decode($r['body'],true);return is_array($j)?$j:[];}
function bkDriveBytes(string $id):array{$m=bkDriveMeta($id);$r=bkHttp('GET','https://www.googleapis.com/drive/v3/files/'.rawurlencode($id).'?alt=media&supportsAllDrives=true',['Authorization: Bearer '.bkGoogleToken()],null,300);if($r['status']!==200)throw new RuntimeException('BKI-Datei konnte nicht geladen werden.');return['name'=>(string)($m['name']??'BKI.pdf'),'mime'=>(string)($m['mimeType']??'application/pdf'),'modified'=>(string)($m['modifiedTime']??''),'bytes'=>$r['body']];}
function bkOpenAIFile(string $driveId):array{$meta=bkDriveMeta($driveId);$cacheKey='openai_bki_calc_'.$driveId;$cached=json_decode(bkSettingGet($cacheKey,'{}'),true);if(is_array($cached)&&($cached['modified']??'')===($meta['modifiedTime']??'')&&!empty($cached['file_id']))return['file_id'=>(string)$cached['file_id'],'name'=>(string)($cached['name']??$meta['name']??'BKI')];$apiKey=trim(env('OPENAI_API_KEY',''));if($apiKey==='')throw new RuntimeException('OpenAI API-Key ist nicht konfiguriert.');$d=bkDriveBytes($driveId);$tmp=tempnam(sys_get_temp_dir(),'bki-');if($tmp===false)throw new RuntimeException('Temporäre BKI-Datei konnte nicht erstellt werden.');file_put_contents($tmp,$d['bytes']);$ch=curl_init('https://api.openai.com/v1/files');curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$apiKey],CURLOPT_POSTFIELDS=>['purpose'=>'user_data','file'=>new CURLFile($tmp,$d['mime'],$d['name'])],CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>420]);$resp=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);@unlink($tmp);if($resp===false||$err!==''||$status<200||$status>=300)throw new RuntimeException('BKI-Datei konnte für die Suche nicht vorbereitet werden.');$j=json_decode((string)$resp,true);$fid=(string)($j['id']??'');if($fid==='')throw new RuntimeException('BKI-Datei-ID fehlt.');bkSettingSet($cacheKey,json_encode(['file_id'=>$fid,'modified'=>$d['modified'],'name'=>$d['name']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));return['file_id'=>$fid,'name'=>$d['name']];}
function bkOpenAIJson(string $method,string $path,?array $payload=null,int $timeout=360):array{
  $key=trim(env('OPENAI_API_KEY',''));
  if($key==='')throw new RuntimeException('OpenAI API-Key ist nicht konfiguriert.');
  $headers=['Authorization: Bearer '.$key];
  $body=null;
  if($payload!==null){$headers[]='Content-Type: application/json';$body=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
  $r=bkHttp($method,'https://api.openai.com/v1/'.ltrim($path,'/'),$headers,$body,$timeout);
  $j=$r['body']!==''?json_decode($r['body'],true):[];
  if($r['status']<200||$r['status']>=300){
    $message=is_array($j)&&!empty($j['error']['message'])?(string)$j['error']['message']:'HTTP '.$r['status'];
    throw new RuntimeException('OpenAI-Dateisuche fehlgeschlagen: '.$message);
  }
  return is_array($j)?$j:[];
}
function bkVectorStore():string{
  $configured=trim(env('OPENAI_BKI_VECTOR_STORE_ID',''));
  $cached=json_decode(bkSettingGet('openai_bki_vector_store','{}'),true);
  $storeId=$configured!==''?$configured:trim((string)($cached['vector_store_id']??''));
  if($storeId!==''){try{$existing=bkOpenAIJson('GET','vector_stores/'.rawurlencode($storeId),null,90);if(!empty($existing['id'])&&($existing['status']??'completed')!=='expired')return$storeId;}catch(Throwable){}}
  $positionFile=bkOpenAIFile(BKI_POSITIONS_ID);$buildingFile=bkOpenAIFile(BKI_BUILDINGS_ID);
  $store=bkOpenAIJson('POST','vector_stores',['name'=>'SV-Netzwerk BKI Altbau 2026','expires_after'=>['anchor'=>'last_active_at','days'=>30]],120);$storeId=(string)($store['id']??'');if($storeId==='')throw new RuntimeException('BKI-Suchindex konnte nicht angelegt werden.');
  $batch=bkOpenAIJson('POST','vector_stores/'.rawurlencode($storeId).'/file_batches',['file_ids'=>[(string)$positionFile['file_id'],(string)$buildingFile['file_id']]],180);$batchId=(string)($batch['id']??'');if($batchId==='')throw new RuntimeException('BKI-Dateien konnten dem Suchindex nicht zugeordnet werden.');
  $deadline=time()+300;do{$state=bkOpenAIJson('GET','vector_stores/'.rawurlencode($storeId).'/file_batches/'.rawurlencode($batchId),null,90);$status=(string)($state['status']??'');if($status==='completed')break;if(in_array($status,['failed','cancelled','expired'],true))throw new RuntimeException('BKI-Suchindex konnte nicht fertiggestellt werden.');usleep(800000);}while(time()<$deadline);if(($status??'')!=='completed')throw new RuntimeException('BKI-Suchindex wird noch aufgebaut. Bitte Suche in wenigen Sekunden erneut starten.');
  bkSettingSet('openai_bki_vector_store',json_encode(['vector_store_id'=>$storeId,'created_at'=>date(DATE_ATOM)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));return$storeId;
}
function bkOutputText(array $d):string{$t=trim((string)($d['output_text']??''));if($t!=='')return$t;foreach(($d['output']??[])as$item){if(($item['type']??'')!=='message')continue;foreach(($item['content']??[])as$p)if(($p['type']??'')==='output_text')$t.=(string)($p['text']??'');}return trim($t);}
function bkJson(string $t):array{if(preg_match('/```(?:json)?\s*(\{.*\})\s*```/s',$t,$m))$t=$m[1];$j=json_decode($t,true);if(is_array($j))return$j;$a=strpos($t,'{');$b=strrpos($t,'}');if($a!==false&&$b!==false&&$b>$a){$j=json_decode(substr($t,$a,$b-$a+1),true);if(is_array($j))return$j;}throw new RuntimeException('BKI-Suchergebnis konnte nicht gelesen werden.');}
function bkOpenAIUploadBytes(string $name,string $mime,string $bytes):string{
  $tmp=tempnam(sys_get_temp_dir(),'kva-bki-');
  if($tmp===false)throw new RuntimeException('KVA konnte nicht vorbereitet werden.');
  try{
    file_put_contents($tmp,$bytes);
    $key=trim(env('OPENAI_API_KEY',''));
    if($key==='')throw new RuntimeException('OpenAI API-Key ist nicht konfiguriert.');
    $ch=curl_init('https://api.openai.com/v1/files');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$key],CURLOPT_POSTFIELDS=>['purpose'=>'user_data','file'=>new CURLFile($tmp,$mime,$name)],CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>420]);
    $response=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$error=curl_error($ch);curl_close($ch);
    if($response===false||$error!==''||$status<200||$status>=300)throw new RuntimeException('KVA konnte nicht für die Auswertung bereitgestellt werden.');
    $json=json_decode((string)$response,true);$id=trim((string)($json['id']??''));
    if($id==='')throw new RuntimeException('KVA-Datei-ID fehlt.');
    return$id;
  }finally{@unlink($tmp);}
}
function bkAnalyzeKva(string $name,string $mime,string $bytes):array{
  if($bytes==='')throw new RuntimeException('KVA-Datei ist leer.');
  $fileId=bkOpenAIUploadBytes($name,$mime,$bytes);
  $instructions=<<<'PROMPT'
Lies den deutschen Kostenvoranschlag vollständig und extrahiere die angebotenen Leistungspositionen als Kalkulationsgrundlage. Übernimm keine Summenzeilen, Zwischensummen, Umsatzsteuer oder Rabatte als Leistungsposition. Fasse eine Position nur dann zusammen, wenn sie im Dokument selbst zusammengefasst ist. Erfinde keine Mengen, Einheiten, Beschreibungen oder Preise.

Antworte ausschließlich als JSON:
{"quote_number":"","company":"","net_total":null,"positions":[{"source_position":"","description":"","quantity":null,"unit":"","offered_unit_price":null,"offered_total":null}]}
PROMPT;
  $response=bkOpenAIJson('POST','responses',[
    'model'=>env('OPENAI_MODEL','gpt-5.4-mini'),
    'instructions'=>$instructions,
    'input'=>[['role'=>'user','content'=>[['type'=>'input_text','text'=>'Extrahiere alle kalkulierbaren Leistungspositionen aus diesem KVA.'],['type'=>'input_file','file_id'=>$fileId]]]],
    'max_output_tokens'=>8000
  ],420);
  $raw=bkJson(bkOutputText($response));$positions=[];
  foreach(($raw['positions']??[])as$row){
    if(!is_array($row))continue;$description=trim((string)($row['description']??''));
    if($description==='')continue;
    $positions[]=['source_position'=>trim((string)($row['source_position']??'')),'description'=>$description,'quantity'=>is_numeric($row['quantity']??null)?(float)$row['quantity']:null,'unit'=>trim((string)($row['unit']??'')),'offered_unit_price'=>is_numeric($row['offered_unit_price']??null)?(float)$row['offered_unit_price']:null,'offered_total'=>is_numeric($row['offered_total']??null)?(float)$row['offered_total']:null];
  }
  if(!$positions)throw new RuntimeException('Im KVA wurden keine belastbaren Leistungspositionen erkannt.');
  return['source_name'=>$name,'quote_number'=>trim((string)($raw['quote_number']??'')),'company'=>trim((string)($raw['company']??'')),'net_total'=>is_numeric($raw['net_total']??null)?(float)$raw['net_total']:null,'positions'=>$positions];
}
function bkSearch(array $in):array{
  $q=trim((string)($in['query']??''));
  if($q==='')throw new RuntimeException('Leistungsbeschreibung fehlt.');
  $location=trim((string)($in['location']??''));
  $qty=trim((string)($in['quantity']??''));
  $unit=trim((string)($in['unit']??''));
  $case=is_array($in['case_meta']??null)?$in['case_meta']:[];
  $vectorStoreId=bkVectorStore();
  $instructions=<<<'PROMPT'
Du arbeitest ausschließlich mit den über die Dateisuche zugänglichen lizenzierten BKI-Unterlagen "BKI Baukosten Positionen Altbau 2026" und "BKI Baukosten Gebäude Altbau 2026". Nutze die Dateisuche gezielt, um für die konkrete Schaden- oder Instandsetzungsleistung passende BKI-Altbau-Positionen und – soweit erforderlich – den Regionalfaktor zu finden. Erfinde keine Positionsnummern, Einheiten, Seiten oder Preise. Wenn eine Angabe nicht belastbar aus den BKI-Dateien auffindbar ist, lasse das Feld leer bzw. null. Verwende keine allgemeinen Marktpreise außerhalb der BKI-Dateien.

Antworte ausschließlich als JSON:
{"regional_factor":null,"regional_factor_note":"","positions":[{"position_code":"","description":"","unit":"","price_low":null,"price_mid":null,"price_high":null,"recommended_quantity":null,"source_page":"","source_name":"BKI Baukosten Positionen Altbau 2026","note":""}]}

Regeln:
- maximal 6 wirklich passende Positionen.
- BKI-Preise als Netto-Einheitspreise in EUR numerisch zurückgeben.
- Wenn mehrere notwendige Teilleistungen bestehen, z. B. Ausbau/Entsorgung und Neueinbau, dürfen mehrere Positionen vorgeschlagen werden.
- recommended_quantity aus der Nutzereingabe ableiten, aber nur bei eindeutiger Umrechnung. Beispiel: 5 Elemente à 2 m² und BKI-Einheit m² => 10; BKI-Einheit Stück => 5.
- regional_factor nur aus den BKI-Regionalfaktoren bestimmen, wenn Ort/PLZ eindeutig zuordenbar; sonst null und kurze Erläuterung.
- source_page muss die BKI-Seite enthalten, soweit sie aus dem Suchtreffer belastbar hervorgeht.
- Fassadengerüst strikt trennen: Auf-/Um-/Abbau ist eine einmalige Leistung; Vorhaltung ist eine gesonderte zeitabhängige Leistung. Preise oder Einheiten niemals zwischen beiden Positionen vertauschen.
- Bei Fassadengerüst-Auf-/Um-/Abbau Plausibilitätskorridor 6 bis 12 EUR netto je m² beachten. Für Kleinflächen unter 60 m² ist statt einer m²-Abrechnung eine Mindest-/Kleinflächenpauschale von 1.400 EUR netto anzusetzen und klar als solche zu kennzeichnen.
PROMPT;
  $text="Leistung: {$q}\nOrt/Region: {$location}\nExplizite Menge: {$qty}\nExplizite Einheit: {$unit}\nSchadenart: ".trim((string)($case['schadenart']??''));
  $payload=[
    'model'=>env('OPENAI_MODEL','gpt-5.4-mini'),
    'instructions'=>$instructions,
    'input'=>$text,
    'tools'=>[[
      'type'=>'file_search',
      'vector_store_ids'=>[$vectorStoreId],
      'max_num_results'=>12
    ]],
    'max_output_tokens'=>4500
  ];
  $d=bkOpenAIJson('POST','responses',$payload,360);
  $out=bkJson(bkOutputText($d));
  $rf=is_numeric($out['regional_factor']??null)?(float)$out['regional_factor']:null;
  $positions=[];
  foreach(($out['positions']??[])as$p){
    if(!is_array($p))continue;
    $positions[]=[
      'position_code'=>trim((string)($p['position_code']??'')),
      'description'=>trim((string)($p['description']??'')),
      'unit'=>trim((string)($p['unit']??'')),
      'price_low'=>is_numeric($p['price_low']??null)?(float)$p['price_low']:null,
      'price_mid'=>is_numeric($p['price_mid']??null)?(float)$p['price_mid']:null,
      'price_high'=>is_numeric($p['price_high']??null)?(float)$p['price_high']:null,
      'recommended_quantity'=>is_numeric($p['recommended_quantity']??null)?(float)$p['recommended_quantity']:null,
      'regional_factor'=>$rf,
      'source_page'=>trim((string)($p['source_page']??'')),
      'source_name'=>trim((string)($p['source_name']??'BKI Baukosten Positionen Altbau 2026')),
      'note'=>trim((string)($p['note']??''))
    ];
  }
  $queryNorm=mb_strtolower($q,'UTF-8');
  $isScaffoldSetup=str_contains($queryNorm,'fassadengerüst')
    && (str_contains($queryNorm,'auf-')||str_contains($queryNorm,'aufbau')||str_contains($queryNorm,'abbau')||str_contains($queryNorm,'umsetzen'));
  $quantity=is_numeric(str_replace(',','.',$qty))?(float)str_replace(',','.',$qty):0.0;
  if($isScaffoldSetup&&$quantity>0){
    if($quantity<60){
      $positions=[[
        'position_code'=>'301.000.057',
        'description'=>'Fassadengerüst – Auf-, Um- und Abbau – Kleinflächenpauschale unter 60 m²',
        'unit'=>'psch',
        'price_low'=>1400.0,
        'price_mid'=>1400.0,
        'price_high'=>1400.0,
        'recommended_quantity'=>1.0,
        'regional_factor'=>$rf,
        'source_page'=>'',
        'source_name'=>'BKI Baukosten Positionen Altbau 2026',
        'note'=>'Mindest-/Kleinflächenpauschale netto; Vorhaltung separat kalkulieren.'
      ]];
    }else{
      $base=$positions[0]??[];
      $positions=[[
        'position_code'=>trim((string)($base['position_code']??''))?:'301.000.057',
        'description'=>'Fassadengerüst – Auf-, Um- und Abbau',
        'unit'=>'m²',
        'price_low'=>6.0,
        'price_mid'=>9.0,
        'price_high'=>12.0,
        'recommended_quantity'=>$quantity,
        'regional_factor'=>$rf,
        'source_page'=>trim((string)($base['source_page']??'')),
        'source_name'=>trim((string)($base['source_name']??'BKI Baukosten Positionen Altbau 2026')),
        'note'=>'Plausibilitätskorridor 6–12 EUR/m² netto; Vorhaltung separat kalkulieren.'
      ]];
    }
  }
  return[
    'positions'=>$positions,
    'regional_factor'=>$rf,
    'regional_factor_note'=>trim((string)($out['regional_factor_note']??'')),
    'search_mode'=>'file_search'
  ];
}

function bkSafeName(string $value):string{
  $value=trim($value);
  $value=preg_replace('/[^A-Za-z0-9ÄÖÜäöüß._-]+/u','-',$value)??'';
  return trim($value,'-_.')?:'Fall';
}
function bkEuro(float $value):string{return number_format($value,2,',','.').' €';}
function bkDriveUploadFile(string $folderId,string $name,string $mime,string $bytes):array{
  if($folderId==='')throw new RuntimeException('Fallordner fehlt.');
  $boundary='svnetbki'.bin2hex(random_bytes(8));
  $meta=['name'=>$name,'mimeType'=>$mime,'parents'=>[$folderId]];
  $body='--'.$boundary."\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n"
    .json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)
    ."\r\n--".$boundary."\r\nContent-Type: ".$mime."; charset=UTF-8\r\n\r\n"
    .$bytes."\r\n--".$boundary."--";
  $r=bkHttp(
    'POST',
    'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&supportsAllDrives=true&fields=id,name,mimeType,webViewLink',
    ['Authorization: Bearer '.bkGoogleToken(),'Content-Type: multipart/related; boundary='.$boundary],
    $body,
    180
  );
  if($r['status']<200||$r['status']>=300)throw new RuntimeException('Kalkulationsdatei konnte nicht in den Fallordner geschrieben werden.');
  $j=json_decode($r['body'],true);
  if(!is_array($j)||empty($j['id']))throw new RuntimeException('Google Drive hat keine Datei-ID fuer die Kalkulation geliefert.');
  return $j;
}
function bkArchiveHtml(array $case,array $items,array $totals,string $location,string $note,int $calcId):string{
  $h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
  $caseNo=trim((string)($case['schaden_nr']??''));
  $damage=trim((string)($case['schadenart']??''));
  $object=trim((string)($case['vn_objekt']??''));
  $created=(new DateTimeImmutable('now',new DateTimeZone('Europe/Berlin')))->format('d.m.Y H:i');
  $rows='';
  foreach($items as $index=>$item){
    if(!is_array($item))continue;
    $qty=(float)($item['quantity']??0);
    $unitPrice=(float)($item['unit_price']??0);
    $factor=(float)($item['regional_factor']??1);
    if($factor<=0)$factor=1;
    $sum=$qty*$unitPrice*$factor;
    $source=trim((string)($item['source_name']??''));
    $page=trim((string)($item['source_page']??''));
    if($page!=='')$source.=($source!==''?' · ':'').'Seite '.$page;
    $rows.='<tr>'
      .'<td>'.($index+1).'</td>'
      .'<td>'.$h($item['position_code']??'').'</td>'
      .'<td>'.$h($item['description']??'').'</td>'
      .'<td class="num">'.$h(number_format($qty,2,',','.')).'</td>'
      .'<td>'.$h($item['unit']??'').'</td>'
      .'<td class="num">'.$h(bkEuro($unitPrice)).'</td>'
      .'<td class="num">'.$h(number_format($factor,3,',','.')).'</td>'
      .'<td class="num">'.$h(bkEuro($sum)).'</td>'
      .'<td>'.$h($source).'</td>'
      .'</tr>';
  }
  $net=(float)($totals['net']??0);
  $vat=(float)($totals['vat']??19);
  $tax=(float)($totals['tax']??0);
  $gross=(float)($totals['gross']??0);
  $title='BKI-Kalkulation'.($caseNo!==''?' – Schaden '.$caseNo:'');
  return '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
    .'<title>'.$h($title).'</title><style>body{font-family:Arial,sans-serif;color:#17324a;margin:28px;font-size:13px}h1{margin:0 0 6px}p{line-height:1.45}.meta{background:#f3f6f8;padding:12px;border-radius:8px;margin:14px 0}table{width:100%;border-collapse:collapse;margin-top:16px}th,td{border:1px solid #d7e0e7;padding:7px;vertical-align:top}th{background:#eef3f6;text-align:left}.num{text-align:right;white-space:nowrap}.totals{margin-left:auto;width:320px;margin-top:16px}.totals td:first-child{font-weight:bold}.gross{font-size:15px;font-weight:bold}.note{margin-top:18px;padding:12px;border-left:4px solid #ff970f;background:#fff8ed}.source{margin-top:18px;font-size:11px;color:#65788a}@media print{body{margin:12mm}.source{page-break-inside:avoid}}</style></head><body>'
    .'<h1>'.$h($title).'</h1><p>Erstellt am '.$h($created).' · Kalkulations-ID '.$calcId.'</p>'
    .'<div class="meta"><strong>Schaden-Nr.:</strong> '.$h($caseNo?:'—').'<br><strong>Schadenart:</strong> '.$h($damage?:'—').'<br><strong>VN / Objekt:</strong> '.$h($object?:'—').'<br><strong>Ort / Region:</strong> '.$h($location?:'—').'</div>'
    .'<table><thead><tr><th>Pos.</th><th>BKI-Pos.</th><th>Leistung</th><th>Menge</th><th>Einheit</th><th>EP netto</th><th>Reg.-Faktor</th><th>Gesamt netto</th><th>Quelle</th></tr></thead><tbody>'.$rows.'</tbody></table>'
    .'<table class="totals"><tr><td>Netto</td><td class="num">'.$h(bkEuro($net)).'</td></tr><tr><td>USt. '.$h(number_format($vat,1,',','.')).' %</td><td class="num">'.$h(bkEuro($tax)).'</td></tr><tr class="gross"><td>Brutto</td><td class="num">'.$h(bkEuro($gross)).'</td></tr></table>'
    .($note!==''?'<div class="note"><strong>Hinweis / Abgeltungsvermerk</strong><br>'.$h($note).'</div>':'')
    .'<p class="source">Kalkulationsgrundlage: BKI Baukosten Positionen Altbau 2026 / BKI Baukosten Gebäude Altbau 2026. Die in der Aufstellung verwendeten Werte sind entsprechend der gespeicherten Auswahl dokumentiert.</p>'
    .'</body></html>';
}
bkSchema();
$action=(string)($_GET['action']??'status');
try{
  if($action==='status') apiJson(['ok'=>true,'source'=>'BKI Altbau 2026','positions_file_id'=>BKI_POSITIONS_ID,'buildings_file_id'=>BKI_BUILDINGS_ID]);
  if($action==='analyze_kva'){
    if($_SERVER['REQUEST_METHOD']!=='POST')apiError(405,'POST erforderlich.');
    $folder=trim((string)($_POST['folder_id']??''));if($folder==='')throw new RuntimeException('Bitte zuerst einen Schadenfall öffnen.');requireCaseFolderAccess($folder,$user);
    if(isset($_FILES['file'])&&is_uploaded_file((string)($_FILES['file']['tmp_name']??''))){$file=$_FILES['file'];if((int)($file['size']??0)>30*1024*1024)throw new RuntimeException('Die Datei darf höchstens 30 MB groß sein.');$name=basename((string)$file['name']);$mime=(string)(mime_content_type((string)$file['tmp_name'])?:($file['type']??'application/octet-stream'));$bytes=(string)file_get_contents((string)$file['tmp_name']);}
    else{$fileId=trim((string)($_POST['file_id']??''));if($fileId==='')throw new RuntimeException('Bitte einen KVA auswählen oder fotografieren.');$meta=bkDriveMeta($fileId);if(!in_array($folder,array_map('strval',is_array($meta['parents']??null)?$meta['parents']:[]),true))throw new RuntimeException('Der ausgewählte KVA gehört nicht zum aktiven Fall.');$selected=bkDriveBytes($fileId);$name=(string)$selected['name'];$mime=(string)$selected['mime'];$bytes=(string)$selected['bytes'];}
    apiJson(['ok'=>true,...bkAnalyzeKva($name,$mime,$bytes)]);
  }
  if($action==='search'){if($_SERVER['REQUEST_METHOD']!=='POST')apiError(405,'POST erforderlich.');apiJson(['ok'=>true,...bkSearch(requestBody())]);}
  if($action==='save'){
    if($_SERVER['REQUEST_METHOD']!=='POST')apiError(405,'POST erforderlich.');$in=requestBody();$items=is_array($in['items']??null)?$in['items']:[];if(!$items)throw new RuntimeException('Kalkulation enthält keine Positionen.');$tot=is_array($in['totals']??null)?$in['totals']:[];$case=is_array($in['case_meta']??null)?$in['case_meta']:[];$folder=trim((string)($in['folder_id']??''));if($folder!=='')requireCaseFolderAccess($folder,$user);$stmt=db()->prepare('INSERT INTO bki_calculations(folder_id,case_no,damage_type,object_name,location,note,net_total,vat_rate,vat_total,gross_total,items_json,created_by,created_at) VALUES(:folder,:case_no,:damage,:obj,:location,:note,:net,:vat,:tax,:gross,:items,:user,NOW())');$stmt->execute([':folder'=>$folder!==''?$folder:null,':case_no'=>trim((string)($case['schaden_nr']??''))?:null,':damage'=>trim((string)($case['schadenart']??''))?:null,':obj'=>trim((string)($case['vn_objekt']??''))?:null,':location'=>trim((string)($in['location']??''))?:null,':note'=>trim((string)($in['note']??''))?:null,':net'=>(float)($tot['net']??0),':vat'=>(float)($tot['vat']??19),':tax'=>(float)($tot['tax']??0),':gross'=>(float)($tot['gross']??0),':items'=>json_encode($items,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),':user'=>(string)($user['email']??$user['full_name']??'')]);$calcId=(int)db()->lastInsertId();$driveFile=null;
    if($folder!==''){
      try{
        $caseNo=trim((string)($case['schaden_nr']??''));
        $stamp=(new DateTimeImmutable('now',new DateTimeZone('Europe/Berlin')))->format('Y-m-d_Hi');
        $fileName='BKI-Kalkulation_'.bkSafeName($caseNo!==''?$caseNo:'Fall').'_'.$stamp.'.html';
        $html=bkArchiveHtml($case,$items,$tot,trim((string)($in['location']??'')),trim((string)($in['note']??'')),$calcId);
        $driveFile=bkDriveUploadFile($folder,$fileName,'text/html',$html);
      }catch(Throwable $archiveError){
        try{$del=db()->prepare('DELETE FROM bki_calculations WHERE id=:id');$del->execute([':id'=>$calcId]);}catch(Throwable){}
        throw new RuntimeException('Kalkulation konnte nicht in der Fallakte archiviert werden: '.$archiveError->getMessage());
      }
    }
    apiJson(['ok'=>true,'id'=>$calcId,'folder_id'=>$folder,'drive_file'=>$driveFile]);
  }
  if($action==='list'){
    $folder=trim((string)($_GET['folder_id']??''));if($folder!=='')requireCaseFolderAccess($folder,$user);if($folder!=='' ){$s=db()->prepare('SELECT id,case_no,damage_type,object_name,gross_total,created_at FROM bki_calculations WHERE folder_id=:f ORDER BY id DESC LIMIT 30');$s->execute([':f'=>$folder]);}else{$s=db()->query('SELECT id,case_no,damage_type,object_name,gross_total,created_at FROM bki_calculations WHERE folder_id IS NULL OR folder_id="" ORDER BY id DESC LIMIT 30');}$out=[];foreach($s->fetchAll()as$r)$out[]=['id'=>(int)$r['id'],'case_no'=>(string)($r['case_no']??''),'gross_total'=>(float)$r['gross_total'],'created_at'=>(string)$r['created_at'],'title'=>trim(((string)($r['damage_type']??'')).' '.((string)($r['object_name']??'')))?:'BKI-Kalkulation'];apiJson(['ok'=>true,'items'=>$out]);
  }
  if($action==='get'){$id=(int)($_GET['id']??0);if($id<=0)apiError(400,'ID fehlt.');$s=db()->prepare('SELECT * FROM bki_calculations WHERE id=:id AND created_by=:u LIMIT 1');$s->execute([':id'=>$id,':u'=>(string)($user['email']??$user['full_name']??'')]);$r=$s->fetch();if(!$r)apiError(404,'Kalkulation nicht gefunden.');$r['items']=json_decode((string)$r['items_json'],true)?:[];unset($r['items_json']);apiJson(['ok'=>true,'item'=>$r]);}
  if($action==='delete'){
    if($_SERVER['REQUEST_METHOD']!=='POST')apiError(405,'POST erforderlich.');
    $in=requestBody();$id=(int)($in['id']??0);if($id<=0)apiError(400,'ID fehlt.');
    $who=(string)($user['email']??$user['full_name']??'');
    $s=db()->prepare('SELECT folder_id FROM bki_calculations WHERE id=:id AND created_by=:u LIMIT 1');$s->execute([':id'=>$id,':u'=>$who]);$row=$s->fetch();
    if(!$row)apiError(404,'Kalkulation nicht gefunden oder keine Löschberechtigung.');
    $folder=trim((string)($row['folder_id']??''));if($folder!=='')requireCaseFolderAccess($folder,$user);
    $del=db()->prepare('DELETE FROM bki_calculations WHERE id=:id AND created_by=:u');$del->execute([':id'=>$id,':u'=>$who]);
    apiJson(['ok'=>true,'deleted_id'=>$id]);
  }
  apiError(404,'Unbekannte Aktion.');
}catch(Throwable $e){error_log('[bki-calculator] '.$e->getMessage());apiError(500,$e->getMessage());}
