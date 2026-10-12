<?php
declare(strict_types=1);
require_once __DIR__.'/../public/intern/api/pauschale-abgeltung-core.php';
$written=[];$stored=[];$allowed=true;
$meta=['schaden_nr'=>'MUSTER-001','vn_objekt'=>'Musterkunde','schaden_strasse'=>'Musterstraße 1','schaden_plz'=>'12345','schaden_ort'=>'Musterort'];
$calculation=['id'=>17,'case_no'=>'MUSTER-001','vat_rate'=>19,'note'=>'Nur bezeichnete Positionen.','items_json'=>json_encode([
    ['description'=>'Muster Anstrich','quantity'=>10,'unit'=>'m²','unit_price'=>40,'regional_factor'=>1,'settlement_mode'=>'percent','settlement_percent'=>30],
    ['description'=>'Muster Nebenleistung','quantity'=>1,'unit'=>'St','unit_price'=>100,'regional_factor'=>1,'settlement_mode'=>'full'],
    ['description'=>'Weiterhin Wiederherstellung','quantity'=>1,'unit'=>'St','unit_price'=>200,'regional_factor'=>1,'settlement_mode'=>'restore'],
])];
function requireCaseFolderAccess($folder,$user){if(!$GLOBALS['allowed']||$folder!=='case')throw new RuntimeException('Fremder Fall',403);}
function ionosList($query){return ['files'=>str_contains($query['q'],"'end'")?[]:[['id'=>'meta','name'=>'00_Falldaten.json'],['id'=>'end','name'=>'08_Abschluss','mimeType'=>'application/vnd.google-apps.folder']]];}
function ionosBytes($id){return $id==='meta'?json_encode($GLOBALS['meta']):$GLOBALS['stored'][$id];}
function ionosWrite($meta,$bytes=null){$id='form-'.count($GLOBALS['written']);$GLOBALS['written'][]=$meta;$GLOBALS['stored'][$id]=$bytes;return ['id'=>$id];}
function db(){return new class{function prepare($sql){return new class{function execute($args){}function fetch($mode){return $GLOBALS['calculation'];}};}};}
$result=paPrepareForm('case',[]);
if($result['type']!=='pauschale_abgeltung'||$result['status']!=='filled_unsigned_draft'||abs($result['payout']-239)>.001)throw new RuntimeException('Keine belegt ausgefüllte Abgeltung.');
if($written[0]['parents']!==['end']||!str_starts_with($written[0]['name'],'MUSTER-001_SV-Netzwerk_Abgeltungsvereinbarung_Entwurf_Kalkulation-17_'))throw new RuntimeException('Falsche Fallablage.');
$pdf=$stored[$result['id']];
foreach(['sv-netzwerk.eu','MUSTER-001','Musterkunde','239,00 EUR','30 % vom Netto']as$needle)if(!str_contains($pdf,$needle))throw new RuntimeException('Nicht ausgefülltes PDF: '.$needle);
if(str_contains($pdf,'DIE REGULIERER'))throw new RuntimeException('Fremdes Branding.');
$bad=$calculation;$rows=json_decode($bad['items_json'],true);unset($rows[0]['settlement_percent']);$bad['items_json']=json_encode($rows);
try{paSettlementPayload($bad,$meta);throw new RuntimeException('Erfundener Standardsatz akzeptiert');}catch(RuntimeException $e){if(!str_contains($e->getMessage(),'Abgeltungssatz fehlt'))throw$e;}
$bad=$calculation;$bad['case_no']='ANDERER-FALL';
try{paSettlementPayload($bad,$meta);throw new RuntimeException('Fremder Fall akzeptiert');}catch(RuntimeException $e){if(!str_contains($e->getMessage(),'anderen Schadennummer'))throw$e;}
$kva=\SvNetSettlement\build(['document_type'=>'kva_review','case_no'=>'MUSTER-KVA','vat'=>19,'lines'=>[['description'=>'Belegte Leistung','quantity'=>1,'unit_price'=>100,'regional_factor'=>1]],'kva'=>['company'=>'Musterfirma','quote_number'=>'KVA-17','gross_total'=>150]]);
if(!str_contains($kva['bytes'],'119,00 EUR'))throw new RuntimeException('KVA-Export zurückgebaut.');
if(getenv('PA_TEST_PDF'))file_put_contents(getenv('PA_TEST_PDF'),$pdf);
$allowed=false;$before=count($written);
try{paPrepareForm('case',[]);throw new RuntimeException('Fremder Fall akzeptiert');}catch(RuntimeException $error){if($error->getCode()!==403)throw$error;}
if(count($written)!==$before)throw new RuntimeException('Vor Berechtigungsprüfung geschrieben.');
$ui=file_get_contents(__DIR__.'/../src/pages/intern/versicherungsfaelle/index.astro');
foreach(['value="pauschale_abgeltung"',"key==='pauschale_abgeltung'",'/intern/api/pauschale-abgeltung.php','Abgeltungsvereinbarung|Dokumentenindex','href="/intern/kalkulation/"']as$needle)if(!str_contains($ui,$needle))throw new RuntimeException('Fehlende UI-Anbindung '.$needle);
echo "Abgeltung: SV-Netzwerk-Formular, gespeicherte Kalkulation, Nettosatz, volle Auszahlung, KVA-Export und Fremdzugriff geprüft.\n";
