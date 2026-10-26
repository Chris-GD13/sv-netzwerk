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

$site=$r;$site['data']=pbClean(array_replace($d,['expert'=>'marc-schuett','contact'=>'Frau Beispiel','appointment'=>'30.10.2026, 10 Uhr','site_same'=>false,'site_street'=>'Objektweg 9','site_postcode'=>'54321','site_city'=>'Andersort','scope'=>'Feuchtemessung an der Kellerwand.']),'marc');
foreach(['offer','order'] as $kind){$site['kind']=$kind;$generated=pbDocuments($site);foreach(['main','contract'] as $document)foreach(['Terminadresse & Leistung','Sachverständiger: Marc Schütt','Frau Beispiel','30.10.2026, 10 Uhr','Objektweg 9','54321 Andersort','Feuchtemessung an der Kellerwand.'] as $text)if(!str_contains($generated[$document],trCp($text)))throw new RuntimeException('Missing service data: '.$text);}
echo "service block: ok (offer and order, main and contract)\n";

// Contact selection follows the appointed expert, never the editor profile.
foreach(['marc-schuett','christian-waechter'] as $expert){
    $contact=$r;$contact['data']['expert']=$expert;$contact['data']['expert_name']=$expert==='marc-schuett'?'Marc Schütt':'Christian Wächter';
    $pdfs=pbDocuments($contact);
    foreach(['main','contract'] as $kind){
        $expected=$expert==='marc-schuett'?['Beauftragter Sachverständiger','Feldstraße 108','58791 Werdohl','Mobil: 0171 211 97 77','ms@sv-schuett.eu']:['Beauftragter Sachverständiger','Nordstraße 17','73432 Aalen','Bürokontakt: Susanne Wächter','Telefon: 07367 3939783','ws@sv-schuett.eu'];
        foreach($expected as $text)if(!str_contains($pdfs[$kind],trCp($text)))throw new RuntimeException('Expert contact missing: '.$text);
        if($expert==='christian-waechter'&&str_contains($pdfs[$kind],'Mobil:'))throw new RuntimeException('Christian must not publish an expert mobile number');
    }
}
echo "appointed expert contacts: ok (Marc mobile, Christian office only)\n";
