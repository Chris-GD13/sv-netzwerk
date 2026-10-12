<?php
require __DIR__.'/../public/intern/api/claimsforce-queue-utils.php';
$job=['sync_mode'=>'single','claim_number'=>'26-085905-9'];
$good=['claims'=>1,'updated'=>1,'skipped'=>0,'failed'=>0,'claimNumber'=>'26-085905-9','inventories'=>[['claimNumber'=>'26-085905-9','files'=>145,'messages'=>42,'tasks'=>1,'notes'=>9,'attachments'=>104]]];
if(!cqSingleResultValid($job,$good))throw new Exception('Vollständiger Einzelfall abgelehnt');
$gf=$good;$gf['inventories'][0]['claimNumber']='26-085905-9 GF';
if(!cqSingleResultValid($job,$gf))throw new Exception('GF-Klassifizierung blockiert identischen Schaden');
$gfJob=$job;$gfJob['claim_number'].=' GF';
if(!cqSingleResultValid($gfJob,$good))throw new Exception('Eingabe mit GF blockiert identischen Schaden');
$gf['inventories'][0]['claimNumber']='26-085905-8 GF';
if(cqSingleResultValid($job,$gf))throw new Exception('Anderer GF-Schaden akzeptiert');
$unavailable=$good;$unavailable['inventories'][0]['errors']=[['status'=>404]];
if(cqSingleResultValid($job,$unavailable))throw new Exception('Offener Quellenfehler als vollständig akzeptiert');
$restricted=$good;$restricted['inventories'][0]['errors']=[];$restricted['inventories'][0]['restrictedFiles']=[['id'=>'protected','status'=>403]];
if(!cqSingleResultValid($job,$restricted))throw new Exception('Dokumentierte geschützte Referenz blockiert übrige Inhalte');
foreach([['claims'=>26],['updated'=>0],['claimNumber'=>'26-076821-6'],['inventories'=>[]],['failed'=>1],['inventories'=>[['claimNumber'=>'26-076821-6']]]]as$change){if(cqSingleResultValid($job,array_replace($good,$change)))throw new Exception('Falscher oder unvollständiger Import akzeptiert');}
echo "Einzelfallabschluss weist Sammelläufe, falsche Fälle und unvollständige Ergebnisse zurück.\n";
function apiError($status,$text){throw new RuntimeException($text,$status);}
function db(){return new class{function prepare($sql){return new class{function execute($args){}function fetchColumn(){return $GLOBALS['testOwner']??'';}};}};}
$owner=['stationId'=>'11111111-1111-4111-8111-111111111111','bridgeVersion'=>'1.4.62','workerVersion'=>'1.4.62','protocol'=>2];
$GLOBALS['testOwner']=cqStationKey($owner);
cqRequireOwner(44,$owner);
foreach([array_replace($owner,['stationId'=>'22222222-2222-4222-8222-222222222222']),array_replace($owner,['bridgeVersion'=>'1.4.51']),array_replace($owner,['workerVersion'=>'']),array_replace($owner,['workerVersion'=>'1.4.58']),array_replace($owner,['protocol'=>1]),[]]as$other){
    try{cqRequireOwner(44,$other);throw new Exception('Fremde oder alte Importstation akzeptiert');}catch(RuntimeException$e){if($e->getCode()!==409)throw $e;}
}
echo "Nur die übernehmende aktuelle Importstation darf Fortschritt oder Abschluss schreiben.\n";
// Zwei gleiche Heartbeats innerhalb einer Sekunde ändern in MySQL keine Spalte.
cqConfirmHeartbeat(44,$owner,0);
cqConfirmHeartbeat(44,$owner,1);
$GLOBALS['testOwner']='';
try{cqConfirmHeartbeat(44,$owner,0);throw new Exception('Beendeter Auftrag wurde als aktiver Heartbeat bestätigt');}catch(RuntimeException$e){if($e->getCode()!==409)throw$e;}
$GLOBALS['testOwner']=hash('sha256','22222222-2222-4222-8222-222222222222');
try{cqConfirmHeartbeat(44,$owner,0);throw new Exception('Fremder Auftrag wurde als Heartbeat bestätigt');}catch(RuntimeException$e){if($e->getCode()!==409)throw$e;}
echo "Identische Heartbeats bleiben gültig; beendete und fremde Aufträge bleiben gesperrt.\n";

require_once dirname(__DIR__).'/public/intern/api/profile-routing.php';
foreach(['cw@sv-schuett.eu','ws@sv-schuett.eu']as$email){
 $user=['email'=>$email];
 if(cqSingleSourceProfiles($user,'')!==['christian','jens'])throw new RuntimeException('Beide Quellen fehlen.');
 if(cqSingleSourceProfiles($user,'',['jens'])!==['jens'])throw new RuntimeException('Bestehender Maurer-Fall wird falsch nachgeladen.');
 if(cqSingleSourceProfiles($user,'jens')!==['jens'])throw new RuntimeException('Explizite Quellenwahl fehlt.');
}
foreach(['ms@sv-schuett.eu','hr@sv-schuett.eu']as$email){
 $rejected=false;
 try{cqSingleSourceProfiles(['email'=>$email],'jens');}catch(InvalidArgumentException){$rejected=true;}
 if(!$rejected)throw new RuntimeException('Fremder Zugriff auf Maurer-Quelle.');
}
echo "Erstimport: freigegebene Quellen und Nachladen bestehender Maurer-Fälle geprüft.\n";
