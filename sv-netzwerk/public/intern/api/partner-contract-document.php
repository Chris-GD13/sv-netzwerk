<?php
declare(strict_types=1);
require_once __DIR__.'/partner-contract-core.php';
require_once __DIR__.'/technical-release-pdf-core.php';

function pcBlocks(array $d): array {
    $v=fn($k)=>$d[$k]!==''?$d[$k]:'________________________________________';
    $blocks=[['Partnervereinbarung',['Rahmenvertrag über die Zusammenarbeit im SV-Netzwerk Bau · Schaden · Regulierung']],
        ['Vertragsparteien',[
            'Netzwerkbetreiber: '.$v('operator_name')."\n".$v('operator_address')."\nVertreten durch: ".$v('operator_representative')."\nRegister: ".$v('operator_register')."\nKontakt: ".$v('operator_email'),
            'Partner: '.$v('partner_name')."\n".$v('partner_address')."\nInhaber / Vertretung: ".$v('partner_representative')."\nRegister, soweit eingetragen: ".$v('partner_register')."\nE-Mail: ".$v('partner_email')."\nTelefon: ".$v('partner_phone'),
            'Fachgebiete: '.$v('fields')."\nEinsatzgebiet: ".$v('region')."\nQualifikationen / Zulassungen / Versicherungsschutz: ".$v('qualifications'),
        ]]];
    $blocks=array_merge($blocks,pcSections($d));
    $blocks[]=['Vertragsannahme',[
        'Ort: '.$v('place').' | Datum: '.($d['date']!==''?trDate($d['date']):'____________________'),
        'Für den Netzwerkbetreiber: ________________________________________'."\nName / Funktion: ________________________________________"."\n\nFür den Partner: ________________________________________\nName / Funktion: ________________________________________",
        'Die Unterschriften beziehungsweise eine dokumentierte übereinstimmende Annahme durch die vertretungsberechtigten Parteien sind gesondert einzuholen. Speichern oder Ausfüllen im Portal ist keine Vertragsannahme und löst keine Provision aus.'
    ]];
    $blocks[]=['Anlage 1 - Einzelauftrag und Provisionsbestätigung',[
        'Diese Anlage ist je konkretem Auftrag vor der provisionspflichtigen Weitergabe von beiden Parteien ausdrücklich zu bestätigen. Sie begründet ohne diese Bestätigung keinen Auftrag. Sie gehört als Muster zum Rahmenvertrag.',
        "Bezug Partner / Rahmenvertrag: ________________________________________\nAuftraggeber / eindeutige Auftragskennung: ________________________________________\nLeistungsumfang / Zeitraum: ________________________________________\nKundenvertrag wird geschlossen mit: ________________________________________\nProvisionssatz für diesen Auftrag: ________ %\nBerechnungsgrundlage: tatsächlich eingegangenes Nettohonorar der eigenen Fachleistung ohne Umsatzsteuer, gesonderte Reise-/Übernachtungskosten, Auslagen und durchlaufende Fremdkosten gemäß Ziffer 3.\nAbweichung, nur bei ausdrücklicher beiderseitiger Vereinbarung: ________________________________________",
        "Bestätigt für den Netzwerkbetreiber, Name / Datum: ________________________________________\nBestätigt für den Partner, Name / Datum: ________________________________________\nTextformbestätigung / zugehörige E-Mail: ________________________________________",
    ]];
    $blocks[]=['Anlage 2 - Gesonderte Freigabe des Expertenprofils',[
        'Eine Veröffentlichung erfolgt erst nach Prüfung und gesonderter Freigabe des konkreten Profilentwurfs und seiner Inhalte. Aktueller Wunsch: '.($d['profile_permission']==='yes'?'Ein Profilentwurf zur gesonderten Freigabe wird gewünscht.':'Derzeit keine Veröffentlichung gewünscht.'). ' Dieser Wunsch ersetzt keine Inhalts- oder Bildfreigabe.',
        "Freigegebener Profilentwurf / Datum / Version: ________________________________________\nFreigegebene berufliche Angaben und Kontaktwege: ________________________________________\nFreigegebene Texte / Bilder / Urheberangaben: ________________________________________",
        'Für das bezeichnete Porträt gestatte ich die Veröffentlichung in meinem Expertenprofil auf sv-netzwerk.eu. Die Einwilligung ist freiwillig und jederzeit für die Zukunft über die Kontaktadresse des Netzwerkbetreibers widerrufbar. Die Rechtmäßigkeit der bisherigen Nutzung bleibt unberührt. Die Netzwerkaufnahme ist nicht von einer Porträtfreigabe abhängig.',
        "Freigabe der bezeichneten Profilinhalte, Name / Datum: ________________________________________\nGesonderte Porträteinwilligung, Name / Datum: ________________________________________",
    ]];
    return $blocks;
}
function pcHtml(array $d,int $revision=0): string {
    $e=fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    $html='<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="robots" content="noindex,nofollow"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Partnervereinbarung - SV-Netzwerk</title><link rel="stylesheet" href="/intern/partner-contract.css"></head><body class="contract-document"><header><img src="/intern/assets/private-business/sv-netzwerk-eu-logo.jpg" alt="SV-Netzwerk – SV in Orange"><p>Partnervereinbarung · Vorlage '.PC_VERSION.' · Revision '.$revision.'</p></header>';
    if($missing=pcMissing($d))$html.='<aside>ENTWURF – noch auszufüllen: '.$e(implode(', ',$missing)).'</aside>';
    foreach(pcBlocks($d) as [$title,$paragraphs]) {$html.='<section><h2>'.$e($title).'</h2>';foreach($paragraphs as $p)$html.='<p>'.nl2br($e($p)).'</p>';$html.='</section>';}
    return $html.'<footer>www.sv-netzwerk.eu · Partnervereinbarung · '.PC_VERSION.'</footer></body></html>';
}
function pcPdf(array $d,int $revision=0): string {
    $pages=[];$ops=[];$y=0;
    $start=function() use (&$pages,&$ops,&$y,$revision) {
        if($ops)$pages[]=$ops;$ops=[];
        $ops[]='q 260 0 0 46 44 767 cm /Logo Do Q';
        trRight($ops,551,789,'PARTNERVEREINBARUNG',9,true,[.05,.13,.2]);
        trRight($ops,551,776,'Vorlage '.PC_VERSION.' | R'.$revision,7,false,[.35,.42,.49]);
        trLine($ops,44,752,551,752,[1,.61,.05],2);$y=732;
    };
    $start();
    $paragraph=function(string $text,bool $bold=false) use (&$ops,&$y,$start) {
        foreach(explode("\n",$text) as $part) {
            if(trim($part)===''){$y-=12;continue;}
            // Character and measured-width bound: long words are split as well.
            $part=preg_replace_callback('/\S{61,}/u',fn($m)=>implode(' ',mb_str_split($m[0],60)),$part)??$part;
            foreach(trWrap($part,89) as $line) {
                if($y<74)$start();trText($ops,44,$y,$line,10,$bold,[.05,.13,.2]);$y-=14;
            }$y-=3;
        }$y-=7;
    };
    if($missing=pcMissing($d))$paragraph('ENTWURF - Noch auszufüllen: '.implode(', ',$missing),true);
    foreach(pcBlocks($d) as [$title,$paragraphs]) {
        if(str_starts_with($title,'Anlage ')||($title==='Vertragsannahme'&&$y<330))$start();
        if($y<135)$start();trText($ops,44,$y,$title,12,true,[.05,.13,.2]);$y-=23;
        foreach($paragraphs as $p)$paragraph($p);
    }
    if($ops)$pages[]=$ops;$count=count($pages);
    foreach($pages as $i=>&$page){trLine($page,44,49,551,49,[.8,.84,.87]);trText($page,44,34,'SV-Netzwerk | Partnervereinbarung | '.PC_VERSION,7,false,[.35,.42,.49]);trRight($page,551,34,'Seite '.($i+1).' von '.$count,7,false,[.35,.42,.49]);}unset($page);
    $logo=file_get_contents(dirname(__DIR__).'/assets/private-business/sv-netzwerk-eu-logo.jpg');$size=getimagesizefromstring($logo);
    $objects=[1=>'<< /Type /Catalog /Pages 2 0 R >>',3=>'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',4=>'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>',5=>'<< /Type /XObject /Subtype /Image /Width '.$size[0].' /Height '.$size[1].' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($logo).">>\nstream\n".$logo."\nendstream"];
    $kids=[];for($i=0;$i<$count;$i++)$kids[]=(6+$i).' 0 R';$objects[2]='<< /Type /Pages /Kids ['.implode(' ',$kids).'] /Count '.$count.' >>';
    foreach($pages as $i=>$page){$content=6+$count+$i;$objects[6+$i]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> /XObject << /Logo 5 0 R >> >> /Contents '.$content.' 0 R >>';$stream=implode("\n",$page);$objects[$content]='<< /Length '.strlen($stream).">>\nstream\n".$stream."\nendstream";}
    ksort($objects);$pdf="%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";$offsets=[0];foreach($objects as $number=>$obj){$offsets[$number]=strlen($pdf);$pdf.="$number 0 obj\n$obj\nendobj\n";}$xref=strlen($pdf);$max=max(array_keys($objects));$pdf.="xref\n0 ".($max+1)."\n0000000000 65535 f \n";for($i=1;$i<=$max;$i++)$pdf.=sprintf('%010d 00000 n ',$offsets[$i])."\n";return $pdf."trailer\n<< /Size ".($max+1)." /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
}
