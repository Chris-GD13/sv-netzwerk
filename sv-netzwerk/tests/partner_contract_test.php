<?php
declare(strict_types=1);
require_once __DIR__.'/../public/intern/api/partner-contract-document.php';
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function fails(callable $fn,int $code):void{try{$fn();}catch(Throwable $e){check($e->getCode()===$code,'Unexpected error: '.$e->getMessage());return;}throw new RuntimeException('Expected failure '.$code);}
$d=pcFields();check(count(pcMissing($d))>0,'Blank contract must stay draft');
foreach(['0','100','-1','NaN','4%','5.555','1e2']as$bad){try{pcClean(['commission'=>$bad]);throw new LogicException('Invalid percentage accepted');}catch(InvalidArgumentException){}}
check(pcClean(['commission'=>'12,50'])['commission']==='12,5','German percent normalization');
try{pcClean(['date'=>'2026-02-31']);throw new LogicException('Invalid date accepted');}catch(InvalidArgumentException){}
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);pcSchema($db);
$id='12345678-1234-4234-a234-123456789012';
$d=array_replace($d,['partner_name'=>'TEST & Muster <script>alert(1)</script>','partner_address'=>"Musterstraße 1\n12345 Musterstadt",'partner_email'=>'test@example.invalid','partner_representative'=>'Testperson','commission'=>'10','place'=>'Aalen','date'=>'2026-10-20','fields'=>'Bauschäden','region'=>'Deutschland','qualifications'=>'Nur Testdaten, kein echter Vertrag']);
$a=pcSave($db,$id,1,0,$d);check($a['revision']==1&&pcMissing($d)===[],'Complete data readback');
fails(fn()=>pcGet($db,$id,2),404);fails(fn()=>pcSave($db,$id,2,1,$d),409);
fails(fn()=>pcSave($db,$id,1,0,$d),409);
$b=pcSave($db,$id,1,1,array_replace($d,['partner_name'=>'Fortgeschriebener Test']));check($b['revision']==2,'Revision increment');
fails(fn()=>pcSave($db,$id,1,1,$d),409);check(pcGet($db,$id,1)['data']['partner_name']==='Fortgeschriebener Test','Concurrent write cannot overwrite');
$db->exec('UPDATE partner_contracts SET archived=1');fails(fn()=>pcSave($db,$id,1,2,$d),409);
$html=pcHtml($d,1);check(!str_contains($html,'<script>')&&str_contains($html,'&lt;script&gt;'),'HTML must escape partner data');
$text=json_encode(pcBlocks($d),JSON_UNESCAPED_UNICODE);
foreach(['Anlage 1','Anlage 2','nicht automatisch Vertragspartner','tatsächlich beim Partner eingegangene Nettohonorar','keine Vertragsannahme','gesetzlichen Vorschriften','vorherige ausdrückliche','kostenfrei'] as $needle)check(str_contains($text,$needle),'Required protection missing: '.$needle);
$pdf=pcPdf($d,1);check(str_starts_with($pdf,'%PDF-1.4')&&str_contains($pdf,'/Logo 5 0 R'),'PDF and official logo');
check(!str_contains($pdf,'/LogoSchuett'),'No foreign logo');
if($out=getenv('PARTNER_TEST_PDF'))file_put_contents($out,pcPdf(pcFields(),0));
echo "partner_contract_test: OK - validation, owner isolation, duplicate/retry, concurrent updates, archive guard, escaping, legal safeguards, PDF/logo\n";
