<?php
declare(strict_types=1);
require_once __DIR__.'/../public/intern/api/private-business-pdf.php';
$d=pbDefaults('christian');$d['scope']='Prüfung';$d['company']='Interne Prüfung';$d['hours']=3;$d['billing_street']='Straße 1';$d['billing_postcode']='73432';$d['billing_city']='Aalen';
$r=['id'=>'test','number'=>'26-12345','kind'=>'offer','revision'=>1,'updated_at'=>'2026-10-17 09:00:00','data'=>$d];$files=pbDocuments($r);
$logo=__DIR__.'/../public/intern/assets/private-business/sv-netzwerk-de-logo.jpg';
if(hash_file('sha256',$logo)!=='ed4777f976d14baae6c14589e40a105369bf1315d7247fdb1b5c5bfe2f469bf4')throw new RuntimeException('Use the user-confirmed original SV-Netzwerk.de logo, not the former hexagon logo');
foreach(['main','contract','withdrawal','packet']as$kind){if(!str_starts_with($files[$kind],'%PDF-1.4')||!str_contains($files[$kind],'/LogoSchuett')||!str_contains($files[$kind],'/LogoNetzwerk'))throw new RuntimeException('PDF or dual branding missing');}
if(!str_contains($files['withdrawal'],'Muster-Widerrufsformular')||!str_contains($files['withdrawal'],'[ ]')||str_contains($files['withdrawal'],'[x]'))throw new RuntimeException('Withdrawal must include form and unselected declarations');
echo "private_business_pdf_test: ok (three documents, packet, both original logos, unsigned declarations)\n";
