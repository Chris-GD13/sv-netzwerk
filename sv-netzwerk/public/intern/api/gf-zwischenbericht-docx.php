<?php
declare(strict_types=1);

/** Fill the Engel original's value rows ABOVE their labels. Preserve the package. */
function gfZwischenberichtDocx(string $bytes, array $meta, array $result, string $userName): string {
    $tmp=tempnam(sys_get_temp_dir(),'engel-zb-');
    if ($tmp===false) throw new RuntimeException('Word-Zwischendatei nicht verfügbar.');
    try {
        file_put_contents($tmp,$bytes); $zip=new ZipArchive();
        if ($zip->open($tmp)!==true) throw new RuntimeException('Original-Zwischenbericht ist kein DOCX.');
        $dom=new DOMDocument(); $dom->preserveWhiteSpace=true;
        if (!$dom->loadXML((string)$zip->getFromName('word/document.xml'),LIBXML_NONET)) throw new RuntimeException('Original-Word-Struktur unlesbar.');
        $ns='http://schemas.openxmlformats.org/wordprocessingml/2006/main';
        $xp=new DOMXPath($dom);$xp->registerNamespace('w',$ns);
        $paragraphs=$xp->query('/w:document/w:body/w:p');$tables=$xp->query('/w:document/w:body/w:tbl');
        if ($tables->length!==3 || $paragraphs->length<25
            || !str_contains($tables->item(0)->textContent,'Regulierer')
            || !str_contains($tables->item(2)->textContent,'Mietausfall')) {
            throw new RuntimeException('Engel-Originalvorlage hat eine andere Struktur. Keine Befüllung mit geratenen Feldpositionen.');
        }
        $set=function(DOMNode $p,string $text)use($dom,$xp,$ns):void {
            $style=($xp->query('./w:r/w:rPr',$p)->item(0)??$xp->query('./w:pPr/w:rPr',$p)->item(0))?->cloneNode(true);
            foreach(iterator_to_array($p->childNodes) as $child)if($child->localName!=='pPr')$p->removeChild($child);
            foreach(explode("\n",$text) as $i=>$line){
                $r=$dom->createElementNS($ns,'w:r');if($style)$r->appendChild($style->cloneNode(true));
                if($i)$r->appendChild($dom->createElementNS($ns,'w:br'));
                $t=$dom->createElementNS($ns,'w:t');$t->setAttribute('xml:space','preserve');$t->appendChild($dom->createTextNode($line));$r->appendChild($t);$p->appendChild($r);
            }
        };
        $cell=function(int $table,int $row,int $col,string $text)use($tables,$xp,$set):void {
            $node=$xp->query('./w:tr['.($row+1).']/w:tc['.($col+1).']',$tables->item($table))->item(0);
            if(!$node)throw new RuntimeException('Originalformularfeld fehlt.');
            $ps=$xp->query('./w:p',$node);$set($ps->item(0),$text);
            for($i=$ps->length-1;$i>0;$i--)$node->removeChild($ps->item($i));
        };
        $form=$result['form_fields']??[];
        $number=trim((string)($form['bericht_nummer']??''));
        if(!preg_match('/^[1-9][0-9]{0,2}$/',$number))throw new RuntimeException('Zwischenberichtnummer nicht eindeutig belegt. Bitte Nummer im Auftrag angeben.');
        $address=trim((string)($form['vn_anschrift']??''));
        $risk=trim((string)($form['schadenort']??''));
        if($risk==='')$risk=trim(implode(' ',array_filter([$meta['strasse']??'',$meta['plz']??'',$meta['ort']??''])));
        foreach([
            [0,0,$userName],[0,1,date('d.m.Y')],[2,0,(string)($meta['schaden_nr']??'')],
            [2,1,(string)($form['schadentag']??$meta['schadentag']??$meta['schadendatum']??'')],
            [2,2,(string)($form['sparte_gefahr']??$meta['sparte']??$meta['gefahr']??'')],
            [4,0,(string)($form['vertrags_nr']??$meta['versicherungsschein_nr']??$meta['vertrags_nr']??'')],
            [4,1,(string)($form['betreuer']??'')],
            [6,0,trim((string)($meta['vn_objekt']??$meta['versicherungsnehmer']??'').($address!==''?"\n".$address:''))],
            [8,0,$risk]
        ] as [$row,$col,$value])$cell(0,$row,$col,$value);
        $cell(1,1,0,(string)($form['letzte_besprechung_am']??''));
        $cell(1,1,1,(string)($form['teilnehmer_1']??$form['teilnehmer']??''));
        $cell(1,1,2,(string)($form['teilnehmer_2']??''));
        foreach(['gebaeude','hausrat','mietausfall'] as $i=>$prefix)foreach(['bislang','befuerwortet','gesamt'] as $j=>$suffix)$cell(2,$i+1,$j+1,(string)($form[$prefix.'_'.$suffix]??''));
        $slots=[6,9,15,18,21];
        if(count($result['sections']??[])!==5)throw new RuntimeException('Zwischenberichtabschnitte unvollständig.');
        foreach($slots as $i=>$slot)$set($paragraphs->item($slot),trim((string)$result['sections'][$i]['text']));
        foreach([7,12,16] as $slot)$set($paragraphs->item($slot),'');
        $signature=$userName."\nRegulierer und Bausachverständiger\nSV-Netzwerk";
        if(str_contains(mb_strtolower($userName),'christian'))$signature.="\nDIN EN ISO/IEC 17024 zertifiziert\ncw@sv-netzwerk.eu\nhttps://www.sv-netzwerk.eu/";
        $set($paragraphs->item(24),$signature);
        $signaturePr=$xp->query('./w:pPr',$paragraphs->item(24))->item(0);
        if(!$signaturePr){$signaturePr=$dom->createElementNS($ns,'w:pPr');$paragraphs->item(24)->insertBefore($signaturePr,$paragraphs->item(24)->firstChild);}
        $signaturePr->appendChild($dom->createElementNS($ns,'w:keepLines'));
        foreach([5,8,14,17,20,23] as $slot){$p=$paragraphs->item($slot);$pr=$xp->query('./w:pPr',$p)->item(0);if(!$pr){$pr=$dom->createElementNS($ns,'w:pPr');$p->insertBefore($pr,$p->firstChild);}if(!$xp->query('./w:keepNext',$pr)->length)$pr->appendChild($dom->createElementNS($ns,'w:keepNext'));}
        foreach($xp->query('//w:tr') as $row){$pr=$xp->query('./w:trPr',$row)->item(0);if(!$pr){$pr=$dom->createElementNS($ns,'w:trPr');$row->insertBefore($pr,$row->firstChild);}if(!$xp->query('./w:cantSplit',$pr)->length)$pr->appendChild($dom->createElementNS($ns,'w:cantSplit'));}
        $zip->addFromString('word/document.xml',$dom->saveXML());
        $header=new DOMDocument();
        if(!$header->loadXML((string)$zip->getFromName('word/header1.xml'),LIBXML_NONET))throw new RuntimeException('Original-Berichtskopf fehlt.');
        $hx=new DOMXPath($header);$hx->registerNamespace('w',$ns);$field=$hx->query('//w:r[w:rPr/w:bdr]/w:t')->item(0);
        if(!$field)throw new RuntimeException('Originalfeld für Zwischenberichtnummer fehlt.');
        $field->nodeValue=$number;$zip->addFromString('word/header1.xml',$header->saveXML());
        $zip->close();return (string)file_get_contents($tmp);
    } finally {if(isset($zip)&&$zip->status===ZipArchive::ER_OK){try{$zip->close();}catch(Throwable){}}@unlink($tmp);}
}
