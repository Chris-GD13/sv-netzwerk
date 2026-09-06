<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
commonHeaders();
$user=requireAuth();
if(!in_array((string)($user['role']??''),['administrator','projektleiter','pruefer','sachverstaendiger'],true))apiError(403,'Keine Berechtigung.');
if($_SERVER['REQUEST_METHOD']!=='POST'){http_response_code(405);exit;}
$data=json_decode(file_get_contents('php://input')?:'',true);
if(!is_array($data)){http_response_code(400);echo'Ungültige PDF-Daten.';exit;}

function vcp(string $text):string{$v=@iconv('UTF-8','Windows-1252//TRANSLIT',$text);return$v===false?(preg_replace('/[^\x20-\x7E]/','?',$text)??''):$v;}
function vesc(string $text):string{return str_replace(['\\','(',')',"\r"],['\\\\','\\(','\\)',''],vcp($text));}
function vtext(array&$ops,float$x,float$y,string$text,float$size=9,bool$bold=false,array$color=[0,0,0]):void{$ops[]=sprintf('BT /%s %.2F Tf %.3F %.3F %.3F rg %.2F %.2F Td (%s) Tj ET',$bold?'F2':'F1',$size,$color[0],$color[1],$color[2],$x,$y,vesc($text));}
function vright(array&$ops,float$right,float$y,string$text,float$size=9,bool$bold=false,array$color=[0,0,0]):void{$width=strlen(vcp($text))*$size*.49;vtext($ops,max(40,$right-$width),$y,$text,$size,$bold,$color);}
function vrect(array&$ops,float$x,float$y,float$w,float$h,array$c):void{$ops[]=sprintf('%.3F %.3F %.3F rg %.2F %.2F %.2F %.2F re f',$c[0],$c[1],$c[2],$x,$y,$w,$h);}
function vline(array&$ops,float$x1,float$y1,float$x2,float$y2,array$c,float$w=.5):void{$ops[]=sprintf('%.2F w %.3F %.3F %.3F RG %.2F %.2F m %.2F %.2F l S',$w,$c[0],$c[1],$c[2],$x1,$y1,$x2,$y2);}
function vwrap(string$text,int$max=76):array{$text=trim(preg_replace('/\s+/u',' ',$text)??'');if($text==='')return[''];$out=[];$line='';foreach(preg_split('/\s+/u',$text)?:[]as$word){$try=$line===''?$word:$line.' '.$word;if(mb_strlen($try,'UTF-8')>$max&&$line!==''){$out[]=$line;$line=$word;}else$line=$try;}if($line!=='')$out[]=$line;return$out;}

