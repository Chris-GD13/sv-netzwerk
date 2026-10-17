<?php
declare(strict_types=1);
function pbRouteHttp(string $url): array {$ch=curl_init($url);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>25,CURLOPT_HTTPHEADER=>['User-Agent: SV-Netzwerk-Portal/1.0 (info@sv-netzwerk.eu)','Accept: application/json']]);$body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);$data=is_string($body)?json_decode($body,true):null;if($status!==200||!is_array($data))throw new RuntimeException('Routenservice derzeit nicht erreichbar. Strecke und Fahrzeit können manuell eingetragen werden.');return$data;}
function pbRouteCached(string $url,int $ttl=86400): array {
    $dir=sys_get_temp_dir().'/sv-private-route';if(!is_dir($dir)&&!@mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('Adresssuche konnte nicht gestartet werden.');
    $file=$dir.'/'.hash('sha256',$url).'.json';
    if(is_file($file)&&filemtime($file)>time()-$ttl){$cached=json_decode((string)file_get_contents($file),true);if(is_array($cached))return $cached;}
    $lock=fopen($dir.'/service.lock','c+');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Adresssuche ist ausgelastet. Bitte erneut versuchen.');
    try{if(str_contains($url,'nominatim.openstreetmap.org')){rewind($lock);$last=(float)stream_get_contents($lock);$wait=1.1-(microtime(true)-$last);if($wait>0)usleep((int)($wait*1000000));ftruncate($lock,0);rewind($lock);fwrite($lock,(string)microtime(true));fflush($lock);}$result=pbRouteHttp($url);file_put_contents($file,json_encode($result,JSON_UNESCAPED_UNICODE),LOCK_EX);@chmod($file,0600);return $result;}finally{flock($lock,LOCK_UN);fclose($lock);}
}
function pbPostalPlaces(string $postcode): array {
    $postcode=trim($postcode);if(!preg_match('/^[0-9]{5}$/',$postcode))throw new RuntimeException('Bitte eine fünfstellige deutsche PLZ eingeben.');
    try{$r=pbRouteCached('https://api.zippopotam.us/de/'.$postcode,2592000);}catch(Throwable $e){throw new RuntimeException('Keine Ortsvorschläge verfügbar. Bitte PLZ prüfen; der Ort kann weiterhin frei eingetragen werden.');}
    $places=[];foreach($r['places']??[]as$p){$name=trim((string)($p['place name']??''));if($name!=='')$places[$name]=$name;}return array_values($places);
}
function pbExactHouse(array $rows,array $a): array {
    preg_match('/([0-9]+\s*[a-zA-Z]?(?:[-\/][0-9]+[a-zA-Z]?)?)\s*$/u',$a['street'],$m);$house=strtolower(preg_replace('/\s+/','',$m[1]??''));$matches=[];
    foreach($rows as$r){$ad=$r['address']??[];if(($ad['postcode']??'')!==$a['postcode']||$house===''||strtolower(preg_replace('/\s+/','',(string)($ad['house_number']??'')))!==$house)continue;$matches[]=$r;}
    if(!$matches)return[];$first=$matches[0];foreach($matches as$r)if(abs((float)$r['lat']-(float)$first['lat'])>0.0003||abs((float)$r['lon']-(float)$first['lon'])>0.0003)return[];return $first;
}
function pbGeocode(array $a): array {
    foreach(['street','postcode','city']as$k){$a[$k]=trim((string)($a[$k]??''));if($a[$k]==='')throw new RuntimeException('Bitte Straße mit Hausnummer, PLZ und Ort vollständig eingeben.');}
    $q=http_build_query(['street'=>$a['street'],'postalcode'=>$a['postcode'],'city'=>$a['city'],'country'=>'Germany','countrycodes'=>'de','format'=>'jsonv2','addressdetails'=>1,'limit'=>5]);
    $rows=pbRouteCached('https://nominatim.openstreetmap.org/search?'.$q);$r=pbExactHouse($rows,$a);if($r)return$r;
    throw new RuntimeException('Adresse nicht eindeutig gefunden: '.$a['street'].', '.$a['postcode'].' '.$a['city'].'. Bitte PLZ und Ortsvorschlag prüfen sowie Hausnummer ergänzen. Es wurden keine geschätzten Streckenwerte übernommen.');
}
function pbRoute(array $data): array {
    $d=pbClean($data,'christian');try{$from=pbGeocode(pbOrigin($d));}catch(Throwable$e){throw new RuntimeException('Startadresse: '.$e->getMessage());}try{$to=pbGeocode(pbSite($d));}catch(Throwable$e){throw new RuntimeException('Terminadresse: '.$e->getMessage());}
    $url='https://router.project-osrm.org/route/v1/driving/'.rawurlencode($from['lon']).','.rawurlencode($from['lat']).';'.rawurlencode($to['lon']).','.rawurlencode($to['lat']).'?overview=false';$r=pbRouteCached($url);if(($r['code']??'')!=='Ok'||!isset($r['routes'][0]['distance'],$r['routes'][0]['duration']))throw new RuntimeException('Keine Fahrstrecke gefunden. Bitte Werte manuell eingeben.');return['oneway_km'=>round($r['routes'][0]['distance']/1000,1),'oneway_minutes'=>(int)ceil($r['routes'][0]['duration']/60),'route_source'=>'OpenStreetMap / OSRM · '.gmdate('Y-m-d H:i').' UTC','from'=>$from['display_name'],'to'=>$to['display_name']];
}
