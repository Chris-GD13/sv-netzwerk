<?php
declare(strict_types=1);
require_once __DIR__.'/../public/intern/api/svnet-branding.php';
require_once __DIR__.'/../public/intern/api/technical-release-pdf-core.php';
require_once __DIR__.'/../public/intern/api/bki-settlement-core.php';
function brandAssert(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
$bytes="Original\x00\xff";
$message=['subject'=>'Synthetischer Test','toRecipients'=>[['emailAddress'=>['address'=>'test@example.invalid']]],'body'=>['contentType'=>'Text','content'=>"Guten Tag,\n<Freigabe> & Prüfung"],'attachments'=>[['name'=>'Original.pdf','contentBytes'=>base64_encode($bytes)]]];
$once=svnetBrandMailMessage($message);$twice=svnetBrandMailMessage($once);
brandAssert($once===$twice,'Logo idempotent in Mail');
brandAssert($once['toRecipients']===$message['toRecipients']&&$once['subject']===$message['subject'],'Empfänger und Betreff unverändert');
brandAssert(base64_decode($once['attachments'][0]['contentBytes'])===$bytes,'Originalbeleg bytegleich');
brandAssert(str_contains($once['body']['content'],'&lt;Freigabe&gt; &amp; Prüfung')&&substr_count($once['body']['content'],'cid:sv-netzwerk-logo')===1,'Mailtext escaped und genau ein Inline-Logo');
brandAssert(hash('sha256',base64_decode($once['attachments'][1]['contentBytes']))==='f329c3cb5245846da2f99007f6a1030e03a51bd3bd851eb8ebd8db65ec0bf96f','Verbindliches vorhandenes eu-Logo');
$technical=trBuildTechnicalReleasePdf(['meta'=>['case_no'=>'SYNTHETISCH-001','vn'=>'Testperson','location'=>'Testobjekt','insurer'=>'Testversicherung','regulator'=>'Testprofil','decision'=>'Testentwurf','assessment'=>'Die Testleistungen sind rechnerisch nachvollziehbar.','date'=>'2026-10-20'],'quotes'=>[['source'=>'Testangebot.pdf','company'=>'Testbetrieb','quote_number'=>'DEMO-01','quote_date'=>'2026-10-20','net_total'=>1000,'vat_rate'=>19,'vat_total'=>190,'gross_total'=>1190,'positions'=>[['position_no'=>'1','description'=>'Synthetische Reparaturleistung','quantity'=>1,'unit'=>'pauschal','unit_price'=>1000,'line_total'=>1000]]]]]);
brandAssert(str_contains($technical,'/Subtype /Image')&&str_contains($technical,'/SVNetLogo Do'),'Technisches PDF bettet echtes Logo ein');
$settlement=\SvNetSettlement\build(['case_no'=>'SYNTHETISCH-001','vn'=>'Testperson','location'=>'Testobjekt','insurer'=>'Testversicherung','lines'=>[['description'=>'Synthetische Leistung','quantity'=>1,'unit'=>'pauschal','unit_price'=>1000,'total'=>1000,'gross'=>1190]]]);
if(is_array($settlement))$settlement=$settlement['bytes']??$settlement['pdf']??'';
brandAssert(is_string($settlement)&&str_contains($settlement,'/Subtype /Image'),'Abgeltungs-PDF bettet echtes Logo ein');
if(isset($argv[1])){file_put_contents($argv[1].'/branding-technical.pdf',$technical);file_put_contents($argv[1].'/branding-settlement.pdf',$settlement);}

