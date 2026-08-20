<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
commonHeaders();
$user = requireAuth();
if (!in_array($user['role'] ?? '', ['administrator','projektleiter','pruefer','sachverstaendiger'], true)) apiError(403, 'Keine Berechtigung.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') apiError(405, 'POST erforderlich.');

const GF_KNOWLEDGE_FOLDER_ID = '1QeJ4Dz6Upg_W5rahWmKE_7KbC4MMgSGe';
const GF_BKI_FOLDER_ID = '1NQ3XO8qfHb92E6wqFU0kQjyIovsq98Ec';
const GF_BKI_ALTBAU_POSITIONEN_ID = '1X6IoAbQ0BKmlFGBb9ZOR4vh4nIJ_6r-p';
const GF_BKI_ALTBAU_GEBAEUDE_ID = '1mAAPIkhN1NqtezkDEiZ4rPzyJg5Cn3aE';
const GF_CASE_META = '00_Falldaten.json';

function gfSettingGet(string $key, string $default=''): string {
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS app_settings (setting_key VARCHAR(190) PRIMARY KEY, setting_value MEDIUMTEXT NULL, updated_at DATETIME NOT NULL, updated_by VARCHAR(190) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $stmt=db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key=:k LIMIT 1');
        $stmt->execute([':k'=>$key]);
        $value=$stmt->fetchColumn();
        return $value===false ? $default : (string)$value;
    } catch(Throwable $e) { return $default; }
}
function gfSettingSet(string $key,string $value): void {
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS app_settings (setting_key VARCHAR(190) PRIMARY KEY, setting_value MEDIUMTEXT NULL, updated_at DATETIME NOT NULL, updated_by VARCHAR(190) NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $stmt=db()->prepare('INSERT INTO app_settings(setting_key,setting_value,updated_at,updated_by) VALUES(:k,:v,NOW(),:u) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=NOW(),updated_by=VALUES(updated_by)');
        $stmt->execute([':k'=>$key,':v'=>$value,':u'=>'gf-ai-generate']);
    } catch(Throwable $e) { }
}
function gfB64url(string $data): string { return rtrim(strtr(base64_encode($data), '+/', '-_'), '='); }
function gfHttp(string $method,string $url,array $headers=[],?string $body=null,bool $googleAuth=false): array {
    if ($googleAuth) $headers[]='Authorization: Bearer '.gfGoogleToken();
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>240,CURLOPT_FOLLOWLOCATION=>true]);
    if ($body!==null) curl_setopt($ch,CURLOPT_POSTFIELDS,$body);
    $response=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
    if ($response===false || $err!=='') apiError(503,'Verbindung fehlgeschlagen: '.($err?:'unbekannter Fehler'));
    return ['status'=>$status,'body'=>(string)$response];
}
function gfGoogleToken(): string {
    static $token=null;if($token!==null)return$token;
    $serviceJson=trim(env('GOOGLE_DRIVE_SERVICE_ACCOUNT_JSON',''));
    if($serviceJson!==''){
        if(!str_starts_with($serviceJson,'{')){$decoded=base64_decode($serviceJson,true);if($decoded!==false)$serviceJson=$decoded;}
        $svc=json_decode($serviceJson,true);
        if(is_array($svc)&&!empty($svc['client_email'])&&!empty($svc['private_key'])){
            $now=time();$header=gfB64url(json_encode(['alg'=>'RS256','typ'=>'JWT'],JSON_UNESCAPED_SLASHES));$claims=gfB64url(json_encode(['iss'=>$svc['client_email'],'scope'=>'https://www.googleapis.com/auth/drive','aud'=>'https://oauth2.googleapis.com/token','iat'=>$now,'exp'=>$now+3500],JSON_UNESCAPED_SLASHES));$input=$header.'.'.$claims;$signature='';
            if(openssl_sign($input,$signature,$svc['private_key'],OPENSSL_ALGO_SHA256)){
                $jwt=$input.'.'.gfB64url($signature);$r=gfHttp('POST','https://oauth2.googleapis.com/token',['Content-Type: application/x-www-form-urlencoded'],http_build_query(['grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer','assertion'=>$jwt]),false);$b=json_decode($r['body'],true);if($r['status']===200&&!empty($b['access_token']))return$token=(string)$b['access_token'];
            }
        }
    }
    $clientId=env('GOOGLE_DRIVE_CLIENT_ID',gfSettingGet('google_drive_client_id'));$clientSecret=env('GOOGLE_DRIVE_CLIENT_SECRET',gfSettingGet('google_drive_client_secret'));$refreshToken=env('GOOGLE_DRIVE_REFRESH_TOKEN',gfSettingGet('google_drive_refresh_token'));
    if($clientId!==''&&$clientSecret!==''&&$refreshToken!==''){$r=gfHttp('POST','https://oauth2.googleapis.com/token',['Content-Type: application/x-www-form-urlencoded'],http_build_query(['client_id'=>$clientId,'client_secret'=>$clientSecret,'refresh_token'=>$refreshToken,'grant_type'=>'refresh_token']),false);$b=json_decode($r['body'],true);if($r['status']===200&&!empty($b['access_token']))return$token=(string)$b['access_token'];}
    apiError(503,'Google Drive ist nicht verbunden.');
}
function gfDriveList(string $parentId): array {
    $q="'".str_replace("'","\\'",$parentId)."' in parents and trashed=false";
    $url='https://www.googleapis.com/drive/v3/files?'.http_build_query(['q'=>$q,'fields'=>'files(id,name,mimeType,modifiedTime,size,webViewLink)','pageSize'=>1000,'orderBy'=>'modifiedTime desc','supportsAllDrives'=>'true']);
    $r=gfHttp('GET',$url,[],null,true);if($r['status']!==200)apiError(503,'Google-Drive-Dateiliste konnte nicht geladen werden.');$d=json_decode($r['body'],true);return is_array($d['files']??null)?$d['files']:[];
}
function gfDriveDownload(array $file): ?array {
    $id=(string)($file['id']??'');$mime=(string)($file['mimeType']??'application/octet-stream');if($id==='')return null;
    if(str_starts_with($mime,'application/vnd.google-apps.')){
        if($mime==='application/vnd.google-apps.document'){$exportMime='application/pdf';$name=((string)($file['name']??'Dokument')).'.pdf';}
        elseif($mime==='application/vnd.google-apps.spreadsheet'){$exportMime='application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';$name=((string)($file['name']??'Tabelle')).'.xlsx';}
        else return null;
        $r=gfHttp('GET','https://www.googleapis.com/drive/v3/files/'.rawurlencode($id).'/export?'.http_build_query(['mimeType'=>$exportMime]),[],null,true);if($r['status']!==200)return null;return['name'=>$name,'mime'=>$exportMime,'bytes'=>$r['body']];
    }
    $r=gfHttp('GET','https://www.googleapis.com/drive/v3/files/'.rawurlencode($id).'?alt=media&supportsAllDrives=true',[],null,true);if($r['status']!==200)return null;return['name'=>(string)($file['name']??'Unterlage'),'mime'=>$mime,'bytes'=>$r['body']];
}
function gfFindByName(string $parentId,string $name): ?array {foreach(gfDriveList($parentId) as$f)if((string)($f['name']??'')===$name)return$f;return null;}
function gfReadJson(string $folderId,string $name): array {$f=gfFindByName($folderId,$name);if(!$f)return[];$d=gfDriveDownload($f);if(!$d)return[];$j=json_decode($d['bytes'],true);return is_array($j)?$j:[];}
function gfUpload(string $parentId,string $name,string $mime,string $bytes): array {
    $meta=['name'=>$name,'mimeType'=>$mime,'parents'=>[$parentId]];$boundary='svnet'.bin2hex(random_bytes(8));$body='--'.$boundary."\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n".json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\r\n--".$boundary."\r\nContent-Type: ".$mime."\r\n\r\n".$bytes."\r\n--".$boundary."--";$url='https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&supportsAllDrives=true&fields=id,name,mimeType,webViewLink,webContentLink';$r=gfHttp('POST',$url,['Content-Type: multipart/related; boundary='.$boundary],$body,true);if($r['status']<200||$r['status']>=300)apiError(503,'KI-Dokument konnte nicht in Google Drive gespeichert werden.');return json_decode($r['body'],true)?:[];
}
function gfOpenAIUploadDriveFile(array $driveFile): ?array {
    $id=(string)($driveFile['id']??'');$modified=(string)($driveFile['modifiedTime']??'');if($id==='')return null;
    $cacheKey='openai_bki_file_'.$id;$cached=json_decode(gfSettingGet($cacheKey,'{}'),true);
    if(is_array($cached)&&($cached['modified']??'')===$modified&&!empty($cached['file_id']))return['file_id'=>(string)$cached['file_id'],'name'=>(string)($cached['name']??($driveFile['name']??'BKI'))];
    $download=gfDriveDownload($driveFile);if(!$download)return null;
    $apiKey=trim(env('OPENAI_API_KEY',''));if($apiKey==='')apiError(503,'OpenAI API-Key ist nicht konfiguriert.');
    $tmp=tempnam(sys_get_temp_dir(),'svnet-bki-');if($tmp===false)return null;file_put_contents($tmp,$download['bytes']);
    $ch=curl_init('https://api.openai.com/v1/files');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.$apiKey],CURLOPT_POSTFIELDS=>['purpose'=>'user_data','file'=>new CURLFile($tmp,(string)$download['mime'],(string)$download['name'])],CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>300]);
    $response=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);@unlink($tmp);
    if($response===false||$err!==''||$status<200||$status>=300)return null;$decoded=json_decode((string)$response,true);$fileId=(string)($decoded['id']??'');if($fileId==='')return null;
    gfSettingSet($cacheKey,json_encode(['file_id'=>$fileId,'modified'=>$modified,'name'=>$download['name']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    return['file_id'=>$fileId,'name'=>(string)$download['name']];
}
function gfBkiSources(): array {
    $wanted=[GF_BKI_ALTBAU_POSITIONEN_ID,GF_BKI_ALTBAU_GEBAEUDE_ID];$out=[];
    foreach(gfDriveList(GF_BKI_FOLDER_ID) as$f){if(!in_array((string)($f['id']??''),$wanted,true))continue;$ref=gfOpenAIUploadDriveFile($f);if($ref)$out[]=$ref;}
    return$out;
}
function gfSafe(string $v):string{$v=preg_replace('/[^A-Za-z0-9ÄÖÜäöüß._-]+/u','-',trim($v))??'';return trim($v,'-_.')?:'Fall';}
function gfH(string $v):string{return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function gfTitle(string $key):string{return match($key){'erstbericht'=>'Erstbericht','schadenprotokoll'=>'Schadenprotokoll','schlusserklaerung'=>'Schlusserklärung','zahlungsbefuerwortung'=>'Zahlungsbefürwortung','nachtrag_stellungnahme'=>'Nachtrag / Stellungnahme','zwischenbericht'=>'Zwischenbericht','schlussbericht'=>'Schlussbericht','vorauszahlung'=>'Vorauszahlung / Freigabe','query_form'=>'Rückfrageformular',default=>'Dokument'};}
function gfHeadings(string $key):array{return match($key){
'erstbericht'=>['Versicherungsverhältnisse','Risikoverhältnisse','Schadenhergang / Schadenursache','Polizeiliche / behördliche Ermittlungen','Ersatzpflicht / Deckung','Schadenumfang','Reserve und Schadenhöhe','Schadenabwicklung','Teilzahlung / Vorauszahlung','Regress'],
'schadenprotokoll'=>['Schadenort und Besichtigungsdaten','Anwesende Personen','Schadenhergang','Festgestelltes Schadenbild','Ursache','Betroffene Bereiche / Sachen','Sofortmaßnahmen','Unterlagen / Belege','Erklärungen des Versicherungsnehmers','Unterschriften / Vermerk'],
'schlusserklaerung'=>['Schadenermittlung','Einzelmengen und Positionen','Abzüge / Selbstbehalt','Bereits geleistete Zahlungen','Entschädigungsbetrag','Restreserve','Erklärung / Unterschrift'],
'zahlungsbefuerwortung'=>['Zahlungsempfänger','Betrag','Zahlungsgrund','Geprüfte Unterlagen','Bereits geleistete Zahlungen','Restreserve','Befürwortung'],
'nachtrag_stellungnahme'=>['Bezug / bisheriger Prüfstand','Neu vorliegende Unterlage / Gutachten','Bisherige Zweifel / offene Punkte','Neue fachliche Feststellungen','Bewertung des Kostenvoranschlags','Entscheidung / Empfehlung','Unterrichtung des Versicherungsnehmers durch die SV','Auswirkungen auf Schadenhöhe / Reserve','Weiteres Vorgehen'],
'zwischenbericht'=>['Aktueller Sachstand','Neue Erkenntnisse','Deckung / Ersatzpflicht','Schadenentwicklung','Beleg- / Nachtragsprüfung','Reserve','Zahlungen','Regress','Weiteres Vorgehen'],
'schlussbericht'=>['Abschließender Sachstand','Schadenursache','Ersatzpflicht / Deckung','Finale Schadenermittlung','Zahlungen','Reserveabschluss','Regressergebnis','Abschlussbewertung'],
'vorauszahlung'=>['Zahlungsempfänger','Betrag','Begründung','Deckungs- / Haftungsstand','Bisherige Zahlungen','Restreserve','Freigabe'],
'query_form'=>['Offener Punkt','Sachverhalt','Bisherige Feststellungen','Konkrete Rückfrage','Benötigte Entscheidung / Unterlage'],default=>['Sachverhalt','Feststellungen','Bewertung','Empfehlung']};}
function gfOpenAI(array $content,string $system): array {
    $apiKey=trim(env('OPENAI_API_KEY',''));if($apiKey==='')apiError(503,'OpenAI API-Key ist nicht konfiguriert.');
    $payload=['model'=>env('OPENAI_MODEL','gpt-5.4-mini'),'instructions'=>$system,'input'=>[['role'=>'user','content'=>$content]],'max_output_tokens'=>9000];
    $ch=curl_init('https://api.openai.com/v1/responses');curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$apiKey],CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>300]);$response=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);
    if($response===false||$err!=='')apiError(503,'KI-Verbindung fehlgeschlagen: '.($err?:'unbekannter Fehler'));$decoded=json_decode((string)$response,true);
    if($status<200||$status>=300){$msg=trim((string)($decoded['error']['message']??''));apiError(503,$msg!==''?'KI-Dokument konnte nicht erzeugt werden: '.$msg:'KI-Dokument konnte nicht erzeugt werden.');}
    $text='';if(isset($decoded['output_text'])&&is_string($decoded['output_text']))$text=trim($decoded['output_text']);if($text===''){foreach(($decoded['output']??[])as$item){if(($item['type']??'')!=='message')continue;foreach(($item['content']??[])as$part)if(($part['type']??'')==='output_text')$text.=(string)($part['text']??'');}$text=trim($text);}if(preg_match('/```(?:json)?\s*(\{.*\})\s*```/s',$text,$m))$text=$m[1];$data=json_decode($text,true);if(!is_array($data)){if(($a=strpos($text,'{'))!==false&&($b=strrpos($text,'}'))!==false&&$b>$a)$data=json_decode(substr($text,$a,$b-$a+1),true);}if(!is_array($data))apiError(503,'KI-Antwort konnte nicht als Dokumentstruktur gelesen werden.');return$data;
}
function gfDocumentHtml(string $title,array $meta,array $result,array $sources,string $userName):string{
    $sections='';foreach(($result['sections']??[])as$s){$h=trim((string)($s['heading']??''));$t=trim((string)($s['text']??''));if($h===''||$t==='')continue;$sections.='<h2>'.gfH($h).'</h2><p>'.nl2br(gfH($t)).'</p>';}
    $sourceHtml='';if($sources){$sourceHtml='<h2>Ausgewertete Unterlagen</h2><ul>';foreach($sources as$n)$sourceHtml.='<li>'.gfH($n).'</li>';$sourceHtml.='</ul>';}
    $open='';if(!empty($result['open_points'])&&is_array($result['open_points'])){$open='<h2>Offene Punkte</h2><ul>';foreach($result['open_points']as$p)$open.='<li>'.gfH((string)$p).'</li>';$open.='</ul>';}
    $generated=date('d.m.Y H:i');return'<!doctype html><html><head><meta charset="utf-8"><title>'.gfH($title).'</title><style>body{font-family:Arial,sans-serif;font-size:11pt;line-height:1.5;margin:2cm;color:#111}h1{font-size:18pt;margin-bottom:18px}h2{font-size:13pt;margin-top:22px;border-bottom:1px solid #bbb;padding-bottom:4px}table{border-collapse:collapse;width:100%;margin:12px 0 20px}td{border:1px solid #ccc;padding:6px;vertical-align:top}.label{font-weight:bold;width:30%}.summary{background:#f3f6f8;padding:10px;border:1px solid #d6e0e7}.footer{margin-top:30px}</style></head><body><h1>'.gfH($title).'</h1><table><tr><td class="label">Schaden-Nr.</td><td>'.gfH((string)($meta['schaden_nr']??'')).'</td></tr><tr><td class="label">Versicherungsschein-Nr.</td><td>'.gfH((string)($meta['versicherungsschein_nr']??'')).'</td></tr><tr><td class="label">VN / Objekt</td><td>'.gfH((string)($meta['vn_objekt']??'')).'</td></tr><tr><td class="label">Schadenort</td><td>'.gfH(trim((string)($meta['strasse']??'').' '.(string)($meta['plz']??'').' '.(string)($meta['ort']??''))).'</td></tr><tr><td class="label">Aktuelle Schadenhöhe / Reserve</td><td>'.gfH((string)($meta['reserve']??'')).'</td></tr></table>'.(!empty($result['summary'])?'<div class="summary"><strong>Zusammenfassung</strong><br>'.nl2br(gfH((string)$result['summary'])).'</div>':'').$sections.$open.$sourceHtml.'<div class="footer"><p>Erstellt am '.gfH($generated).' durch '.gfH($userName).'.</p><p>Christian Wächter<br>Sachverständiger &amp; Großschadenregulierer<br>DIN EN ISO/IEC 17024 zertifiziert<br>https://www.sv-netzwerk.eu/</p></div></body></html>';
}

