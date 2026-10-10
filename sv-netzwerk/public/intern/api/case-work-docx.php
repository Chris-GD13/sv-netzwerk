<?php
declare(strict_types=1);

/** Format the already reviewed answer; never ask another model to rewrite it. */
function cwTextDocx(string $text): string {
    $escape=static fn(string $s):string=>htmlspecialchars($s,ENT_XML1|ENT_QUOTES,'UTF-8');
    $run=static function(string $s,bool $bold=false)use($escape):string{
        return '<w:r><w:rPr>'.($bold?'<w:b/>':'').'</w:rPr><w:t xml:space="preserve">'.$escape(str_replace('**','',$s)).'</w:t></w:r>';
    };
    $paragraph=static function(array $lines,bool $keep=false)use($run):string{
        $body='';foreach($lines as $i=>$line){if($i)$body.='<w:r><w:br/></w:r>';$body.=$run($line);}
        return '<w:p><w:pPr><w:spacing w:after="120"/>'.($keep?'<w:keepLines/>':'').'</w:pPr>'.$body.'</w:p>';
    };
    $cells=static fn(string $line):array=>array_map('trim',explode('|',trim(trim($line),'|')));
    $isSeparator=static function(string $line)use($cells):bool{
        $parts=$cells($line);if(count($parts)<2)return false;
        foreach($parts as $part)if(!preg_match('/^:?-{3,}:?$/',$part))return false;return true;
    };
    $lines=preg_split('/\R/u',trim($text));$body='';$buffer=[];
    $flush=static function()use(&$body,&$buffer,$paragraph):void{
        if($buffer){$body.=$paragraph($buffer,str_contains(implode("\n",$buffer),'Mit freundlichen Grüßen'));$buffer=[];}
    };
    for($i=0;$i<count($lines);$i++){
        if(isset($lines[$i+1])&&str_contains($lines[$i],'|')&&$isSeparator($lines[$i+1])){
            $flush();$rows=[$cells($lines[$i])];$i+=2;
            while($i<count($lines)&&str_contains($lines[$i],'|')&&trim($lines[$i])!==''){$rows[]=$cells($lines[$i]);$i++;}$i--;
            $columns=count($rows[0]);$width=(int)floor(9638/$columns);
            $body.='<w:tbl><w:tblPr><w:tblW w:w="9638" w:type="dxa"/><w:tblBorders>';
            foreach(['top','left','bottom','right','insideH','insideV'] as $edge)$body.='<w:'.$edge.' w:val="single" w:sz="4" w:color="C7D2DB"/>';
            $body.='</w:tblBorders><w:tblCellMar><w:top w:w="80" w:type="dxa"/><w:left w:w="80" w:type="dxa"/><w:bottom w:w="80" w:type="dxa"/><w:right w:w="80" w:type="dxa"/></w:tblCellMar></w:tblPr><w:tblGrid>'.str_repeat('<w:gridCol w:w="'.$width.'"/>',$columns).'</w:tblGrid>';
            foreach($rows as $r=>$row){
                if(count($row)!==$columns)throw new RuntimeException('Zahlungsübersicht hat uneinheitliche Spalten. Bitte Text korrigieren.');
                $body.='<w:tr><w:trPr><w:cantSplit/>'.($r===0?'<w:tblHeader/>':'').'</w:trPr>';
                foreach($row as $cell)$body.='<w:tc><w:tcPr><w:tcW w:w="'.$width.'" w:type="dxa"/>'.($r===0?'<w:shd w:fill="EEF3F6"/>':'').'</w:tcPr><w:p><w:pPr><w:spacing w:after="60"/></w:pPr>'.$run($cell,$r===0).'</w:p></w:tc>';
                $body.='</w:tr>';
            }
            $body.='</w:tbl><w:p/>';continue;
        }
        if(trim($lines[$i])===''){$flush();continue;}$buffer[]=$lines[$i];
    }
    $flush();
    $files=[
        '[Content_Types].xml'=>'<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/><Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/></Types>',
        '_rels/.rels'=>'<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>',
        'word/_rels/document.xml.rels'=>'<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
        'word/styles.xml'=>'<?xml version="1.0" encoding="UTF-8"?><w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial"/><w:sz w:val="21"/><w:lang w:val="de-DE"/></w:rPr></w:rPrDefault><w:pPrDefault><w:pPr><w:spacing w:line="250" w:lineRule="auto"/></w:pPr></w:pPrDefault></w:docDefaults></w:styles>',
        'word/document.xml'=>'<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$body.'<w:sectPr><w:pgSz w:w="11906" w:h="16838"/><w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134"/></w:sectPr></w:body></w:document>',
    ];
    $tmp=tempnam(sys_get_temp_dir(),'work-docx-');if($tmp===false)throw new RuntimeException('Word-Datei konnte nicht vorbereitet werden.');
    try{$zip=new ZipArchive();if($zip->open($tmp,ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Word-Datei konnte nicht geöffnet werden.');foreach($files as $name=>$bytes)$zip->addFromString($name,$bytes);$zip->close();return (string)file_get_contents($tmp);}finally{unlink($tmp);}
}
