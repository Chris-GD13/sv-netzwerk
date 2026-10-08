<?php
declare(strict_types=1);
require __DIR__.'/../public/intern/api/bki-batch.php';
function check(bool$value,string$message):void{if(!$value)throw new RuntimeException($message);}
$rows=[['row_id'=>'0','source_position'=>'1','description'=>'Gussrohr ausbauen','quantity'=>12,'unit'=>'m','offered_total'=>924],['row_id'=>'1','source_position'=>'2','description'=>'Öffnen','quantity'=>12,'unit'=>'m','offered_total'=>1980]];
$excerpt=['filename'=>'BKI Altbau.pdf','text'=>"Seite 711\n344.000.066 Gussrohr ausbauen, m, 17,00 / 25,00 / 30,00 EUR"];
$component=['position_code'=>'344.000.066','description'=>'Gussrohr ausbauen','unit'=>'m','quantity'=>12,'unit_price'=>25,'price_text'=>'25,00','source_name'=>'BKI Altbau.pdf','source_page'=>'Seite 711','source_quote'=>'344.000.066 Gussrohr ausbauen, m, 17,00 / 25,00 / 30,00 EUR'];
$raw=['positions'=>[['row_id'=>'0','coverage'=>'complete','components'=>[$component]],['row_id'=>'1','coverage'=>'partial','components'=>[$component],'reason'=>'Öffnungsfläche fehlt']]];
$valid=bkBatchValidate($raw,$rows,[$excerpt]);check($valid['positions'][0]['status']==='ready','Source backed complete scope');check($valid['positions'][1]['status']==='open'&&$valid['positions'][1]['components']===[],'Partial scope cannot silently price');
$bad=$raw;$bad['positions'][0]['components'][0]['unit']='m2';check(bkBatchValidate($bad,$rows,[$excerpt])['positions'][0]['status']==='open','Meters cannot become square meters');
$bad=$raw;$bad['positions'][0]['components'][0]['source_quote']='344.000.066 Fantasiepreis 25,00 EUR';check(bkBatchValidate($bad,$rows,[$excerpt])['positions'][0]['status']==='open','Invented quotation blocked');
$bad=$raw;$bad['positions'][0]['components'][0]['unit_price']=30;check(bkBatchValidate($bad,$rows,[$excerpt])['positions'][0]['status']==='open','Price token mismatch blocked');
$bad=$raw;$bad['positions'][0]['components'][0]['source_page']='Seite 0';check(bkBatchValidate($bad,$rows,[$excerpt])['positions'][0]['status']==='open','Page zero blocked');
$bad=$raw;$bad['positions'][0]['components'][0]['source_page']='Seite 900';$result=bkBatchValidate($bad,$rows,[$excerpt]);check($result['positions'][0]['components'][0]['source_page']==='','Unproven page not displayed');
$component['unit']='m2';$component['quantity_source']=['type'=>'fact','quote'=>'12 m² Öffnungsfläche'];
check(!bkBatchQuantity($component,$rows[0],$rows,[]),'No inferred area');check(bkBatchQuantity($component,$rows[0],$rows,['notes'=>'Gemessen: 12 m² Öffnungsfläche']),'Explicit factual area allowed');
$component['quantity']=24;check(!bkBatchQuantity($component,$rows[0],$rows,['notes'=>'Gemessen: 12 m² Öffnungsfläche']),'Fact quantity mismatch blocked');
$component['unit']='t';$component['quantity']=1;check(!bkBatchQuantity($component,['unit'=>'Pausch','quantity'=>1],$rows,[]),'One lump sum cannot become one tonne');
echo "BKI batch source and quantity tests passed\n";

$table=['filename'=>'Original.pdf','text'=>"12 Abwasserleitung, HT-Rohr, DN/OD110 KG 411\nMaterial: Polypropylen (PP)\n30€ 34€ 36€ 42€ 56€ [m] ⏱ 0,35h/m 344.000.075"];
$choice=['position_code'=>'344.000.075','unit'=>'m','unit_price'=>999,'price_text'=>'30€ 34€ 36€ 42€ 56€','source_name'=>'falsche Bezeichnung','source_quote'=>'gekürzter Modelltext'];
$bound=bkBatchBindSource($choice,[$table],'mid');check($bound['unit_price']===36.0&&$bound['source_name']==='Original.pdf','Price comes directly from the original row');check(bkBatchEvidence($bound,[$table]),'Clock and labor time retained in exact original quote');
check(bkBatchBindSource($choice,[$table],'low')['unit_price']===34.0,'Low bound uses lower BKI interval');check(bkBatchBindSource($choice,[$table],'high')['unit_price']===42.0,'High bound uses upper BKI interval');
$choice['unit']='m2';check(empty(bkBatchBindSource($choice,[$table],'mid')['source_bound']),'Source resolver cannot change units');

check(bkBatchScopeIssue(['description'=>'Brandschutzabschottung R90'],['scope'=>'erforderliche Brandschutzmanschetten'])!=='','Fire resistance class cannot be inferred');
check(bkBatchScopeIssue(['description'=>'Brandschutzabschottung R90'],['scope'=>'Abschottung R90'])==='','Explicit fire resistance class accepted');
