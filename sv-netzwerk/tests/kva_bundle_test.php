<?php
declare(strict_types=1);
require_once __DIR__.'/../public/intern/api/kva-bundle.php';
function ensure(bool $ok,string $message):void { if(!$ok)throw new RuntimeException($message); }
$offers=kvaBundleMerge([], [['company'=>'Bau GmbH','quote_number'=>'A/1','has_priced_positions'=>true],['company'=>'Bau GmbH','quote_number'=>'Referenz','has_priced_positions'=>false]],1,12);
$offers=kvaBundleMerge($offers,[['company'=>'Bau GmbH','quote_number'=>'A/1','has_priced_positions'=>true],['company'=>'Bau GmbH','quote_number'=>'A/2','has_priced_positions'=>true]],11,22);
ensure(count($offers)===2,'Überlappende Originalseiten deduplizieren; Bezugnahmen ausschließen.');
$first=array_values($offers)[0];ensure($first['start']===1&&$first['end']===22,'Fortgesetzte Angebote mit allen Seiten lesen.');
$source=file_get_contents(__DIR__.'/../public/intern/api/kva-release.php');
$start=strpos($source,'function krCalculationFiles(');$end=strpos($source,'function krCaseNo(',$start);eval(substr($source,$start,$end-$start));
function krList(string $folder):array { return [['id'=>'brief','name'=>'Brief_Original.pdf'],['id'=>'quote','name'=>'Angebot.pdf'],['id'=>'meta','name'=>'Falldaten.json']]; }
$files=krCalculationFiles('case');ensure(count($files)===2&&in_array('brief',array_column($files,'id'),true),'Brief.pdf muss neben Angebot.pdf auswählbar sein.');
$unsupported=kvaBundlePrepare('Foto.jpg','image/jpeg','image-bytes','',fn()=>[],fn()=>'',fn()=>null,'case');
ensure($unsupported['bytes']==='image-bytes','Fotografieren ohne PDF-Seitenprüfung erhalten.');
$pdf=kvaBundleTemp();ensure(str_ends_with($pdf,'.pdf')&&is_file($pdf),'PDF-Endung für geschützten Serverworker.');unlink($pdf);
echo "kva_bundle_test: OK\n";
ensure(count(kvaBundlePositionWarnings([['offered_total'=>3987]],4036.84))===1,'Fehlende Logistikpauschale muss als Summenabweichung sichtbar werden.');
ensure(kvaBundlePositionWarnings([['offered_total'=>3987],['offered_total'=>49.84],['offered_total'=>999,'optional'=>true]],4036.84)===[],'Gedruckte Kostenposition berücksichtigen; Bedarfspositionen aus der Hauptsumme ausschließen.');
