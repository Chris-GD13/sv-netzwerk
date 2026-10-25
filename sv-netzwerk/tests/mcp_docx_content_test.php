<?php
declare(strict_types=1);
require __DIR__.'/../public/intern/mcp/docx-content.php';
function fixture(string $xml):string{$path=tempnam(sys_get_temp_dir(),'docx-test-');$z=new ZipArchive();$z->open($path,ZipArchive::OVERWRITE);$z->addFromString('word/document.xml',$xml);$z->close();$bytes=file_get_contents($path);unlink($path);return $bytes;}
$xml='<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Testbericht</w:t></w:r></w:p><w:tbl><w:tr><w:tc><w:p><w:r><w:t>Betrag</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>1.234,56 EUR</w:t></w:r></w:p></w:tc></w:tr></w:tbl><w:p><w:del><w:r><w:delText>Verworfen</w:delText></w:r></w:del></w:p></w:body></w:document>';
$bytes=fixture($xml);$r=mcpDocxContent($bytes);
if(!str_contains($r[1]['text'],"Betrag\n1.234,56 EUR")||str_contains($r[1]['text'],'Verworfen'))throw new RuntimeException('DOCX text/table mismatch');
$meta=json_decode($r[0]['text'],true);if($meta['sha256']!==hash('sha256',$bytes)||$meta['visual_review_required']!==true)throw new RuntimeException('Evidence metadata missing');
foreach(['not a zip',fixture('<!DOCTYPE x [<!ENTITY x SYSTEM "file:///private">]><x/>'),fixture('<broken>')] as $bad){try{mcpDocxContent($bad);throw new LogicException('unsafe input accepted');}catch(RuntimeException $expected){}}
echo "PASS: DOCX paragraphs/table order/hash, deleted text omitted, invalid ZIP/XML/entities rejected\n";
