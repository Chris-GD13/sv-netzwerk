<?php
declare(strict_types=1);
require_once __DIR__.'/../public/intern/api/private-business-pdf.php';
require_once __DIR__.'/../public/intern/api/private-business-pdf-cache.php';
function pdfCheck(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$r=['id'=>'qa','number'=>'26-12345','revision'=>1,'kind'=>'offer','updated_at'=>'2026-10-17 10:00:00','data'=>pbDefaults('christian')];
$files=pbCachePdfDocuments($db,$r);pdfCheck(count($files)===4,'Four PDFs must be saved in the portal');
$saved=pbCachedPdf($db,'qa',1,'packet');pdfCheck($saved['pdf_bytes']===$files['packet']&&str_starts_with($saved['pdf_bytes'],'%PDF'),'Download must use saved PDF bytes');
pdfCheck($saved['template_version']===PB_PDF_TEMPLATE,'Corrected template must be identifiable');
$r['updated_at']='2026-10-18 10:00:00';pdfCheck(pbCachePdfDocuments($db,$r)===$files,'A saved revision must never be silently regenerated');
pdfCheck(pbCachedPdf($db,'different',1,'packet')===null,'Another record must not share cached documents');
$db->exec("UPDATE private_business_pdf_cache SET pdf_bytes='wrong' WHERE kind='packet'");
try{pbCachedPdf($db,'qa',1,'packet');throw new RuntimeException('Damaged PDF served');}catch(RuntimeException $e){pdfCheck(str_contains($e->getMessage(),'bestätigt'),'Integrity check must reject modified bytes');}
echo "private_business_pdf_cache_test: ok (saved local PDFs, stable revisions, correct template, integrity, no cloud dependency)\n";
