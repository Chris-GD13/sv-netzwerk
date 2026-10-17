<?php
declare(strict_types=1);
require_once __DIR__.'/../public/intern/api/private-business-pdf.php';
$d=pbDefaults('christian');$d['scope']='Prüfung';$d['company']='Interne Prüfung';$d['hours']=3;$d['billing_street']='Straße 1';$d['billing_postcode']='73432';$d['billing_city']='Aalen';
$r=['id'=>'test','number'=>'26-12345','kind'=>'offer','revision'=>1,'updated_at'=>'2026-10-17 09:00:00','data'=>$d];$files=pbDocuments($r);
$logo=__DIR__.'/../public/intern/assets/private-business/sv-netzwerk-eu-logo.jpg';
if(hash_file('sha256',$logo)!=='f329c3cb5245846da2f99007f6a1030e03a51bd3bd851eb8ebd8db65ec0bf96f')throw new RuntimeException('Use official sv-netzwerk.eu logo only; never the .de car logo');
foreach(['main','contract','withdrawal','packet']as$kind){if(!str_starts_with($files[$kind],'%PDF-1.4')||!str_contains($files[$kind],'/LogoSchuett')||!str_contains($files[$kind],'/LogoNetzwerk'))throw new RuntimeException('PDF or correct dual branding missing');}
if(!str_contains($files['withdrawal'],'Muster-Widerrufsformular')||!str_contains($files['withdrawal'],'[ ]')||str_contains($files['withdrawal'],'[x]'))throw new RuntimeException('Withdrawal must include form and unselected declarations');
if(str_contains($files['withdrawal'],'Feldstra')||!str_contains($files['withdrawal'],'73432 Aalen'))throw new RuntimeException('Withdrawal correspondence must use the Aalen address');
foreach(array_merge([''=>''],array_column(pbExperts(),'name','id'))as$key=>$name){
    $choice=$r;$choice['data']=pbClean(array_replace($d,['expert'=>$key]),'christian');$pdf=pbDocuments($choice);
    foreach(['main','contract']as$kind){if($name===''){if(str_contains($pdf[$kind],trCp('Sachverständiger:')))throw new RuntimeException('Unnamed expert must not be printed');}elseif(!str_contains($pdf[$kind],trCp('Sachverständiger: '.$name)))throw new RuntimeException('Full expert name missing');}
}
$katja=$r;$katja['data']['correspondence_email']='ks@sv-schuett.eu';foreach(pbDocuments($katja)as$pdf){if(!str_contains($pdf,'ks@sv-schuett.eu')||str_contains($pdf,'ws@sv-schuett.eu'))throw new RuntimeException('All Katja document contact and withdrawal addresses must use Katja email');}
if(!str_contains($files['contract'],'Factoringunternehmen')||str_contains($files['contract'],'TeamFaktor')||str_contains($files['contract'],'Finnya'))throw new RuntimeException('Contract must give the generic factoring notice without naming the provider');
foreach(['main','contract']as$kind)if(!str_contains($files[$kind],'100 % Vorkasse')||!str_contains($files[$kind],'Zahlungsbedingungen')||!str_contains($files[$kind],trCp('vollständigem')))throw new RuntimeException('Full gross prepayment after factoring rejection and before acceptance/performance must be transparent');
echo "private_business_pdf_test: ok (three documents, packet, Schuett and official eu logo, unsigned declarations)\n";
