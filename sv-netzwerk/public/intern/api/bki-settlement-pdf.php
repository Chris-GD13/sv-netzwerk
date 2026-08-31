<?php
declare(strict_types=1);

header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
$data = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($data)) { http_response_code(400); echo 'Invalid payload'; exit; }

function cp(string $text): string { $value = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text); return $value === false ? (preg_replace('/[^\x20-\x7E]/', '?', $text) ?? '') : $value; }
function esc(string $text): string { return str_replace(['\\','(',')',"\r"], ['\\\\','\\(','\\)',''], cp($text)); }
function money(float $value): string { return number_format($value, 2, ',', '.') . ' EUR'; }
function numberValue(float $value): string { return number_format($value, abs($value-round($value))<0.0001 ? 0 : 2, ',', '.'); }
function displayDate(string $value): string { $date=DateTimeImmutable::createFromFormat('Y-m-d',$value); return $date?$date->format('d.m.Y'):$value; }
function wrapText(string $text, int $max=70): array {
  $text=trim(preg_replace('/\s+/u',' ',$text)??''); if($text==='')return [''];
  $out=[];$line='';foreach(preg_split('/\s+/u',$text)?:[] as $word){$test=$line===''?$word:$line.' '.$word;if(mb_strlen($test,'UTF-8')>$max&&$line!==''){$out[]=$line;$line=$word;}else{$line=$test;}}if($line!=='')$out[]=$line;return $out;
}

$caseNo=trim((string)($data['case_no']??''));
$vn=trim((string)($data['vn']??''));
$location=trim((string)($data['location']??''));
$date=trim((string)($data['date']??date('Y-m-d')));
$regulator=trim((string)($data['regulator']??'Christian Wächter'));
$note=trim((string)($data['note']??''));
$vat=(float)($data['vat']??0);
$lines=is_array($data['lines']??null)?$data['lines']:[];
if(!$lines){http_response_code(400);echo 'Keine Kalkulationspositionen';exit;}

$navy=[0.027,0.102,0.180];$blue=[0.090,0.196,0.286];$orange=[1.000,0.616,0.071];$muted=[0.350,0.443,0.522];$lineColor=[0.820,0.867,0.902];$panel=[0.956,0.972,0.984];$section=[0.910,0.945,0.968];
$pages=[];$ops=[];$y=0.0;

function textLine(array &$ops,float $x,float $y,string $text,float $size=9,bool $bold=false,array $color=[0,0,0]):void{$font=$bold?'F2':'F1';$ops[]=sprintf('BT /%s %.2F Tf %.3F %.3F %.3F rg %.2F %.2F Td (%s) Tj ET',$font,$size,$color[0],$color[1],$color[2],$x,$y,esc($text));}
function rightText(array &$ops,float $right,float $y,string $text,float $size=9,bool $bold=false,array $color=[0,0,0]):void{$width=strlen(cp($text))*$size*0.49;textLine($ops,max(40,$right-$width),$y,$text,$size,$bold,$color);}
function fillRect(array &$ops,float $x,float $y,float $width,float $height,array $color):void{$ops[]=sprintf('%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f',$color[0],$color[1],$color[2],$x,$y,$width,$height);}
function strokeLine(array &$ops,float $x1,float $y1,float $x2,float $y2,array $color,float $width=.5):void{$ops[]=sprintf('%.2F w %.3F %.3F %.3F RG %.2F %.2F m %.2F %.2F l S',$width,$color[0],$color[1],$color[2],$x1,$y1,$x2,$y2);}
function drawLogo(array &$ops):void{global $navy,$orange,$muted;textLine($ops,45,793,'SV',26,true,$orange);textLine($ops,88,799,'sv-netzwerk.eu',15,true,$navy);textLine($ops,89,787,'BAU - SCHADEN - REGULIERUNG',6.5,true,$muted);}
function drawPageHeader(array &$ops,bool $continuation=false):float{global $navy,$orange,$muted,$lineColor;drawLogo($ops);rightText($ops,550,799,'ABGELTUNGSVEREINBARUNG',8,true,$navy);if($continuation)rightText($ops,550,787,'Fortsetzung',7,false,$muted);strokeLine($ops,45,775,550,775,$lineColor,.7);fillRect($ops,45,771,72,3,$orange);return 752;}
function drawTableHeader(array &$ops,float &$y):void{global $navy,$panel;fillRect($ops,45,$y-20,505,20,$panel);textLine($ops,50,$y-13,'Pos.',7.5,true,$navy);textLine($ops,78,$y-13,'Leistung',7.5,true,$navy);textLine($ops,318,$y-13,'Grundlage',7.5,true,$navy);rightText($ops,462,$y-13,'Betrag brutto',7.5,true,$navy);textLine($ops,472,$y-13,'Regulierung',7.5,true,$navy);$y-=23;}
function startPage(array &$pages,array &$ops,float &$y,bool $table=false):void{if($ops)$pages[]=$ops;$ops=[];$y=drawPageHeader($ops,count($pages)>0);if($table)drawTableHeader($ops,$y);}
function needSpace(array &$pages,array &$ops,float &$y,float $height,bool $table=false):void{if($y-$height<66)startPage($pages,$ops,$y,$table);}
function paragraph(array &$pages,array &$ops,float &$y,string $text,int $max=96,float $size=9,bool $bold=false):void{global $navy;foreach(wrapText($text,$max)as$line){needSpace($pages,$ops,$y,13,false);textLine($ops,50,$y,$line,$size,$bold,$navy);$y-=$size+4;}$y-=4;}

