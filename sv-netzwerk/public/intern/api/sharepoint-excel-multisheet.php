<?php
/**
 * SharePoint Excel Multi-Sheet Import
 * Liest rekursiv alle Excel-/CSV-Dateien im Projektordner und innerhalb jeder
 * Arbeitsmappe alle sichtbaren Arbeitsblätter (z. B. EG, 1. OG, 2. OG, 3. OG).
 * Der Blattname wird als Etage/Floor mitgeführt, sofern die Zeile keine eigene
 * Etagenangabe enthält. Die eigentliche Datenübernahme bleibt in sharepoint-v2.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';
commonHeaders();
requireAuth();
if (($_GET['action'] ?? '') !== 'import_sharepoint_excel') {
    apiError(404, 'Unbekannter Multi-Sheet-Endpunkt.');
}

function msCfg(string $key, string $default = ''): string {
    $v = getenv($key);
    return $v === false || trim($v) === '' ? $default : trim($v);
}
function msToken(): string {
    static $token = null;
    if ($token) return $token;
    $tenant = msCfg('MS_TENANT_ID'); $client = msCfg('MS_CLIENT_ID'); $secret = msCfg('MS_CLIENT_SECRET');
    if ($tenant === '' || $client === '' || $secret === '') apiError(503, 'SharePoint-Verbindung unvollständig.');
    $c = curl_init('https://login.microsoftonline.com/' . rawurlencode($tenant) . '/oauth2/v2.0/token');
    curl_setopt_array($c, [CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,
        CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS=>http_build_query(['client_id'=>$client,'client_secret'=>$secret,'scope'=>'https://graph.microsoft.com/.default','grant_type'=>'client_credentials'])]);
    $r = curl_exec($c); $s = (int)curl_getinfo($c,CURLINFO_HTTP_CODE); curl_close($c);
    $j = is_string($r) ? json_decode($r,true) : null;
    if ($s < 200 || $s >= 300 || !is_array($j) || empty($j['access_token'])) apiError(503,'Microsoft-Anmeldung fehlgeschlagen.');
    return $token = (string)$j['access_token'];
}
function msGraph(string $url, bool $binary=false): array|string {
    $c=curl_init($url); curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_TIMEOUT=>$binary?180:60,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.msToken()]]);
    $r=curl_exec($c); $s=(int)curl_getinfo($c,CURLINFO_HTTP_CODE); curl_close($c);
    if ($s<200||$s>=300||!is_string($r)) apiError(503,'SharePoint konnte nicht gelesen werden (HTTP '.$s.').');
    if ($binary) return ['body'=>$r];
    $j=json_decode($r,true); if(!is_array($j)) apiError(503,'Ungültige SharePoint-Antwort.'); return $j;
}
function msSite(): string {
    static $id=null; if($id) return $id; $cfg=msCfg('MS_SHAREPOINT_SITE_ID'); if($cfg!=='') return $id=$cfg;
    $host=msCfg('MS_SHAREPOINT_HOST','sv1schuett.sharepoint.com'); $path=msCfg('MS_SHAREPOINT_SITE_PATH','/sites/SVBroSchtt');
    $j=msGraph('https://graph.microsoft.com/v1.0/sites/'.rawurlencode($host).':'.str_replace('%2F','/',rawurlencode($path)).'?$select=id');
    $id=(string)($j['id']??''); if($id==='') apiError(503,'SharePoint-Site nicht gefunden.'); return $id;
}
function msDrive(): string {
    static $id=null; if($id) return $id; $cfg=msCfg('MS_SHAREPOINT_DRIVE_ID'); if($cfg!=='') return $id=$cfg;
    $j=msGraph('https://graph.microsoft.com/v1.0/sites/'.rawurlencode(msSite()).'/drive?$select=id'); $id=(string)($j['id']??''); if($id==='') apiError(503,'Dokumentbibliothek nicht gefunden.'); return $id;
}
function msItemByPath(string $path): array {
    $parts=array_values(array_filter(explode('/',trim($path,'/')),fn($x)=>$x!=='')); $parent=''; $hit=null;
    foreach($parts as $part){
        $url='https://graph.microsoft.com/v1.0/drives/'.rawurlencode(msDrive()).($parent===''?'/root/children':'/items/'.rawurlencode($parent).'/children').'?%24select=id,name,size,file,folder&%24top=200';
        $hit=null;
        while($url!==''){ $p=msGraph($url); foreach(($p['value']??[]) as $it){ if(is_array($it)&&strcasecmp((string)($it['name']??''),$part)===0){$hit=$it;break 2;} } $url=(string)($p['@odata.nextLink']??''); }
        if(!is_array($hit)||empty($hit['id'])) apiError(404,'SharePoint-Pfad nicht gefunden: '.$part); $parent=(string)$hit['id'];
    }
    return $hit??[];
}
function msTree(string $root): array {
    $out=[];$q=[[$root,'']]; while($q){[$id,$rel]=array_shift($q);$url='https://graph.microsoft.com/v1.0/drives/'.rawurlencode(msDrive()).'/items/'.rawurlencode($id).'/children?%24select=id,name,size,file,folder,lastModifiedDateTime&%24top=200';
        while($url!==''){ $p=msGraph($url); foreach(($p['value']??[]) as $it){ if(!is_array($it))continue;$n=(string)($it['name']??'');if($n==='')continue;$rp=ltrim($rel.'/'.$n,'/');$it['relative_path']=$rp;$out[]=$it;if(!empty($it['folder'])&&!empty($it['id']))$q[]=[(string)$it['id'],$rp]; } $url=(string)($p['@odata.nextLink']??''); }} return $out;
}
function msCol(string $ref): int { $l=preg_replace('/\d+/','',strtoupper($ref))??'';$v=0;for($i=0;$i<strlen($l);$i++)$v=$v*26+(ord($l[$i])-64);return $v; }
function msHeaderLikely(array $row): bool { $t=implode(' ',array_map('strval',$row)); return preg_match('/zimmer|raum|fenster|beschlag|glas|rahmen|lage|mangel|breite|hoehe|höhe|aufbau|nummer/i',$t)===1; }
function msNorm(string $v): string { $v=mb_strtolower(trim($v),'UTF-8');$v=strtr($v,['ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss']);return trim((string)preg_replace('/[^a-z0-9]+/',' ',$v)); }
function msRowsAssoc(array $rows,string $sheet): array {
    if(!$rows)return[];$headerRows=0;for($i=0;$i<min(3,count($rows));$i++){if(msHeaderLikely($rows[$i]))$headerRows++;else break;}if($headerRows===0)$headerRows=1;
    $max=0;for($i=0;$i<$headerRows;$i++)$max=max($max,count($rows[$i]));$headers=[];$used=[];$section='';
    for($c=0;$c<$max;$c++){ $top=trim((string)($rows[0][$c]??''));if($top!=='')$section=preg_match('/^(Glas|Rahmen)$/iu',$top)?$top:'';$parts=[];for($r=0;$r<$headerRows;$r++){ $x=trim((string)($rows[$r][$c]??''));if($x!=='')$parts[]=$x; }$sub=trim((string)($rows[1][$c]??''));if($top===''&&$section!==''&&preg_match('/^(Breite|Höhe|Hoehe)$/iu',$sub))array_unshift($parts,$section);$parts=array_values(array_unique($parts));$h=trim(implode(' ',$parts));if($h==='')$h='spalte_'.($c+1);$base=$h;$k=2;while(isset($used[msNorm($h)]))$h=$base.' '.$k++;$used[msNorm($h)]=true;$headers[$c]=$h; }
    $out=[];for($i=$headerRows;$i<count($rows);$i++){ $e=[];foreach($headers as $c=>$h)$e[$h]=trim((string)($rows[$i][$c]??''));if(!array_filter($e,fn($x)=>$x!==''))continue;$hasFloor=false;foreach(array_keys($e) as $k){$nk=msNorm((string)$k);if(str_contains($nk,'etage')||str_contains($nk,'geschoss')||$nk==='floor'){$hasFloor=trim((string)$e[$k])!=='';if(!$hasFloor)$e[$k]=$sheet;$hasFloor=true;break;}}if(!$hasFloor)$e['Etage']=$sheet;$e['__sheet_name']=$sheet;$out[]=$e;}return$out;
}
function msXlsx(string $path): array {
    $z=new ZipArchive();if($z->open($path)!==true)return[];$shared=[];$sx=$z->getFromName('xl/sharedStrings.xml');if($sx!==false&&($xml=simplexml_load_string((string)$sx))!==false){foreach($xml->si as $si){$p=[];if(isset($si->t))$p[]=(string)$si->t;foreach($si->r as $r)if(isset($r->t))$p[]=(string)$r->t;$shared[]=implode('',$p);}}
    $wbx=$z->getFromName('xl/workbook.xml');$relx=$z->getFromName('xl/_rels/workbook.xml.rels');if($wbx===false||$relx===false){$z->close();return[];}$wb=simplexml_load_string((string)$wbx);$rels=simplexml_load_string((string)$relx);if($wb===false||$rels===false){$z->close();return[];}
    $relMap=[];foreach($rels->Relationship as $r)$relMap[(string)$r['Id']]=(string)$r['Target'];$ns=$wb->getNamespaces(true);$rns=$ns['r']??null;$all=[];$sheets=[];
    foreach($wb->sheets->sheet as $sh){$state=(string)($sh['state']??'visible');if($state==='hidden'||$state==='veryHidden')continue;$name=trim((string)$sh['name']);$attrs=$rns?$sh->attributes($rns):null;$rid=$attrs?(string)($attrs['id']??''):'';$target=$relMap[$rid]??'';if($target==='')continue;$sheetPath=ltrim($target,'/');if(!str_starts_with($sheetPath,'xl/'))$sheetPath='xl/'.$sheetPath;$x=$z->getFromName($sheetPath);if($x===false)continue;$xml=simplexml_load_string((string)$x);if($xml===false||!isset($xml->sheetData->row))continue;$raw=[];foreach($xml->sheetData->row as $row){$map=[];foreach($row->c as $cell){$col=msCol((string)$cell['r']);$type=(string)$cell['t'];$v='';if($type==='s')$v=$shared[(int)((string)$cell->v)]??'';elseif($type==='inlineStr'){$parts=[];if(isset($cell->is->t))$parts[]=(string)$cell->is->t;foreach($cell->is->r as $run)if(isset($run->t))$parts[]=(string)$run->t;$v=implode('',$parts);}elseif($type==='b')$v=((string)$cell->v==='1')?'true':'false';else$v=(string)$cell->v;if($col>0)$map[$col]=trim($v);}if(!$map)continue;ksort($map);$last=max(array_keys($map));$dense=[];for($i=1;$i<=$last;$i++)$dense[]=$map[$i]??'';$raw[]=$dense;} $assoc=msRowsAssoc($raw,$name);$all=array_merge($all,$assoc);$sheets[]=['name'=>$name,'rows'=>count($assoc)];}
    $z->close();return['rows'=>$all,'sheets'=>$sheets];
}
function msCsv(string $path): array { $sample=@file_get_contents($path,false,null,0,4096)?:'';$d=substr_count($sample,',')>substr_count($sample,';')?',':';';$raw=[];$h=fopen($path,'rb');if(!$h)return[];while(($r=fgetcsv($h,0,$d))!==false)$raw[]=array_map(fn($v)=>trim((string)$v),$r);fclose($h);return msRowsAssoc($raw,'CSV'); }

$excelPath=msCfg('MS_SHAREPOINT_EXCEL_PATH','VS Schäden/Marc/Privatgutachten/2026/Bundesministerium Verteidigung_Bonn/BW fesnterprüfung.xlsx');
$rootPath=msCfg('MS_SHAREPOINT_PROJECT_PATH',dirname($excelPath));$folder=msItemByPath($rootPath);$fid=(string)($folder['id']??'');if($fid==='')apiError(404,'Projektordner nicht gefunden.');
$files=array_values(array_filter(msTree($fid),fn($i)=>!empty($i['file'])&&preg_match('/\.(xlsx|xlsm|csv)$/i',(string)($i['name']??''))));
usort($files,fn($a,$b)=>strnatcasecmp((string)($a['relative_path']??''),(string)($b['relative_path']??'')));
$rows=[];$columns=[];$names=[];$sheetReport=[];$warnings=[];
foreach($files as $f){$id=(string)($f['id']??'');$name=(string)($f['name']??'');if($id===''||$name==='')continue;try{$dl=msGraph('https://graph.microsoft.com/v1.0/drives/'.rawurlencode(msDrive()).'/items/'.rawurlencode($id).'/content',true);$tmp=tempnam(sys_get_temp_dir(),'msx-');if($tmp===false||file_put_contents($tmp,$dl['body'])===false)throw new RuntimeException('Zwischenspeichern fehlgeschlagen');$ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));if($ext==='csv'){$parsed=['rows'=>msCsv($tmp),'sheets'=>[['name'=>'CSV','rows'=>0]]];$parsed['sheets'][0]['rows']=count($parsed['rows']);}else{$parsed=msXlsx($tmp);}@unlink($tmp);foreach(($parsed['rows']??[]) as $r){$r['__source_file']=(string)($f['relative_path']??$name);foreach(array_keys($r) as $c)if(!str_starts_with((string)$c,'__'))$columns[$c]=true;$rows[]=$r;}$names[]=(string)($f['relative_path']??$name);$sheetReport[]=['file'=>(string)($f['relative_path']??$name),'sheets'=>$parsed['sheets']??[]];}catch(Throwable $e){$warnings[]=(string)($f['relative_path']??$name).': '.$e->getMessage();}}
if(!$rows)apiError(422,'Keine lesbaren Excel-Daten gefunden.');
apiJson(['ok'=>true,'file_name'=>count($names).' SharePoint-Tabellen / alle Arbeitsblätter zusammengeführt','file_names'=>$names,'files_processed'=>count($names),'rows'=>$rows,'columns'=>array_keys($columns),'sheet_report'=>$sheetReport,'warnings'=>array_slice($warnings,0,20)]);
