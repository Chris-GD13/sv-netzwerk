<?php
declare(strict_types=1);
require_once __DIR__.'/../public/intern/api/document-review-core.php';
function check(bool $ok,string $text):void { if (!$ok) throw new RuntimeException($text); }
function fails(callable $fn,string $text):void { try {$fn();}catch(RuntimeException){return;}throw new RuntimeException($text); }
check(drMoney('9.760,16 €')===9760.16,'German cents');
check(drMoney('9760.16')===9760.16,'Decimal API amount');
check(drMoney(null)===null,'Unknown is not zero');
check(drReviewSender(['name'=>'Christian Wächter','email'=>'cw@sv-schuett.eu'])['email']==='cw@sv-schuett.eu','Christian uses the connected mailbox as sender');
check(drReviewSender(['name'=>'Christian Wächter','email'=>'cw@sv-schuett.eu'])['mailbox']==='cw@sv-schuett.eu','Mailbox identity remains separate from From alias');
check(drReviewSender(['name'=>'Marc Schütt','email'=>'ms@sv-schuett.eu'])['email']==='ms@sv-schuett.eu','Other profiles retained');
$values=['company'=>'POLYGON Deutschland GmbH','number'=>'RE-17','date'=>'09.10.2026','gross'=>'1.190,00','net'=>'1.000,00','vat'=>'190,00','release_amount'=>'1.190,00','decision'=>'approved','reason'=>'Abschlagsrechnung','to'=>'versicherung@example.org','cc'=>'controlling@dieregulierer.de','bcc'=>'Archiv@sv.de'];
fails(fn()=>drValidate(['mode'=>'direct'],array_replace($values,['reason'=>''])),'Direct needs reason');
fails(fn()=>drValidate(['mode'=>'direct'],array_replace($values,['decision'=>''])),'Explicit decision');
fails(fn()=>drValidate(['mode'=>'direct'],array_replace($values,['release_amount'=>'1.200,00'])),'No excessive release');
fails(fn()=>drValidate(['mode'=>'review','analysis'=>['assessment'=>'Read']],$values),'Read alone is not human confirmation');
fails(fn()=>drValidate(['mode'=>'review','analysis'=>null],array_replace($values,['review_confirmed'=>true,'assessment'=>'checked'])),'Bypass cannot masquerade as review');
$accepted=drValidate(['mode'=>'direct'],$values);
$record=['kind'=>'invoice','mode'=>'direct','case_no'=>'TEST-17','values'=>$accepted,'sender_name'=>'Christian Wächter','subject'=>'Rechnungsprüfung · TEST-17','file_name'=>'Original-Rechnung.pdf','mime'=>'application/pdf'];
check(str_starts_with(drSubject(array_replace($record,['case_no'=>'26-085905-9'])),'26-085905-9 · Rechnungsprüfung'),'Insurance claim number leads subject');
fails(fn()=>drSubject(array_replace($record,['case_no'=>''])),'No email subject without insurance claim number');
$message=drMessage($record,"%PDF-Original\x00\xff");
check(drMessage($record+['sender'=>'cw@sv-netzwerk.eu'],'original')['from']['emailAddress']['address']==='cw@sv-netzwerk.eu','Actual From address is explicit');
check(base64_decode($message['attachments'][0]['contentBytes'])==="%PDF-Original\x00\xff",'Original byte-identical attachment');
check($message['attachments'][0]['name']==='Original-Rechnung.pdf','Original attachment name');
check($message['toRecipients'][0]['emailAddress']['address']==='versicherung@example.org','Recipient role');
check($message['bccRecipients'][0]['emailAddress']['address']==='Archiv@sv.de','Archive selection remains BCC');
check(str_contains($message['body']['content'],'ohne erneute Prüfung'),'Direct wording');
check(!str_contains($message['body']['content'],'Prüfergebnis:'),'Never claim a direct invoice was checked');
check(str_contains($message['body']['content'],'1.190,00 EUR brutto'),'Exact cents in email');
check(str_contains($message['body']['content'],'Bausachverständiger') && str_contains($message['body']['content'],'cw@sv-schuett.eu'),'Full Christian signature with connected sender');
check(!str_contains($message['body']['content'],'SV-Büro Marc Schütt'),'No wrong office in Christian signature');
$rejected=drValidate(['mode'=>'review','analysis'=>['assessment'=>'Plausibility']],array_replace($values,['decision'=>'rejected','assessment'=>'Doppelt berechnet','review_confirmed'=>true,'reason'=>'Bereits abgerechnet']));
check($rejected['release_amount']===0.0,'Rejected is never a payment');
$record['values']=$rejected;$record['mode']='review';$record['kind']='offer';
$text=drBody($record);
check(str_contains($text,'Angebot')&&str_contains($text,'nicht freigegeben'),'Separate offer rejection');
check(!str_contains($text,'Freigabebetrag:'),'No release amount for rejection');
check(str_contains($text,'Bereits abgerechnet')&&!str_contains($text,'Doppelt berechnet'),'Mail uses the human statement, not the internal assessment');
$internal='Das ist als mitgeteilte Angabe dokumentiert, nicht als unabhängig nachgewiesene Freigabe. Offene Punkte: keine zusätzlichen Nachweise zu Leistungsumfang, Aufmaß, Zahlungsstand oder etwaigen Teilfreigaben im Belegsatz erkennbar. KI-Prüfvorschlag.';
foreach (['invoice','offer'] as $kind) {
    $human='Ich habe den Beleg geprüft. Bitte reichen Sie das Aufmaß für die noch nicht anerkannten Leistungen nach.';
    $review=drValidate(['mode'=>'review','analysis'=>['assessment'=>$internal]],array_replace($values,['assessment'=>$internal,'review_confirmed'=>true,'reason'=>$human]));
    $mail=drMessage(array_replace($record,['kind'=>$kind,'mode'=>'review','values'=>$review,'sender'=>'cw@sv-schuett.eu']),'original');
    check(str_contains($mail['body']['content'],$human)&&!str_contains($mail['body']['content'],$internal),'Internal evidence notes never reach either mail kind');
    check($mail['from']['emailAddress']['address']==='cw@sv-schuett.eu','Mail From uses the connected mailbox');
    fails(fn()=>drValidate(['mode'=>'review','analysis'=>['assessment'=>$internal]],array_replace($values,['assessment'=>$internal,'review_confirmed'=>true,'reason'=>''])),'A checked internal analysis cannot replace the human mail statement');
    foreach (['review','direct'] as $mode) {
        fails(fn()=>drValidate(['mode'=>$mode,'analysis'=>['assessment'=>$internal]],array_replace($values,['assessment'=>$internal,'review_confirmed'=>true,'reason'=>$internal])),'Copied internal notes are rejected for both modes');
        fails(fn()=>drBody(array_replace($record,['kind'=>$kind,'mode'=>$mode,'values'=>array_replace($review,['reason'=>'Die KI hat den Beleg freigegeben.'])])),'Last-step body validation prevents automated release wording');
    }
}
foreach (['Die Rechnung wurde durch KI geprüft.','Automatisierte Freigabe','Offene Punkte: Belege fehlen.','Das ist als mitgeteilte Angabe dokumentiert.','Nicht unabhängig nachgewiesen.'] as $forbidden) {
    fails(fn()=>reviewAssertCorrespondence($forbidden),'Internal or machine wording blocked');
}
reviewAssertCorrespondence('Die Freigabe umfasst ausschließlich die aufgeführten Leistungen. Der Zahlungsstand ist vor Auszahlung abzugleichen. Bitte reichen Sie die Fremdgewerksrechnung nach.');
fails(fn()=>drRecipients('not-an-address'),'Invalid email rejected');
check(count(drRecipients('test@example.org, TEST@example.org'))===1,'Deduplicate recipients');
$energy=drValidate(['mode'=>'direct'],array_replace($values,['energy_kwh'=>'431','energy_rate'=>'0,35','energy_vn'=>'ETG Bussenstraße 45a']));
check($energy['energy_amount']===150.85 && $energy['gross']===1190.0 && $energy['release_amount']===1190.0,'Energy separate from supplier release');
$changed=drValidate(['mode'=>'direct'],array_replace($values,['energy_kwh'=>'431','energy_rate'=>'0,40']));check($changed['energy_amount']===172.40,'Editable energy tariff');
$energyMail=drBody(array_replace($record,['values'=>$energy]));check(str_contains($energyMail,'150,85 EUR')&&str_contains($energyMail,'Erstattung an den VN'),'Separate VN energy reimbursement in email');
fails(fn()=>drValidate(['mode'=>'direct'],array_replace($values,['energy_kwh'=>'431','energy_rate'=>'0'])),'Invalid energy rate rejected');
echo "Rechnung/Angebot, direkte Übernahme, Entscheidungen, Centbeträge und Originalanhang geprüft.\n";
