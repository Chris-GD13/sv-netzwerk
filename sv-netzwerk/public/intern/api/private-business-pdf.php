<?php
declare(strict_types=1);
require_once __DIR__.'/technical-release-pdf-core.php';
require_once __DIR__.'/private-business-core.php';

function pbHeader(array &$ops,string $title,array $r): float {
    $navy=[.06,.20,.30];$ops[]='q 104 0 0 69.64 44 757 cm /LogoSchuett Do Q';$ops[]='q 147 0 0 26 404 796 cm /LogoNetzwerk Do Q';trText($ops,166,811,'Sachverständigenbüro Marc Schütt e.K.',10,true,$navy);
    trText($ops,166,793,str_contains($title,'Widerruf')?'Nordstraße 17 · 73432 Aalen':'Feldstraße 108 · 58791 Werdohl',8,false,$navy);
    trText($ops,166,779,str_contains($title,'Widerruf')?'Niederlassung Süd · Widerruf und Korrespondenz':'Niederlassung Süd: Nordstraße 17 · 73432 Aalen',8,false,$navy);
    trText($ops,166,762,'07367 3939783 · ws@sv-schuett.eu',8,false,$navy);
    trLine($ops,44,740,551,740,[.35,.46,.52],1);trText($ops,44,716,$title,16,true,$navy);trRight($ops,551,716,$r['number'],12,true,$navy);
    trText($ops,44,697,'Stand '.(new DateTimeImmutable($r['updated_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('d.m.Y').' · Version '.$r['revision'],8,false,[.4,.45,.5]);
    if(pbMissing($r['data'])){trText($ops,44,681,'ENTWURF - Angaben noch unvollständig',9,true,[.65,.25,.08]);return 658;}return 675;
}
function pbParagraph(array &$pages,array &$ops,float &$y,string $text,string $title,array $r,bool $bold=false): void {
    $height=0;foreach(explode("\n",$text)as$part)$height+=count(trWrap($part,94))*13+5;if($height<560&&$y-$height<70){$pages[]=$ops;$ops=[];$y=pbHeader($ops,$title,$r);}
    foreach(explode("\n",$text)as$part){foreach(trWrap($part,94)as$line){if($y<74){$pages[]=$ops;$ops=[];$y=pbHeader($ops,$title,$r);}trText($ops,44,$y,$line,9,$bold,[.08,.16,.22]);$y-=13;}$y-=5;}
}
function pbSection(array &$pages,array &$ops,float &$y,string $name,string $title,array $r): void {if($y<118){$pages[]=$ops;$ops=[];$y=pbHeader($ops,$title,$r);} $y-=6;trText($ops,44,$y,$name,11,true,[.13,.30,.40]);$y-=19;}
function pbAddressText(array $a): string {return trim($a['street'].', '.$a['postcode'].' '.$a['city'],', ');}
function pbClientBlock(array &$pages,array &$ops,float &$y,string $title,array $r): void {$d=$r['data'];$left=[pbCustomer($d)?:'Noch nicht angegeben',$d['billing_street'],trim($d['billing_postcode'].' '.$d['billing_city']),($d['phone']??'')!==''?'Telefon: '.$d['phone']:'',($d['mobile']??'')!==''?'Mobil: '.$d['mobile']:'',($d['other_phone']??'')!==''?'Sonstige Nummer: '.$d['other_phone']:'',$d['email'],$d['additional_email']??''];$site=pbSite($d);$expert=match($d['expert']??''){'christian'=>'Christian Wächter','marc'=>'Marc Schütt',default=>''};$right=[$site['street'],trim($site['postcode'].' '.$site['city']),$d['contact']!==''?'Kontakt: '.$d['contact']:'',$d['appointment']!==''?'Termin: '.$d['appointment']:'',$expert!==''?'Sachverständiger: '.$expert:''];$columns=[];foreach([$left,$right]as$col){$lines=[];foreach($col as$value)if($value!=='')$lines=array_merge($lines,trWrap($value,43));$columns[]=$lines;}$height=max(count($columns[0]),count($columns[1]))*13+45;trFill($ops,44,$y-$height,507,$height,[.96,.975,.98]);foreach($columns as$i=>$lines){$x=$i===0?55:313;trText($ops,$x,$y-17,$i===0?'Auftraggeber / Rechnung':'Ortstermin / Objekt',9,true,[.13,.30,.40]);foreach($lines as$j=>$line)trText($ops,$x,$y-37-$j*13,$line,8.8,false,[.08,.16,.22]);}$y-=$height+16;}
function pbPriceBlock(array &$pages,array &$ops,float &$y,string $title,array $r): void {
    $d=$r['data'];$t=pbTotals($d);$rows=[];$tableHeight=26+57;
    foreach($t['lines']as$line){$line['wrapped']=trWrap($line['label'],36);$line['height']=max(22,count($line['wrapped'])*11+8);$rows[]=$line;$tableHeight+=$line['height'];}
    if($y-$tableHeight-30<80){$pages[]=$ops;$ops=[];$y=pbHeader($ops,$title,$r);}
    pbSection($pages,$ops,$y,$d['pricing']==='flat'?'Vereinbarte Pauschale':'Kostenkalkulation nach Aufwand',$title,$r);
    $top=$y+6;$bottom=$top-$tableHeight;$navy=[.06,.20,.30];$border=[.78,.83,.86];
    trFill($ops,44,$top-26,507,26,$navy);
    trText($ops,52,$top-17,'Leistung',8.5,true,[1,1,1]);
    trRight($ops,316,$top-17,'Menge',8.5,true,[1,1,1]);
    trText($ops,328,$top-17,'Einheit',8.5,true,[1,1,1]);
    trRight($ops,451,$top-12,'Einzelpreis',8.5,true,[1,1,1]);trRight($ops,451,$top-22,'netto (EUR)',7,false,[1,1,1]);
    trRight($ops,543,$top-12,'Gesamtpreis',8.5,true,[1,1,1]);trRight($ops,543,$top-22,'netto (EUR)',7,false,[1,1,1]);
    $rowTop=$top-26;
    foreach($rows as$i=>$line){$h=$line['height'];trFill($ops,44,$rowTop-$h,507,$h,$i%2===0?[.96,.975,.98]:[1,1,1]);
        foreach($line['wrapped']as$j=>$text)trText($ops,52,$rowTop-16-$j*11,$text,8.5,false,[.08,.16,.22]);
        trRight($ops,316,$rowTop-16,trNumber(round($line['quantity'],3)),8.5);trText($ops,328,$rowTop-16,$line['unit'],8.2);
        trRight($ops,451,$rowTop-16,number_format($line['rate'],2,',','.'),8.5);trRight($ops,543,$rowTop-16,number_format($line['net'],2,',','.'),8.5);
        $rowTop-=$h;trLine($ops,44,$rowTop,551,$rowTop,$border,.5);
    }
    foreach([44,275,322,374,459,551]as$x)trLine($ops,$x,$top-26,$x,$rowTop,$border,.5);
    foreach([['Summe netto',$t['net']],['Umsatzsteuer '.trNumber($d['vat_rate']).' %',$t['vat']],['Gesamt brutto',$t['gross']]]as$i=>$sum){
        $color=$i===2?[1,1,1]:[.08,.16,.22];trFill($ops,275,$rowTop-19,276,19,$i===2?$navy:[.94,.96,.97]);
        trText($ops,283,$rowTop-13,$sum[0],9,true,$color);trRight($ops,543,$rowTop-13,trMoney($sum[1]),9,true,$color);$rowTop-=19;
    }
    $y=$bottom-12;
    if($d['pricing']==='flat')pbParagraph($pages,$ops,$y,'Die Einzelansätze dienen der Kalkulation. Maßgeblich ist die angebotene Gesamtpauschale von '.trMoney($t['net']).' netto.',$title,$r,true);
    if(str_contains($title,'Auftragsformular')){pbParagraph($pages,$ops,$y,'Anfahrt ab '.pbAddressText(pbOrigin($d)).'; '.$d['visits'].' Termin(e), jeweils Hin- und Rückweg. Bei Aufwandsabrechnung gelten die dokumentierten tatsächlichen Zeiten und Mengen.',$title,$r);return;}
    pbParagraph($pages,$ops,$y,'Start: '.pbAddressText(pbOrigin($d)).' | Ziel: '.pbAddressText(pbSite($d))."\n".$d['visits'].' Termin(e); einfache Strecke '.trNumber($d['oneway_km']).' km / '.trNumber($d['oneway_minutes']).' Minuten. Berechnet werden Hin- und Rückweg: '.trNumber($t['km_total']).' km und '.trNumber(round($t['travel_hours'],3)).' Stunden.', $title,$r);
    if($d['pricing']==='effort')pbParagraph($pages,$ops,$y,'Der Gesamtbetrag ist eine Kostenschätzung für den beschriebenen Leistungsumfang. Abgerechnet wird der tatsächlich dokumentierte Aufwand zu den vereinbarten Sätzen. Erhebliche absehbare Überschreitungen werden vor Fortsetzung mit dem Auftraggeber abgestimmt.',$title,$r);
}
function pbDocumentPages(array $r,string $kind): array {
    $d=$r['data'];$isOrder=$r['kind']==='order';$title=match($kind){'main'=>$isOrder?'Auftrag für Sachverständigenleistungen':'Angebot für Sachverständigenleistungen','contract'=>$isOrder?'Auftragsformular':'Auftragsformular zum Angebot',default=>'Widerrufsbelehrung'};
    $pages=[];$ops=[];$y=pbHeader($ops,$title,$r);
    if($kind!=='withdrawal'){
        if(!empty($d['offer_number']))pbParagraph($pages,$ops,$y,'Zugehöriges Angebot: '.$d['offer_number'],$title,$r);
        pbClientBlock($pages,$ops,$y,$title,$r);pbSection($pages,$ops,$y,'Leistungsgegenstand',$title,$r);pbParagraph($pages,$ops,$y,$d['scope']?:'Leistungsbeschreibung wird ergänzt.',$title,$r);pbPriceBlock($pages,$ops,$y,$title,$r);
        if($kind==='main'){
            if($d['valid_until']!=='')pbParagraph($pages,$ops,$y,'Angebot gültig bis: '.$d['valid_until'],$title,$r);
            if($d['notes']!=='')pbParagraph($pages,$ops,$y,$d['notes'],$title,$r);
            pbParagraph($pages,$ops,$y,'Zur Beauftragung senden Sie bitte das unterschriebene Auftragsformular zurück. Eine etwa gewünschte Tätigkeit innerhalb der Widerrufsfrist ist gesondert auf der Widerrufsbelehrung zu erklären.',$title,$r);
        }else{
            pbSection($pages,$ops,$y,'Vertragsbedingungen',$title,$r);
            pbParagraph($pages,$ops,$y,'Beauftragt wird ausschließlich der vorstehend beschriebene Leistungsumfang. Ein bestimmtes Ergebnis eines Rechtsstreits, eine Versicherungsleistung oder eine behördliche Entscheidung wird nicht zugesagt. Für ein geschuldetes schriftliches Gutachten gelten die gesetzlichen werkvertraglichen Regelungen; für reine Beratungsleistungen die gesetzlichen Regelungen des jeweiligen Vertragstyps.',$title,$r);
            pbParagraph($pages,$ops,$y,'Es gelten die gesetzlichen Haftungsregelungen. Besondere Auslagen, insbesondere Flug-, Übernachtungs- und Fremdleistungskosten, werden nur nach vorheriger Vereinbarung berechnet. Fälligkeit und gegebenenfalls Abnahme richten sich nach den gesetzlichen Voraussetzungen. Eine etwaige Forderungsabtretung und der Zahlungsempfänger werden gesondert mitgeteilt.',$title,$r);
            pbParagraph($pages,$ops,$y,'[  ] Ich ermächtige den Sachverständigen, die erforderlichen fachlichen Abstimmungen mit der benannten Gegenseite vorzunehmen und diese nach Abstimmung zum Ortstermin einzuladen.',$title,$r);
            pbParagraph($pages,$ops,$y,'Ich beauftrage das Sachverständigenbüro Marc Schütt e.K. zu dem beschriebenen Umfang und den aufgeführten Konditionen. Die Widerrufsbelehrung und das Muster-Widerrufsformular wurden mir zur Verfügung gestellt.',$title,$r,true);
            pbParagraph($pages,$ops,$y,"Ort / Datum: ____________________________________\nUnterschrift Auftraggeber: __________________________",$title,$r);
        }
    }else{
        pbParagraph($pages,$ops,$y,'Zum '.($isOrder?'Auftrag ':'Angebot ').$r['number'].' · Auftraggeber: '.(pbCustomer($d)?:'________________________'),$title,$r);
        pbParagraph($pages,$ops,$y,'Die nachfolgende Belehrung gilt für Verbraucher bei Fernabsatzverträgen und außerhalb von Geschäftsräumen geschlossenen Verträgen.',$title,$r);
        pbSection($pages,$ops,$y,'Widerrufsrecht',$title,$r);
        pbParagraph($pages,$ops,$y,'Sie haben das Recht, binnen vierzehn Tagen ohne Angabe von Gründen diesen Vertrag zu widerrufen. Die Widerrufsfrist beträgt vierzehn Tage ab dem Tag des Vertragsabschlusses.',$title,$r);
        pbParagraph($pages,$ops,$y,'Um Ihr Widerrufsrecht auszuüben, müssen Sie uns (Sachverständigenbüro Marc Schütt e.K., Nordstraße 17, 73432 Aalen; Telefon 07367 3939783; E-Mail ws@sv-schuett.eu) mittels einer eindeutigen Erklärung (z. B. ein mit der Post versandter Brief oder eine E-Mail) über Ihren Entschluss, diesen Vertrag zu widerrufen, informieren. Sie können dafür das beigefügte Muster-Widerrufsformular verwenden, das jedoch nicht vorgeschrieben ist.',$title,$r);
        pbParagraph($pages,$ops,$y,'Zur Wahrung der Widerrufsfrist reicht es aus, dass Sie die Mitteilung über die Ausübung des Widerrufsrechts vor Ablauf der Widerrufsfrist absenden.',$title,$r);
        pbSection($pages,$ops,$y,'Folgen des Widerrufs',$title,$r);
        pbParagraph($pages,$ops,$y,'Wenn Sie diesen Vertrag widerrufen, haben wir Ihnen alle Zahlungen, die wir von Ihnen erhalten haben, einschließlich der Lieferkosten (mit Ausnahme der zusätzlichen Kosten, die sich daraus ergeben, dass Sie eine andere Art der Lieferung als die von uns angebotene, günstigste Standardlieferung gewählt haben), unverzüglich und spätestens binnen vierzehn Tagen ab dem Tag zurückzuzahlen, an dem die Mitteilung über Ihren Widerruf dieses Vertrags bei uns eingegangen ist. Für diese Rückzahlung verwenden wir dasselbe Zahlungsmittel, das Sie bei der ursprünglichen Transaktion eingesetzt haben, es sei denn, mit Ihnen wurde ausdrücklich etwas anderes vereinbart; in keinem Fall werden Ihnen wegen dieser Rückzahlung Entgelte berechnet.',$title,$r);
        pbParagraph($pages,$ops,$y,'Haben Sie verlangt, dass die Dienstleistungen während der Widerrufsfrist beginnen sollen, so haben Sie uns einen angemessenen Betrag zu zahlen, der dem Anteil der bis zu dem Zeitpunkt, zu dem Sie uns von der Ausübung des Widerrufsrechts hinsichtlich dieses Vertrags unterrichten, bereits erbrachten Dienstleistungen im Vergleich zum Gesamtumfang der im Vertrag vorgesehenen Dienstleistungen entspricht.',$title,$r);
        $pages[]=$ops;$ops=[];$y=pbHeader($ops,'Erklärungen und Widerrufsformular',$r);
        pbSection($pages,$ops,$y,'Nur bei gewünschtem vorzeitigem Leistungsbeginn',$title,$r);
        pbParagraph($pages,$ops,$y,'Die folgenden Erklärungen sind freiwillig und vom Verbraucher selbst abzugeben. Ohne diese Erklärungen wird vor Ablauf der Widerrufsfrist nicht mit der Leistung begonnen.',$title,$r);
        pbParagraph($pages,$ops,$y,'[  ] Ich verlange ausdrücklich und stimme zu, dass vor Ablauf der Widerrufsfrist mit der beauftragten Sachverständigenleistung begonnen wird.',$title,$r);
        pbParagraph($pages,$ops,$y,'[  ] Ich bestätige meine Kenntnis, dass mein Widerrufsrecht bei vollständiger Vertragserfüllung durch den Unternehmer erlischt. Über den anteiligen Wertersatz im Fall eines vorherigen Widerrufs wurde ich informiert.',$title,$r);
        pbParagraph($pages,$ops,$y,"Ort / Datum: ____________________________________\nUnterschrift Verbraucher: ___________________________",$title,$r);
        pbSection($pages,$ops,$y,'Muster-Widerrufsformular',$title,$r);
        pbParagraph($pages,$ops,$y,'Wenn Sie den Vertrag widerrufen wollen, füllen Sie bitte dieses Formular aus und senden Sie es zurück.',$title,$r);
        pbParagraph($pages,$ops,$y,"An: Sachverständigenbüro Marc Schütt e.K.\nNordstraße 17, 73432 Aalen\nE-Mail: ws@sv-schuett.eu",$title,$r);
        pbParagraph($pages,$ops,$y,'Hiermit widerrufe(n) ich/wir (*) den von mir/uns (*) abgeschlossenen Vertrag über die Erbringung der folgenden Dienstleistung:',$title,$r);
        pbParagraph($pages,$ops,$y,"_________________________________________________________\nVertrags-/Angebotsnummer: ".$r['number']."\nBestellt am: _____________________________________________\nName des/der Verbraucher(s): ______________________________\nAnschrift des/der Verbraucher(s): ___________________________\n_________________________________________________________\nDatum: __________________________________________________\nUnterschrift (nur bei Mitteilung auf Papier): ___________________\n(*) Unzutreffendes streichen.",$title,$r);
    }
    if($ops)$pages[]=$ops;return $pages;
}
function pbPdf(array $pages): string {
    $count=count($pages);foreach($pages as$i=>&$p){trLine($p,44,48,551,48,[.75,.8,.84],.5);trText($p,44,34,'HRA 5477 · Amtsgericht Iserlohn · USt-IdNr. DE 320170737',6.8,false,[.4,.45,.5]);trRight($p,551,34,'Seite '.($i+1).' von '.$count,7,false,[.4,.45,.5]);}unset($p);
    $objects=[1=>'<< /Type /Catalog /Pages 2 0 R >>',3=>'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',4=>'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>'];$start=5;$contents=$start+$count;$logoStart=$contents+$count;$logos=[];foreach(['LogoSchuett'=>'schuett-logo.jpg','LogoNetzwerk'=>'sv-netzwerk-eu-logo.jpg']as$key=>$filename){$path=dirname(__DIR__).'/assets/private-business/'.$filename;$bytes=file_get_contents($path);$size=getimagesize($path);$n=$logoStart+count($logos);$objects[$n]='<< /Type /XObject /Subtype /Image /Width '.$size[0].' /Height '.$size[1].' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($bytes).">>\nstream\n".$bytes."\nendstream";$logos[]='/'.$key.' '.$n.' 0 R';}$kids=[];for($i=0;$i<$count;$i++)$kids[]=($start+$i).' 0 R';$objects[2]='<< /Type /Pages /Kids ['.implode(' ',$kids).'] /Count '.$count.' >>';foreach($pages as$i=>$p){$objects[$start+$i]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> /XObject << '.implode(' ',$logos).' >> >> /Contents '.($contents+$i).' 0 R >>';$stream=implode("\n",$p);$objects[$contents+$i]='<< /Length '.strlen($stream).">>\nstream\n".$stream."\nendstream";}ksort($objects);$pdf="%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";$offsets=[0];foreach($objects as$n=>$o){$offsets[$n]=strlen($pdf);$pdf.="$n 0 obj\n$o\nendobj\n";}$xref=strlen($pdf);$max=max(array_keys($objects));$pdf.="xref\n0 ".($max+1)."\n0000000000 65535 f \n";for($i=1;$i<=$max;$i++)$pdf.=sprintf('%010d 00000 n ',$offsets[$i])."\n";return$pdf."trailer\n<< /Size ".($max+1)." /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
}
function pbDocuments(array $r): array {$pages=[];$files=[];foreach(['main','contract','withdrawal']as$kind){$pages[$kind]=pbDocumentPages($r,$kind);$files[$kind]=pbPdf($pages[$kind]);}$files['packet']=pbPdf(array_merge(...array_values($pages)));return$files;}
