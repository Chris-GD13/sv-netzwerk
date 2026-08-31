<?php
declare(strict_types=1);
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '', true);
if (!is_array($data)) { http_response_code(400); echo 'Invalid payload'; exit; }

function cp(string $s): string { $v = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $s); return $v === false ? preg_replace('/[^\x20-\x7E]/', '?', $s) : $v; }
function esc(string $s): string { return str_replace(['\\','(',')',"\r"], ['\\\\','\\(','\\)',''], cp($s)); }
function money(float $v): string { return number_format($v, 2, ',', '.') . ' EUR'; }
function wrapText(string $text, int $max=92): array {
  $text = trim(preg_replace('/\s+/u', ' ', $text)); if ($text === '') return [''];
  $words = preg_split('/\s+/u', $text) ?: []; $out=[]; $line='';
  foreach ($words as $word) { $test=$line===''?$word:$line.' '.$word; if (mb_strlen($test,'UTF-8')>$max && $line!=='') { $out[]=$line; $line=$word; } else $line=$test; }
  if ($line!=='') $out[]=$line; return $out;
}

$caseNo = trim((string)($data['case_no'] ?? ''));
$vn = trim((string)($data['vn'] ?? ''));
$location = trim((string)($data['location'] ?? ''));
$date = trim((string)($data['date'] ?? date('Y-m-d')));
$regulator = trim((string)($data['regulator'] ?? 'Christian Wächter'));
$note = trim((string)($data['note'] ?? ''));
$vat = (float)($data['vat'] ?? 0);
$lines = is_array($data['lines'] ?? null) ? $data['lines'] : [];
if (!$lines) { http_response_code(400); echo 'Keine Kalkulationspositionen'; exit; }