startPage($pages,$ops,$y,false);
textLine($ops,45,$y,'Abgeltungsvereinbarung',19,true,$navy);$y-=18;
textLine($ops,45,$y,'Schadenkalkulation als Grundlage der Regulierung',9,false,$muted);$y-=22;
fillRect($ops,45,$y-98,505,98,$panel);fillRect($ops,45,$y-98,4,98,$orange);
textLine($ops,60,$y-16,'SCHADEN-NR.',7,true,$muted);textLine($ops,60,$y-31,$caseNo!==''?$caseNo:'-',10,true,$navy);
textLine($ops,420,$y-16,'DATUM',7,true,$muted);textLine($ops,420,$y-31,displayDate($date),9,true,$navy);
textLine($ops,60,$y-50,'VERSICHERUNGSNEHMER / OBJEKT',7,true,$muted);foreach(array_slice(wrapText($vn!==''?$vn:'-',62),0,2)as$index=>$value)textLine($ops,230,$y-50-$index*10,$value,8.5,$index===0,$navy);
textLine($ops,60,$y-82,'SCHADENORT',7,true,$muted);textLine($ops,133,$y-82,$location!==''?$location:'-',8.5,false,$navy);
$y-=112;drawTableHeader($ops,$y);

$totalGross=0.0;$payout=0.0;$position=0;$rowIndex=0;
foreach($lines as$line){
  if(($line['type']??'')==='section'){
    $sectionLines=wrapText((string)($line['description']??'KVA-Positionen'),77);$height=max(28,14+count($sectionLines)*11);needSpace($pages,$ops,$y,$height,true);
    fillRect($ops,45,$y-$height,505,$height,$section);fillRect($ops,45,$y-$height,4,$height,$orange);foreach($sectionLines as$index=>$value)textLine($ops,58,$y-18-$index*11,$value,9,true,$navy);$y-=$height+4;$rowIndex=0;continue;
  }
  $position++;$quantity=(float)($line['quantity']??0);$unit=trim((string)($line['unit']??''));$unitPrice=(float)($line['unit_price']??0);$factor=(float)($line['regional_factor']??1);$net=$quantity*$unitPrice*$factor;$gross=$net*(1+$vat/100);$totalGross+=$gross;
  $mode=(string)($line['settlement_mode']??'restore');$percent=max(0,min(100,(float)($line['settlement_percent']??30)));$settlementAmount=$mode==='percent'?$net*$percent/100:($mode==='full'?$gross:0.0);if($mode!=='restore')$payout+=$settlementAmount;
  $description=wrapText((string)($line['description']??'Freie Position'),46);$basisText=numberValue($quantity).($unit!==''?' '.$unit:'').' x '.money($unitPrice);if(abs($factor-1)>.0001)$basisText.=' x '.numberValue($factor);$basis=wrapText($basisText,16);
  $regulation=$mode==='percent'?[numberValue($percent).' % vom Netto',money($settlementAmount)]:($mode==='full'?['Auszahlung 100 %',money($settlementAmount)]:['Wiederherstellung']);
  $height=max(36,16+count($description)*10,16+count($basis)*9,16+count($regulation)*10);needSpace($pages,$ops,$y,$height,true);if($rowIndex%2===1)fillRect($ops,45,$y-$height,505,$height,[.985,.990,.994]);
  textLine($ops,51,$y-16,(string)$position,8,true,$navy);foreach($description as$index=>$value)textLine($ops,78,$y-16-$index*10,$value,8,false,$navy);foreach($basis as$index=>$value)textLine($ops,318,$y-16-$index*9,$value,7.2,false,$muted);rightText($ops,462,$y-16,money($gross),8,true,$navy);foreach($regulation as$index=>$value)textLine($ops,472,$y-16-$index*10,$value,$index===0?7.2:7.8,$index>0,$index>0?$navy:$muted);
  strokeLine($ops,45,$y-$height,550,$y-$height,$lineColor,.35);$y-=$height;$rowIndex++;
}

