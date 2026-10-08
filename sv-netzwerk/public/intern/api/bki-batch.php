<?php
declare(strict_types=1);

function bkBatchText(string $text): string {
  return trim((string)preg_replace('/\s+/u',' ',str_replace(["\u{00a0}",'²'],[' ','2'],$text)));
}
function bkBatchUnit(string $unit): string {
  $unit=strtolower(bkBatchText($unit));
  $unit=str_replace([' ','rohrleitung','.'], '', $unit);
  return match($unit){'pausch','pauschale'=>'psch','stk','stück'=>'st',default=>$unit};
}
function bkBatchNumber(string $text): float {
  $text=preg_replace('/[^0-9,.\-]/','',$text);
  if(str_contains($text,','))$text=str_replace(',','.',str_replace('.','',$text));
  return (float)$text;
}
/** Read the price directly from the original BKI row; models only select a code. */
function bkBatchBindSource(array $component,array $excerpts,string $level): array {
  $code=trim((string)($component['position_code']??''));if($code==='')return $component;
  foreach($excerpts as $excerpt){
    $text=(string)($excerpt['text']??'');$end=strpos($text,$code);if($end===false)continue;
    $before=substr($text,0,$end);$lineStart=strrpos($before,"
");$priceLine=substr($before,$lineStart===false?0:$lineStart+1);
    if(!preg_match('/((?:(?:[0-9]+(?:[.,][0-9]+)?€|[–-])\s+){4}(?:[0-9]+(?:[.,][0-9]+)?€|[–-]))\s+\[([^\]]+)\]/u',$priceLine,$match))continue;
    if(bkBatchUnit($match[2])!==bkBatchUnit((string)($component['unit']??'')))continue;
    $prices=preg_split('/\s+/u',trim($match[1]));$token=$prices[$level==='low'?1:($level==='high'?3:2)]??'';
    $price=bkBatchNumber($token);if($price<=0)continue;
    $start=max(0,$end-1600);if(preg_match_all('/(?:^|\n)[0-9]+ [^\n]*KG [0-9]+/u',$before,$headers,PREG_OFFSET_CAPTURE))$start=end($headers[0])[1];
    $quote=substr($text,$start,$end+strlen($code)-$start);if(strlen($quote)>6000)continue;
    $component['unit_price']=$price;$component['price_text']=$token;$component['source_name']=(string)($excerpt['filename']??'');$component['source_quote']=trim($quote);$component['source_bound']=true;
    return $component;
  }
  return $component;
}

/** Bind every proposed price to an actual returned file-search excerpt. */
function bkBatchEvidence(array $component,array $excerpts): bool {
  $quote=bkBatchText((string)($component['source_quote']??''));
  $code=bkBatchText((string)($component['position_code']??''));
  $token=bkBatchText((string)($component['price_text']??''));
  $page=trim((string)($component['source_page']??''));
  $unit=bkBatchUnit((string)($component['unit']??''));
  if(strlen($quote)<10||strlen($quote)>6000||$code===''||$token===''||preg_match('/(?:Seite|S\.)\s*0\b/i',$page)||$page==='0')return false;
  if($unit===''||!preg_match('/(?<![a-z0-9])'.preg_quote($unit,'/').'(?![a-z0-9])/iu',$quote))return false;
  if(!str_contains($quote,$code)||!preg_match('/(?<![0-9])'.preg_quote($token,'/').'(?![0-9])/u',str_replace($code,'',$quote))||abs(bkBatchNumber($token)-(float)($component['unit_price']??0))>.005)return false;
  foreach($excerpts as $excerpt){
    if(($excerpt['filename']??'')!==($component['source_name']??''))continue;
    if(str_contains(bkBatchText((string)($excerpt['text']??'')),$quote))return true;
  }
  return false;
}

function bkBatchScopeIssue(array $component,array $row,array $facts=[]): string {
  $scope=(string)($row['description']??'').' '.(string)($row['scope']??'').' '.(string)($facts['notes']??'');
  $source=(string)($component['description']??'').' '.(string)($component['source_quote']??'');
  if(preg_match('/\b([RF] ?(?:30|60|90|120))\b/u',$source,$class)&&!preg_match('/\b'.preg_quote($class[1],'/').'\b/u',$scope))return 'Die BKI-Position setzt '.$class[1].' voraus; die erforderliche Feuerwiderstandsklasse ist im KVA nicht belegt.';
  return '';
}

function bkBatchQuantity(array $component,array $row,array $rows,array $facts): bool {
  $quantity=(float)($component['quantity']??0);$unit=bkBatchUnit((string)($component['unit']??''));
  if(!is_finite($quantity)||$quantity<=0)return false;
  if($unit===bkBatchUnit((string)$row['unit'])&&abs($quantity-(float)$row['quantity'])<.00001)return true;
  $source=is_array($component['quantity_source']??null)?$component['quantity_source']:[];
  if(($source['type']??'')==='kva')foreach($rows as$other)if((string)$other['row_id']===(string)($source['row_id']??'')&&$unit===bkBatchUnit((string)$other['unit'])&&abs($quantity-(float)$other['quantity'])<.00001)return true;
  if(($source['type']??'')!=='fact')return false;
  $quote=bkBatchText((string)($source['quote']??''));$notes=bkBatchText((string)($facts['notes']??''));
  if(strlen($quote)<3||!str_contains($notes,$quote))return false;
  preg_match_all('/(?<![0-9])[0-9]+(?:[.,][0-9]+)?\s*(m2|m|t|kg|Stk|Stück|St|psch|Pausch)(?![a-z0-9])/iu',$quote,$matches,PREG_SET_ORDER);
  foreach($matches as$match)if(bkBatchUnit($match[1])===$unit&&abs(bkBatchNumber(substr($match[0],0,-strlen($match[1])))-$quantity)<.00001)return true;
  return false;
}
function bkBatchValidate(array $raw,array $rows,array $excerpts,array $facts=[],string $level='mid'): array {
  $byId=[];foreach(($raw['positions']??[])as $result){if(is_array($result))$byId[(string)($result['row_id']??'')]=$result;}
  $positions=[];$used=[];
  foreach($rows as $row){
    $result=$byId[(string)$row['row_id']]??[];
    $reason=trim((string)($result['reason']??''));
    $components=is_array($result['components']??null)?$result['components']:[];
    $ready=($result['coverage']??'')==='complete'&&count($components)>0;
    $checked=[];$keys=[];
    foreach($components as $component){
      if(!is_array($component)){$ready=false;continue;}
      $component=bkBatchBindSource($component,$excerpts,$level);
      $quantity=(float)($component['quantity']??0);$ep=(float)($component['unit_price']??0);
      $sameUnit=bkBatchUnit((string)$row['unit'])===bkBatchUnit((string)($component['unit']??''));
      // Automatic quantities may only reuse the explicit quantity of this same KVA row.
      // Conversions and quantities derived from other rows stay questions, not invented values.
      $quantityValid=bkBatchQuantity($component,$row,$rows,$facts);
      $evidence=bkBatchEvidence($component,$excerpts);$scopeIssue=bkBatchScopeIssue($component,$row,$facts);if($scopeIssue!==''){$ready=false;$reason.=' '.$scopeIssue;}
      // Page numbers supplied by a model are not citations unless the retrieved
      // original text proves them. The verbatim excerpt remains the citation.
      $page=(string)($component['source_page']??'');$pageProved=false;
      if(preg_match('/\b([1-9][0-9]*)\b/',$page,$pageMatch))foreach($excerpts as$excerpt)if(($excerpt['filename']??'')===($component['source_name']??'')&&preg_match('/(?:Seite|S\.|page)\s*'.preg_quote($pageMatch[1],'/').'\b|^\s*'.preg_quote($pageMatch[1],'/').'\s*$/imu',(string)($excerpt['text']??'')))$pageProved=true;
      if(!$pageProved)$component['source_page']='';
      $key=(string)($component['position_code']??'').'|'.bkBatchUnit((string)($component['unit']??'')).'|'.$quantity;
      if(!$quantityValid||!$evidence||!is_finite($ep)||$ep<=0||isset($used[$key])||isset($keys[$key])){
        $ready=false;
        $reason.=!$quantityValid?' BKI-Menge bzw. Umrechnung ist nicht belegt.':(!$evidence?' Preis oder Quellenbeleg ist nicht eindeutig nachgewiesen.':' Möglicher Doppelansatz oder ungültiger Preis.');
      }
      $keys[$key]=true;
      $component['evidence_verified']=$evidence;$component['quantity_verified']=$quantityValid;
      $checked[]=$component;
    }
    if($ready)foreach($keys as $key=>$_)$used[$key]=true;
    $positions[]=['row_id'=>$row['row_id'],'source_position'=>$row['source_position'],'description'=>$row['description'],'status'=>$ready?'ready':'open','reason'=>$ready?trim((string)($result['reason']??'')):($reason!==''?trim($reason):'Keine vollständige, belegte BKI-Zuordnung gefunden.'),'components'=>$ready?$checked:[],'source_candidates'=>$checked,'offered_total'=>$row['offered_total']??null];
  }
  return ['retrieval_count'=>count($excerpts),'retrieval_sources'=>array_values(array_unique(array_column($excerpts,'filename'))),'retrieval_excerpts'=>$excerpts,'positions'=>$positions,'questions'=>is_array($raw['questions']??null)?$raw['questions']:[]];
}

function bkBatchSearch(array $input): array {
  $rows=[];
  foreach(($input['rows']??[])as $index=>$row){
    if(!is_array($row)||trim((string)($row['description']??''))==='')continue;
    $rows[]=['row_id'=>(string)$index,'source_position'=>trim((string)($row['source_position']??$index+1)),'description'=>trim((string)$row['description']),'scope'=>trim((string)($row['scope']??'')),'quantity'=>(float)($row['quantity']??0),'unit'=>trim((string)($row['unit']??'')),'offered_total'=>is_numeric($row['offered_total']??null)?(float)$row['offered_total']:null];
  }
  if(!$rows||count($rows)>60)throw new RuntimeException('Bitte 1 bis 60 KVA-Positionen auswählen.');
  set_time_limit(600);
  require_once __DIR__.'/bki-catalog-calculation.php';
  return bkCatalogCalculate($rows,$input);
}