$rows=is_array($data['rows']??null)?array_slice($data['rows'],0,30):[];
$total=max(0,(float)($data['total1914']??0));$current=max(0,(float)($data['currentValue']??0));$factor=max(0,(float)($data['factor']??0));
if(!$rows||$total<=0||$current<=0||$factor<=0){http_response_code(400);echo'Unvollständige Berechnung.';exit;}
$navy=[.027,.102,.180];$orange=[1,.616,.071];$muted=[.35,.443,.522];$line=[.82,.867,.902];$panel=[.956,.972,.984];$green=[.929,.973,.949];$ops=[];
vtext($ops,45,793,'SV',26,true,$orange);vtext($ops,88,799,'sv-netzwerk.eu',15,true,$navy);vtext($ops,89,787,'BAU - SCHADEN - REGULIERUNG',6.5,true,$muted);vright($ops,550,799,'WERTERMITTLUNG 1914',8,true,$navy);vline($ops,45,775,550,775,$line,.7);vrect($ops,45,771,72,3,$orange);
vtext($ops,45,742,'Ermittlung der Versicherungssumme 1914',18,true,$navy);vtext($ops,45,725,'Wohngebäude - vereinfachtes Wertermittlungsverfahren',9,false,$muted);
$y=700;vrect($ops,45,$y-92,505,92,$panel);vrect($ops,45,$y-92,4,92,$orange);
$meta=[['SCHADEN-NR.',(string)($data['caseNo']??'')],['VERSICHERUNGSSCHEIN-NR.',(string)($data['policyNo']??'')],['VERSICHERUNGSNEHMER / OBJEKT',(string)($data['object']??'')],['VERSICHERUNGSGRUNDSTÜCK',(string)($data['address']??'')]];
foreach($meta as$i=>$row){$yy=$y-17-$i*19;vtext($ops,60,$yy,$row[0],6.5,true,$muted);vtext($ops,225,$yy,$row[1]!==''?$row[1]:'-',8.5,$i<2,$navy);}vright($ops,535,$y-17,(string)($data['createdAt']??''),7,false,$muted);$y-=112;
vrect($ops,45,$y-21,505,21,$navy);vtext($ops,52,$y-14,'Berechnungsposition',8,true,[1,1,1]);vright($ops,540,$y-14,'Wert',8,true,[1,1,1]);$y-=25;
foreach($rows as$row){$label=mb_substr(trim((string)($row['label']??'')),0,220);$value=mb_substr(trim((string)($row['value']??'')),0,80);$wrapped=vwrap($label,72);$height=max(25,10+count($wrapped)*10);if($y-$height<145)break;foreach($wrapped as$i=>$part)vtext($ops,52,$y-15-$i*10,$part,8,false,$navy);vright($ops,540,$y-15,$value,8,true,$navy);vline($ops,45,$y-$height,550,$y-$height,$line,.35);$y-=$height;}
$y-=12;vrect($ops,45,$y-68,505,68,$green);vrect($ops,45,$y-68,4,68,$orange);vtext($ops,60,$y-22,'VERSICHERUNGSSUMME 1914',8,true,$muted);vright($ops,535,$y-22,number_format($total,2,',','.').' Mark',13,true,$navy);vtext($ops,60,$y-48,'GEBÄUDEWERT '.(int)($data['year']??2026).' - RECHENFAKTOR '.number_format($factor,1,',','.'),8,true,$muted);vright($ops,535,$y-48,number_format($current,2,',','.').' EUR',13,true,$navy);$y-=88;
$special=trim((string)($data['specialNote']??''));$outbuilding=trim((string)($data['outbuildingUse']??''));if($special!==''||$outbuilding!==''){vtext($ops,45,$y,'Ergänzende Angaben',11,true,$navy);$y-=16;foreach([['Sonderausstattungen',$special],['Nebengebäude',trim($outbuilding.' · '.(string)($data['outbuildingSize']??'').' m² · '.(string)($data['outbuildingConstruction']??''),' ·')]]as$row){if($row[1]==='')continue;foreach(vwrap($row[0].': '.$row[1],94)as$part){vtext($ops,50,$y,$part,8,false,$navy);$y-=11;}}}
vline($ops,45,45,550,45,$line,.5);vtext($ops,45,31,'SV-Netzwerk Prüfportal',6.5,false,$muted);vright($ops,550,31,'Seite 1 von 1',6.5,false,$muted);
$stream=implode("\n",$ops);$objects=[1=>'<< /Type /Catalog /Pages 2 0 R >>',2=>'<< /Type /Pages /Kids [5 0 R] /Count 1 >>',3=>'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',4=>'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',5=>'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents 6 0 R >>',6=>'<< /Length '.strlen($stream).">>\nstream\n$stream\nendstream"];
$pdf="%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";$offsets=[0];foreach($objects as$n=>$object){$offsets[$n]=strlen($pdf);$pdf.="$n 0 obj\n$object\nendobj\n";}$xref=strlen($pdf);$pdf.="xref\n0 7\n0000000000 65535 f \n";for($i=1;$i<=6;$i++)$pdf.=sprintf('%010d 00000 n ',$offsets[$i])."\n";$pdf.="trailer\n<< /Size 7 /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
header_remove('Content-Type');header('Content-Type: application/pdf');header('Content-Disposition: inline; filename="Wertermittlung-1914.pdf"');header('Content-Length: '.strlen($pdf));echo$pdf;
