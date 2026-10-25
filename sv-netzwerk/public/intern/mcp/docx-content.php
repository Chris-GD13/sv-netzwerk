<?php
declare(strict_types=1);
function mcpDocxContent(string $bytes): array {
    if(strlen($bytes)>15*1024*1024||!class_exists('ZipArchive'))throw new RuntimeException('DOCX_READER_UNAVAILABLE_OR_LIMIT');
    $tmp=tempnam(sys_get_temp_dir(),'mcp-docx-');
    if(!$tmp)throw new RuntimeException('DOCX_TEMP_FAILED');
    $zip=new ZipArchive();$opened=false;
    try{
        if(file_put_contents($tmp,$bytes)!==strlen($bytes)||$zip->open($tmp)!==true)throw new RuntimeException('DOCX_ZIP_INVALID');$opened=true;
        if($zip->locateName('word/document.xml')===false)throw new RuntimeException('DOCX_MAIN_DOCUMENT_MISSING');
        if($zip->numFiles>5000)throw new RuntimeException('DOCX_ENTRY_LIMIT');
        $parts=[];$total=0;$images=0;
        for($i=0;$i<$zip->numFiles;$i++){
            $stat=$zip->statIndex($i);$name=(string)$stat['name'];
            if(str_starts_with($name,'word/media/'))$images++;
            if(!preg_match('~^word/(document|header\d+|footer\d+|footnotes|endnotes)\.xml$~D',$name))continue;
            $total+=(int)$stat['size'];if($total>8*1024*1024)throw new RuntimeException('DOCX_XML_LIMIT');
            $xml=$zip->getFromIndex($i);if($xml===false||stripos($xml,'<!DOCTYPE')!==false||stripos($xml,'<!ENTITY')!==false)throw new RuntimeException('DOCX_XML_UNSAFE');
            $doc=new DOMDocument();$previous=libxml_use_internal_errors(true);
            try{$valid=$doc->loadXML($xml,LIBXML_NONET);}finally{libxml_clear_errors();libxml_use_internal_errors($previous);}
            if(!$valid)throw new RuntimeException('DOCX_XML_INVALID');
            $xpath=new DOMXPath($doc);$xpath->registerNamespace('w','http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $lines=[];
            foreach($xpath->query('//w:p[not(ancestor::w:del)]') as $paragraph){
                $text='';foreach($xpath->query('.//w:t[not(ancestor::w:del)]|.//w:tab|.//w:br',$paragraph) as $node)$text.=$node->localName==='t'?$node->textContent:($node->localName==='tab'?"\t":"\n");
                if(trim($text)!=='')$lines[]=$text;
            }
            $parts[$name]=implode("\n",$lines);
        }
        if(trim(implode('', $parts))==='')throw new RuntimeException('DOCX_TEXT_EMPTY_OR_LIMIT');
        ksort($parts);$text='';foreach($parts as $name=>$body)$text.="\n[".$name."]\n".$body."\n";
        if(trim($text)===''||mb_strlen($text)>500000)throw new RuntimeException('DOCX_TEXT_EMPTY_OR_LIMIT');
        return [['type'=>'text','text'=>json_encode(['format'=>'docx','sha256'=>hash('sha256',$bytes),'parts'=>array_keys($parts),'embedded_media_count'=>$images,'visual_review_required'=>true,'notice'=>'Text aus dem Original-Word-Dokument. Tabellenzellreihenfolge erhalten, aber keine visuelle Layoutprüfung; eingebettete Bilder sind hier nicht ausgewertet.'],JSON_UNESCAPED_UNICODE)],['type'=>'text','text'=>$text]];
    }finally{if($opened)$zip->close();@unlink($tmp);}
}
