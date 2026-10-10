<?php
declare(strict_types=1);
require __DIR__.'/../public/intern/api/gf-zwischenbericht-docx.php';
$path=$argv[1]??'';
if(!$path||!is_file($path)){echo "Original template integration test requires path argument.\n";exit(0);}
$original=(string)file_get_contents($path);
$result=['form_fields'=>['bericht_nummer'=>'3','letzte_besprechung_am'=>'09.10.2026','teilnehmer_1'=>'Teilnehmer A','teilnehmer_2'=>'Teilnehmer B','vn_anschrift'=>'Wohnanschrift 1','schadenort'=>'Risikoanschrift 2','gebaeude_bislang'=>'nicht bestätigt','gebaeude_befuerwortet'=>'478,36 EUR','gebaeude_gesamt'=>'mind. 478,36 EUR'],
    'sections'=>array_map(fn($text)=>['text'=>$text],['Feststellungen mit 478,36 EUR.','Neu zu zahlen sind 478,36 EUR.','Weitere Abwicklung.','68.528,52','Kein Regress ersichtlich.'])];
$bytes=gfZwischenberichtDocx($original,['schaden_nr'=>'TEST-ENGEL-3','vn_objekt'=>'TEST-VN'],$result,'Christian Wächter');
$temp=tempnam(sys_get_temp_dir(),'zb-test-');file_put_contents($temp,$bytes);$zip=new ZipArchive();$zip->open($temp);$before=new ZipArchive();$before->open($path);
for($i=0;$i<$before->numFiles;$i++){$name=$before->getNameIndex($i);if(!in_array($name,['word/document.xml','word/header1.xml'],true)&&$before->getFromName($name)!==$zip->getFromName($name))throw new RuntimeException('Original component changed: '.$name);}
$dom=new DOMDocument();$dom->loadXML($zip->getFromName('word/document.xml'));$x=new DOMXPath($dom);$x->registerNamespace('w','http://schemas.openxmlformats.org/wordprocessingml/2006/main');
foreach([[1,3,1,'TEST-ENGEL-3'],[1,7,1,'Wohnanschrift 1'],[1,9,1,'Risikoanschrift 2'],[3,2,3,'478,36 EUR']] as [$t,$r,$c,$expected]){
    $actual=$x->query('/w:document/w:body/w:tbl['.$t.']/w:tr['.$r.']/w:tc['.$c.']')->item(0)->textContent;
    if(!str_contains($actual,$expected))throw new RuntimeException('Wrong original field: '.$expected);
}
if(!str_contains($dom->textContent,'cw@sv-netzwerk.eu'))throw new RuntimeException('Signature missing');
$zip->close();$before->close();unlink($temp);
if(isset($argv[2]))file_put_contents($argv[2],$bytes);
echo "gf_zwischenbericht_docx_test: original field positions and all unchanged package parts verified\n";
