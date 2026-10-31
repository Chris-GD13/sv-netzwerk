<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
commonHeaders();
requireAuth();
if ($_SERVER['REQUEST_METHOD']!=='POST') apiError(405,'POST erforderlich.');
$body=requestBody();
$home=trim((string)($body['home']??''));
$addresses=array_values(array_filter(array_map(static fn($v)=>trim((string)$v),is_array($body['addresses']??null)?$body['addresses']:[])));
if($home==='')apiError(400,'Heimatadresse fehlt.');
if(count($addresses)<1||count($addresses)>100)apiError(400,'Ungültige Ortsliste.');
function routeHttp(string $url):array{
  $ch=curl_init($url);
  curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>['User-Agent: SV-Netzwerk-Fahrtenbuch/1.0 kontakt@sv-netzwerk.eu'],CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>45]);
  $body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
  return[$status,is_string($body)?$body:''];
}
function routeQuery(string $address,string $home):string{
  $a=preg_replace('/\s+/u',' ',trim($address))??trim($address);
  if(!preg_match('/\b\d{5}\b/u',$a)&&!preg_match('/\b(?:Aalen|Urbach|Dinkelsbühl|Schwäbisch Hall|Waldenburg)\b/ui',$a)){
    $homeParts=explode(',',$home);
    $city=trim((string)end($homeParts));
    if($city!=='')$a.=', '.$city;
  }
  return $a.', Deutschland';
}
function routeGeo(string $address,string $home):?array{
  $q=routeQuery($address,$home);
  [$status,$raw]=routeHttp('https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1&countrycodes=de,at,ch&q='.rawurlencode($q));
  $data=json_decode($raw,true);
  if($status===200&&is_array($data)&&isset($data[0]['lat'],$data[0]['lon']))return['lat'=>(float)$data[0]['lat'],'lon'=>(float)$data[0]['lon'],'label'=>$q];
  return null;
}
$points=[];$unresolved=[];
foreach(array_merge([$home],$addresses,[$home]) as $address){
  $point=routeGeo($address,$home);
  if($point===null){$unresolved[]=$address;continue;}
  $points[]=$point;
  usleep(250000);
}
if(count($unresolved)>0||count($points)<2)apiJson(['ok'=>false,'error'=>'Orte konnten nicht eindeutig geocodiert werden.','unresolved'=>$unresolved],422);
$coords=implode(';',array_map(static fn($p)=>$p['lon'].','.$p['lat'],$points));
[$status,$raw]=routeHttp('https://router.project-osrm.org/route/v1/driving/'.$coords.'?overview=false&steps=false');
$data=json_decode($raw,true);
if($status!==200||!is_array($data)||($data['code']??'')!=='Ok'||empty($data['routes'][0]['distance']))apiJson(['ok'=>false,'error'=>'Die Straßenroute konnte nicht berechnet werden.'],422);
apiJson(['ok'=>true,'km'=>round(((float)$data['routes'][0]['distance'])/1000,1),'query_addresses'=>array_map(static fn($a)=>routeQuery($a,$home),array_merge([$home],$addresses,[$home]))]);