needSpace($pages,$ops,$y,102,false);$y-=14;fillRect($ops,45,$y-70,505,70,$panel);fillRect($ops,45,$y-70,4,70,$orange);
textLine($ops,60,$y-18,'KALKULATIONSSUMME',7,true,$muted);textLine($ops,60,$y-37,'Wiederherstellungsbetrag brutto',9,false,$navy);rightText($ops,535,$y-37,money($totalGross),12,true,$navy);textLine($ops,60,$y-56,'Auszahlung / Abgeltung',9,true,$navy);rightText($ops,535,$y-56,money($payout),12,true,$navy);$y-=92;
if($note!==''){$noteParts=preg_split('/\n\s*\n/u',$note)?:[];$noteHeight=34;foreach($noteParts as$part)$noteHeight+=count(wrapText(trim($part),96))*13+4;if($y-($noteHeight+105)<66)startPage($pages,$ops,$y,false);textLine($ops,45,$y,'Vereinbarung',13,true,$navy);$y-=8;fillRect($ops,45,$y-2,46,3,$orange);$y-=18;foreach($noteParts as$index=>$part)paragraph($pages,$ops,$y,trim($part),96,9,$index===0);}
needSpace($pages,$ops,$y,105,false);$y-=28;strokeLine($ops,50,$y,245,$y,$blue,.8);strokeLine($ops,350,$y,545,$y,$blue,.8);$y-=15;textLine($ops,50,$y,'Versicherungsnehmer / Bevollmaechtigter',7.5,true,$navy);textLine($ops,350,$y,$regulator,8.5,true,$navy);$y-=12;textLine($ops,350,$y,'Regulierer',7.5,false,$muted);if($ops)$pages[]=$ops;

$pageCount=count($pages);foreach($pages as$index=>&$pageOps){strokeLine($pageOps,45,45,550,45,$lineColor,.5);textLine($pageOps,45,31,'SV-Netzwerk Pruefportal',6.5,false,$muted);rightText($pageOps,550,31,'Seite '.($index+1).' von '.$pageCount,6.5,false,$muted);}unset($pageOps);
$objects=[1=>'<< /Type /Catalog /Pages 2 0 R >>'];$pageObjectStart=5;$contentObjectStart=$pageObjectStart+$pageCount;$kids=[];for($index=0;$index<$pageCount;$index++)$kids[]=($pageObjectStart+$index).' 0 R';$objects[2]='<< /Type /Pages /Kids ['.implode(' ',$kids).'] /Count '.$pageCount.' >>';$objects[3]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';$objects[4]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
foreach($pages as$index=>$pageOps){$objects[$pageObjectStart+$index]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents '.($contentObjectStart+$index).' 0 R >>';$stream=implode("\n",$pageOps);$objects[$contentObjectStart+$index]="<< /Length ".strlen($stream).">>\nstream\n$stream\nendstream";}
ksort($objects);$pdf="%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";$offsets=[0];foreach($objects as$number=>$object){$offsets[$number]=strlen($pdf);$pdf.="$number 0 obj\n$object\nendobj\n";}$xref=strlen($pdf);$max=max(array_keys($objects));$pdf.="xref\n0 ".($max+1)."\n0000000000 65535 f \n";for($index=1;$index<=$max;$index++)$pdf.=sprintf('%010d 00000 n ',$offsets[$index]??0)."\n";$pdf.="trailer\n<< /Size ".($max+1)." /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
$filename=preg_replace('/[^A-Za-z0-9._-]+/','-',$caseNo!==''?$caseNo:'Schaden').'_Abgeltungsvereinbarung_'.$date.'.pdf';header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="'.$filename.'"');header('Content-Length: '.strlen($pdf));echo$pdf;
