<?php
declare(strict_types=1);

function gfInstructionUsesSavedCalculation(string $instructions): bool
{
    return preg_match('/(?:\b(?:auf\s+grund|aufgrund|grundlage|anhand|aus)\b.{0,100}\bkalkulation\b|\bkalkulation\b.{0,100}\b(?:schlusserkl[aä]rung|zugrunde\s+legen|übernehmen|verwenden)\b)/uis',$instructions)===1;
}

function gfSavedCalculationShortDescription(array $item): string
{
    $code=trim((string)($item['source_position_code']??$item['position_code']??''));
    $labels=[
        '01.01.000'=>'Leckageortungspauschale',
        '01.03.002'=>'Leckagereparatur',
        '02.01.000'=>'Trocknung Grundpauschale',
        '02.03.000'=>'Raumtrocknung',
        '03.01.000'=>'Grundsanierungspauschale',
        '07.00.000'=>'Entsorgung Kleinmengen',
        '09.00.001'=>'Dispersionsanstrich',
        '09.00.004'=>'Tiefgrund',
        '10.00.000'=>'Putzrückbau',
        '10.00.001'=>'Haftgrund',
        '10.01.000'=>'Putzflächen ausbessern',
        '13.02.000'=>'Laminat ausbauen',
        '13.04.000'=>'Trittschalldämmung',
        '13.05.004'=>'Laminat liefern und verlegen',
    ];
    if(isset($labels[$code]))return$labels[$code];
    $description=trim((string)($item['description']??''));
    $description=preg_replace('/^(?:Ortung\s*\/\s*Reparatur|Trocknung|Rückbau(?:\s*\/\s*Entsorgung|\s+Laminat)?|Wiederherstellung(?:\s+Laminat)?)\s*:\s*/ui','',$description)??$description;
    $description=preg_replace('/\s*[\(\[].*$/u','',$description)??$description;
    $description=trim(preg_replace('/\s+/u',' ',$description)??$description," \t\n\r\0\x0B:;,.–-");
    if(mb_strlen($description,'UTF-8')>55)$description=rtrim(mb_substr($description,0,55,'UTF-8')).'…';
    return$description!==''?$description:'Kalkulationsposition';
}

function gfSavedCalculationEntriesFromRow(array $calculation): array
{
    $items=json_decode((string)($calculation['items_json']??''),true);if(!is_array($items))return[];
    $reference='BKI-Kalkulation'.((int)($calculation['id']??0)>0?' Nr. '.(int)$calculation['id']:'');$date='';$createdAt=trim((string)($calculation['created_at']??''));if($createdAt!==''){try{$date=(new DateTimeImmutable($createdAt))->format('d.m.Y');}catch(Throwable){}}
    $entries=[];foreach($items as$item){if(!is_array($item)||($item['type']??'position')==='section')continue;$description=trim((string)($item['description']??''));$quantity=(float)($item['quantity']??0);$unitPrice=(float)($item['unit_price']??0);$factor=(float)($item['regional_factor']??1);if($factor<=0)$factor=1;$amount=round($quantity*$unitPrice*$factor,2);if($description===''||$quantity<=0||$unitPrice<=0||$amount<=0)continue;$entries[]=['description'=>gfSavedCalculationShortDescription($item),'source_type'=>'Kalkulation','source_reference'=>$reference,'date'=>$date,'recipient'=>'','recipient_type'=>'vn','amount'=>$amount];}
    $vat=round((float)($calculation['vat_total']??0),2);if($entries&&$vat>0){$vatRate=(float)($calculation['vat_rate']??0);$entries[]=['description'=>'Umsatzsteuer'.($vatRate>0?' '.rtrim(rtrim(number_format($vatRate,2,',','.'),'0'),',').' %':''),'source_type'=>'Kalkulation','source_reference'=>$reference,'date'=>$date,'recipient'=>'','recipient_type'=>'vn','amount'=>$vat];}return$entries;
}

function gfLatestSavedCalculationEntries(string $folderId): array
{
    try{$statement=db()->prepare('SELECT id,items_json,net_total,vat_rate,vat_total,gross_total,created_at FROM bki_calculations WHERE folder_id=:folder ORDER BY id DESC LIMIT 1');$statement->execute([':folder'=>$folderId]);$calculation=$statement->fetch(PDO::FETCH_ASSOC);return is_array($calculation)?gfSavedCalculationEntriesFromRow($calculation):[];}catch(Throwable){return[];}
}

function gfApplySavedCalculationToSchlusserklaerung(string $key,array $result,string $folderId,string $instructions): array
{
    if($key!=='schlusserklaerung'||!gfInstructionUsesSavedCalculation($instructions))return$result;$entries=gfLatestSavedCalculationEntries($folderId);if(!$entries)throw new RuntimeException('Schlusserklärung konnte nicht erstellt werden: Im aktiven Fall wurde keine vollständig gespeicherte Kalkulation mit belastbaren Positionen gefunden.');$result['entries']=$entries;$totals=is_array($result['totals']??null)?$result['totals']:[];foreach(['self_retention','partial_payments','direct_payments','rest_reserve']as$field)if(!is_numeric($totals[$field]??null))$totals[$field]=0;$result['totals']=$totals;$result['summary']='Schlusserklärung auf Grundlage der zuletzt im aktiven Fall gespeicherten BKI-Kalkulation.';return$result;
}