$body=requestBody();$folderId=trim((string)($body['folder_id']??''));$order=is_array($body['order']??null)?$body['order']:[];$outputs=array_values(array_filter(array_map('strval',is_array($order['outputs']??null)?$order['outputs']:[])));if($folderId==='')apiError(400,'Kein aktiver Fallordner.');if(!$outputs)apiError(400,'Keine Dokumente ausgewählt.');
$allowed=['erstbericht','schadenprotokoll','schlusserklaerung','zahlungsbefuerwortung','nachtrag_stellungnahme','zwischenbericht','schlussbericht','vorauszahlung','query_form'];foreach($outputs as$o)if(!in_array($o,$allowed,true))apiError(400,'Unbekannter Dokumenttyp: '.$o);
$meta=gfReadJson($folderId,GF_CASE_META);if(!$meta)apiError(409,'Falldaten konnten nicht geladen werden.');
$instructions=(string)($order['instructions']??'');$bkiRequested=preg_match('/\bBKI\b/ui',$instructions)===1;
$caseFiles=gfDriveList($folderId);$selected=[];$totalBytes=0;$maxTotal=42*1024*1024;
foreach($caseFiles as$f){$name=(string)($f['name']??'');$mime=(string)($f['mimeType']??'');if($name===GF_CASE_META||str_starts_with($name,'00_Dokumentauftrag')||preg_match('/_(Erstbericht|Zwischenbericht|Schlussbericht|Nachtrag-Stellungnahme|Schlusserklärung|Schadenprotokoll|Zahlungsbefürwortung|Vorauszahlung-Freigabe|Rückfrageformular)_/u',$name))continue;if($mime==='application/vnd.google-apps.folder')continue;$size=(int)($f['size']??0);if($size>18*1024*1024)continue;$d=gfDriveDownload($f);if(!$d)continue;$len=strlen($d['bytes']);if($len<=0||$totalBytes+$len>$maxTotal)continue;$selected[]=$d;$totalBytes+=$len;if(count($selected)>=10)break;}
$knowledge=[];foreach(gfDriveList(GF_KNOWLEDGE_FOLDER_ID) as$f){$n=mb_strtolower((string)($f['name']??''),'UTF-8');if(!str_contains($n,'engel')&&!str_contains($n,'gf')&&!str_contains($n,'erstbericht')&&!str_contains($n,'master-arbeitsstandard'))continue;$d=gfDriveDownload($f);if(!$d)continue;$len=strlen($d['bytes']);if($len<=0||$len>10*1024*1024||$totalBytes+$len>$maxTotal)continue;$knowledge[]=$d;$totalBytes+=$len;if(count($knowledge)>=4)break;}
$bki=[];if($bkiRequested){$bki=gfBkiSources();if(count($bki)<2)apiError(503,'BKI wurde angefordert, die beiden BKI-Altbau-Quellen konnten jedoch nicht vollständig bereitgestellt werden. Bitte BKI-Ordner bzw. OpenAI-Dateizugriff prüfen.');}
if(!$selected)apiError(409,'Im aktiven Fall wurden keine auswertbaren Unterlagen gefunden. Bitte zuerst Gutachten, KVA oder sonstige Fallunterlagen hochladen.');
$userName=(string)($user['full_name']??($user['email']??'Christian Wächter'));$created=[];$sourceNames=array_map(fn($d)=>(string)$d['name'],$selected);if($bkiRequested)foreach($bki as$b)$sourceNames[]='BKI · '.(string)$b['name'];
foreach($outputs as$key){
    $title=gfTitle($key);$headings=gfHeadings($key);
    $bkiRule=$bkiRequested?" Der Arbeitsauftrag verlangt eine Bewertung nach BKI. Verwende die beigefügten BKI-Baukosten Altbau 2026 (Gebäude und Positionen) als maßgebliche Kalkulations- und Plausibilitätsquelle. Vergleiche KVA-/Rechnungspositionen fachlich mit den passenden BKI-Werten. Nenne BKI-Bezug, Position bzw. Kostengruppe und Wert nur soweit aus den Quellen eindeutig belegbar. Berücksichtige Preisstand, Einheit, Mengenbezug und erforderliche Anpassungen transparent. Keine BKI-Werte erfinden oder aus allgemeinem Wissen ersetzen.":'';
    $system="Du bist fachlicher Assistent eines deutschen Sachverständigen und Großschadenregulierers. Erstelle einen belastbaren, regulierungssicheren Text für einen SV-GF-Schaden der Sparkassenversicherung. Grundlage sind ausschließlich die beigefügten Fallunterlagen, Falldaten, der konkrete Arbeitsauftrag und beigefügte Engel/QS-Regelwerke.".$bkiRule." Keine Tatsachen erfinden, keine Lücken mit Vermutungen schließen. Aussagen fremder Sachverständiger als deren Feststellung kennzeichnen. Wenn eine Ursache nur ausgeschlossen, aber keine Alternativursache bewiesen ist, genau so formulieren. Widersprüche ausdrücklich benennen. Beträge, Daten, Namen und Schaden-Nr. exakt übernehmen. Der Text muss direkt verwendbar sein und darf keine Platzhalter wie 'aus der Fallakte zu befüllen' enthalten. Bei Nachtrag/Stellungnahme den bisherigen Prüfstand, die neue Unterlage, die fachliche Konsequenz und die konkrete Empfehlung sauber herleiten. Wenn der Arbeitsauftrag die Unterrichtung des VN durch die SV verlangt, dies ausdrücklich als Empfehlung an die SV formulieren. Halte die Engel/QS-Vorgaben ein; Regressaussage bei Berichten nicht vergessen. Antworte ausschließlich als JSON im Format {\"summary\":\"...\",\"sections\":[{\"heading\":\"...\",\"text\":\"...\"}],\"open_points\":[\"...\"]}. Verwende exakt diese Abschnittsüberschriften und keine weiteren: ".json_encode($headings,JSON_UNESCAPED_UNICODE).".";
    $content=[['type'=>'input_text','text'=>'Dokumenttyp: '.$title."\n\nFalldaten:\n".json_encode($meta,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n\nArbeitsauftrag:\n".$instructions."\n\nBearbeitungsart: ".(string)($order['work_mode']??'')."\nUmgang mit offenen Punkten: ".(string)($order['open_points']??'').($bkiRequested?"\n\nBKI-Modus: AKTIV – die beiden BKI-Altbau-2026-Quellen sind verbindlich heranzuziehen.":'')]];
    foreach($selected as$d)$content[]=['type'=>'input_file','filename'=>$d['name'],'file_data'=>'data:'.$d['mime'].';base64,'.base64_encode($d['bytes'])];
    foreach($knowledge as$d)$content[]=['type'=>'input_file','filename'=>'REGELWERK_'.$d['name'],'file_data'=>'data:'.$d['mime'].';base64,'.base64_encode($d['bytes'])];
    foreach($bki as$b)$content[]=['type'=>'input_file','file_id'=>$b['file_id']];
    $result=gfOpenAI($content,$system);$stamp=date('Y-m-d_His');$name=gfSafe((string)($meta['schaden_nr']??'Fall')).'_'.gfSafe($title).'_'.$stamp.'.doc';$html=gfDocumentHtml($title,$meta,$result,$sourceNames,$userName);$file=gfUpload($folderId,$name,'application/msword',$html);$created[]=['id'=>$file['id']??'','name'=>$file['name']??$name,'webViewLink'=>$file['webViewLink']??null,'webContentLink'=>$file['webContentLink']??null,'type'=>$key];
}
apiJson(['ok'=>true,'created'=>$created,'count'=>count($created),'ai'=>true,'bki_used'=>$bkiRequested,'sources'=>$sourceNames]);
