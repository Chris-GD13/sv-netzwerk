<?php
declare(strict_types=1);
require __DIR__.'/../public/intern/api/case-work-docx.php';
$text="Sehr geehrte Damen und Herren,\n\n| Position | Forderung | Neu empfohlen |\n| --- | ---: | ---: |\n| A & B <Original> | 123,45 EUR | 123,45 EUR |\n\nMit freundlichen Grüßen\nChristian Wächter\nRegulierer und Bausachverständiger\nSV-Netzwerk\nDIN EN ISO/IEC 17024 zertifiziert\ncw@sv-netzwerk.eu\nhttps://www.sv-netzwerk.eu/";
$bytes=cwTextDocx($text);$tmp=tempnam(sys_get_temp_dir(),'cw-test-');file_put_contents($tmp,$bytes);
$zip=new ZipArchive();if($zip->open($tmp)!==true)throw new RuntimeException('Invalid Word package');
$dom=new DOMDocument();if(!$dom->loadXML($zip->getFromName('word/document.xml')))throw new RuntimeException('Invalid Word XML');
$x=new DOMXPath($dom);$x->registerNamespace('w','http://schemas.openxmlformats.org/wordprocessingml/2006/main');
if($x->query('//w:tbl')->length!==1||$x->query('//w:tr')->length!==2)throw new RuntimeException('Payment table lost');
foreach(['A & B <Original>','123,45 EUR','Regulierer und Bausachverständiger','cw@sv-netzwerk.eu'] as $value)if(!str_contains($dom->textContent,$value))throw new RuntimeException('Reviewed text changed: '.$value);
$zip->close();unlink($tmp);
try{cwTextDocx("| A | B |\n| --- | --- |\n| 1 | 2 | 3 |");throw new LogicException('Malformed table accepted');}catch(RuntimeException $error){if($error instanceof LogicException)throw $error;}
if(isset($argv[1]))file_put_contents($argv[1],$bytes);
echo "case_work_docx_test: exact text, amounts, signature and native Word table preserved\n";