$pages=[]; $ops=[]; $y=800;
function textLine(array &$ops, float $x, float $y, string $text, int $size=10, bool $bold=false): void { $font=$bold?'F2':'F1'; $ops[]="BT /$font $size Tf 0 g $x $y Td (".esc($text).") Tj ET"; }
function rule(array &$ops, float $x1,float $y1,float $x2,float $y2): void { $ops[]="0.75 w 0.15 0.25 0.35 RG $x1 $y1 m $x2 $y2 l S"; }
function newPage(array &$pages, array &$ops, float &$y): void { if ($ops) $pages[]=$ops; $ops=[]; $y=800; }
function need(array &$pages,array &$ops,float &$y,float $height): void { if ($y-$height<55) newPage($pages,$ops,$y); }
function paragraph(array &$pages,array &$ops,float &$y,string $text,int $size=9,bool $bold=false,int $max=100): void { foreach (wrapText($text,$max) as $line) { need($pages,$ops,$y,14); textLine($ops,48,$y,$line,$size,$bold); $y-=12; } $y-=5; }
function pageHeader(array &$ops,float &$y,string $caseNo): void {
  textLine($ops,48,$y,'SV-NETZWERK',18,true); $y-=15; textLine($ops,48,$y,'BAU - SCHADEN - REGULIERUNG',8,false); $y-=10; rule($ops,48,$y,547,$y); $y-=24;
  textLine($ops,48,$y,'Abgeltungsvereinbarung / Schadenkalkulation',15,true); $y-=18; if($caseNo!==''){textLine($ops,48,$y,'Schaden-Nr.: '.$caseNo,9);$y-=18;}
}
pageHeader($ops,$y,$caseNo);
paragraph($pages,$ops,$y,'VN / Objekt: '.($vn!==''?$vn:'-'),10,true);
paragraph($pages,$ops,$y,'Schadenort: '.($location!==''?$location:'-'),9);
paragraph($pages,$ops,$y,'Datum: '.$date,9);
$y-=4;
textLine($ops,48,$y,'Pos.',8,true); textLine($ops,78,$y,'Leistung',8,true); textLine($ops,340,$y,'Betrag',8,true); textLine($ops,430,$y,'Regelung',8,true); $y-=7; rule($ops,48,$y,547,$y); $y-=14;
$totalGross=0.0; $payout=0.0; $position=0;
foreach($lines as $i=>$line){
  if(($line['type']??'')==='section'){
    $desc=wrapText((string)($line['description']??'KVA-Positionen'),85); $height=max(24,count($desc)*12+8); need($pages,$ops,$y,$height);
    foreach($desc as $j=>$d)textLine($ops,48,$y-$j*12,$d,10,true);
    $y-=$height; rule($ops,48,$y+5,547,$y+5); continue;
  }
  $position++;
  $qty=(float)($line['quantity']??0); $ep=(float)($line['unit_price']??0); $factor=(float)($line['regional_factor']??1); $net=$qty*$ep*$factor; $gross=$net*(1+$vat/100); $totalGross+=$gross;
  $mode=(string)($line['settlement_mode']??'restore'); $percent=max(0,min(100,(float)($line['settlement_percent']??30)));
  $amount=$mode==='percent'?$net*$percent/100:$gross; if($mode!=='restore')$payout+=$amount;
  $ruleText=$mode==='percent'?'Abgeltung '.number_format($percent,0,',','.').' %':($mode==='full'?'Auszahlung 100 %':'Wiederherstellung');
  $desc=wrapText((string)($line['description']??'Freie Position'),46); $height=max(20,count($desc)*11+6); need($pages,$ops,$y,$height);
  textLine($ops,48,$y,(string)$position,8); foreach($desc as $j=>$d)textLine($ops,78,$y-$j*11,$d,8);
  textLine($ops,340,$y,money($mode==='percent'?$amount:$gross),8); foreach(wrapText($ruleText,22) as $j=>$r)textLine($ops,430,$y-$j*11,$r,8);
  $y-=$height; rule($ops,48,$y+5,547,$y+5);
}
$y-=12; need($pages,$ops,$y,50); textLine($ops,48,$y,'Kalkulierter Wiederherstellungsbetrag brutto',10,true); textLine($ops,430,$y,money($totalGross),10,true); $y-=18; textLine($ops,48,$y,'Gesamtauszahlung / Abgeltung',10,true); textLine($ops,430,$y,money($payout),10,true); $y-=28;
if($note!==''){ need($pages,$ops,$y,30); textLine($ops,48,$y,'Vereinbarung',11,true); $y-=18; foreach(preg_split('/\n\s*\n/u',$note)?:[] as $idx=>$p) paragraph($pages,$ops,$y,trim($p),9,$idx===0,100); }
need($pages,$ops,$y,75); $y-=18; rule($ops,48,$y,240,$y); rule($ops,340,$y,547,$y); $y-=13; textLine($ops,48,$y,'VN / Bevollmaechtigter',8,true); textLine($ops,340,$y-1,$regulator,8,true); $y-=12; textLine($ops,340,$y,'Regulierer',8);
$pages[]=$ops;

$objects=[]; $objects[1]='<< /Type /Catalog /Pages 2 0 R >>';
$kids=[]; $pageObjStart=5; $contentObjStart=$pageObjStart+count($pages);
foreach($pages as $i=>$p)$kids[]=($pageObjStart+$i).' 0 R';
$objects[2]='<< /Type /Pages /Kids ['.implode(' ',$kids).'] /Count '.count($pages).' >>';
$objects[3]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
$objects[4]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
foreach($pages as $i=>$p){$objects[$pageObjStart+$i]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents '.($contentObjStart+$i).' 0 R >>'; $stream=implode("\n",$p); $objects[$contentObjStart+$i]="<< /Length ".strlen($stream)." >>\nstream\n$stream\nendstream";}
ksort($objects); $pdf="%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"; $offsets=[0]; foreach($objects as $n=>$obj){$offsets[$n]=strlen($pdf);$pdf.="$n 0 obj\n$obj\nendobj\n";} $xref=strlen($pdf); $max=max(array_keys($objects)); $pdf.="xref\n0 ".($max+1)."\n0000000000 65535 f \n"; for($i=1;$i<=$max;$i++)$pdf.=sprintf('%010d 00000 n ', $offsets[$i]??0)."\n"; $pdf.="trailer\n<< /Size ".($max+1)." /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
$filename=preg_replace('/[^A-Za-z0-9._-]+/','-', $caseNo!==''?$caseNo:'Schaden').'_Abgeltungsvereinbarung_'.$date.'.pdf';
header('Content-Type: application/pdf'); header('Content-Disposition: attachment; filename="'.$filename.'"'); header('Content-Length: '.strlen($pdf)); echo $pdf;
