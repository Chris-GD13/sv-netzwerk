<?php
declare(strict_types=1);
/** Read every worksheet cell locally; never execute macros or accept API row truncation. */
function svAssistantSpreadsheetText(string $bytes): string {
    if(!class_exists('ZipArchive'))throw new RuntimeException('SPREADSHEET_READER_UNAVAILABLE');
    $path=tempnam(sys_get_temp_dir(),'sv-sheet-');$zip=new ZipArchive();$opened=false;
    try{
        if(file_put_contents($path,$bytes)!==strlen($bytes)||$zip->open($path)!==true)throw new RuntimeException('SPREADSHEET_INVALID');
        $opened=true;
        $text='Alle Tabellenzellen als Rohwerte. Formeln mit gespeichertem Ergebnis; keine Neuberechnung oder makrobasierte Werte. Zahlen-/Datumsformat fachlich prüfen.\n';$shared=[];$total=0;
        $read=function(string $name)use($zip,&$total):SimpleXMLElement{
            $stat=$zip->statName($name);if(!$stat||$stat['size']>8*1024*1024||($total+=$stat['size'])>20*1024*1024)throw new RuntimeException('SPREADSHEET_TOO_LARGE');
            $xml=simplexml_load_string($zip->getFromName($name),SimpleXMLElement::class,LIBXML_NONET);if(!$xml)throw new RuntimeException('SPREADSHEET_INVALID');
            $xml->registerXPathNamespace('s','http://schemas.openxmlformats.org/spreadsheetml/2006/main');return $xml;
        };
        if($zip->locateName('xl/sharedStrings.xml')!==false){$xml=$read('xl/sharedStrings.xml');foreach($xml->xpath('//s:si') as $item){$item->registerXPathNamespace('s','http://schemas.openxmlformats.org/spreadsheetml/2006/main');$shared[]=implode('',array_map('strval',$item->xpath('.//s:t')));}}
        $sheets=0;
        for($i=0;$i<$zip->numFiles;$i++){
            $name=$zip->getNameIndex($i);if(!preg_match('~^xl/worksheets/[^/]+\.xml$~',$name))continue;$sheets++;
            $xml=$read($name);$text.="\nTabelle: ".$name."\n";
            foreach($xml->xpath('//s:row') as $row){$values=[];foreach($row->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main')->c as $cell){$c=$cell->children('http://schemas.openxmlformats.org/spreadsheetml/2006/main');$value=(string)$c->v;$type=(string)$cell->attributes()['t'];if($type==='s')$value=$shared[(int)$value]??throw new RuntimeException('SPREADSHEET_INVALID');elseif($type==='inlineStr'){$cell->registerXPathNamespace('s','http://schemas.openxmlformats.org/spreadsheetml/2006/main');$value=implode('',array_map('strval',$cell->xpath('.//s:t')));}$values[]=(string)$cell->attributes()['r'].': '.$value.((string)$c->f!==''?' [Formel: '.(string)$c->f.']':'');}$text.=implode(' | ',$values)."\n";if(strlen($text)>700000)throw new RuntimeException('SPREADSHEET_TOO_LARGE');}
        }
        if(!$sheets)throw new RuntimeException('SPREADSHEET_INVALID');return $text;
    }finally{if($opened)$zip->close();if(is_file($path))unlink($path);}
}
