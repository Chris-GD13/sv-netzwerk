<?php
declare(strict_types=1);
require_once __DIR__.'/../public/intern/api/document-review-core.php';
function check(bool $ok,string $text):void { if (!$ok) throw new RuntimeException($text); }
function fails(callable $fn,string $text):void { try {$fn();}catch(RuntimeException){return;}throw new RuntimeException($text); }
check(drMoney('9.760,16 €')===9760.16,'German cents');
check(drMoney('9760.16')===9760.16,'Decimal API amount');
check(drMoney(null)===null,'Unknown is not zero');
check(drReviewSender(['name'=>'Christian Wächter','email'=>'cw@sv-schuett.eu'])['email']==='cw@sv-netzwerk.eu','Christian uses SV-Netzwerk sender');
check(drReviewSender(['name'=>'Marc Schütt','email'=>'ms@sv-schuett.eu'])['email']==='ms@sv-schuett.eu','Other profiles retained');
$values=['company'=>'POLYGON Deutschland GmbH','number'=>'RE-17','date'=>'09.10.2026','gross'=>'1.190,00','net'=>'1.000,00','vat'=>'190,00','release_amount'=>'1.190,00','decision'=>'approved','reason'=>'Abschlagsrechnung','to'=>'versicherung@example.org','cc'=>'controlling@dieregulierer.de','bcc'=>'Archiv@sv.de'];
fails(fn()=>drValidate(['mode'=>'direct'],array_replace($values,['reason'=>''])),'Direct needs reason');
fails(fn()=>drValidate(['mode'=>'direct'],array_replace($values,['decision'=>''])),'Explicit decision');
fails(fn()=>drValidate(['mode'=>'direct'],array_replace($values,['release_amount'=>'1.200,00'])),'No excessive release');
fails(fn()=>drValidate(['mode'=>'review','analysis'=>['assessment'=>'Read']],$values),'Read alone is not human confirmation');
fails(fn()=>drValidate(['mode'=>'review','analysis'=>null],array_replace($values,['review_confirmed'=>true,'assessment'=>'checked'])),'Bypass cannot masquerade as review');
$accepted=drValidate(['mode'=>'direct'],$values);
$record=['kind'=>'invoice','mode'=>'direct','case_no'=>'TEST-17','values'=>$accepted,'sender_name'=>'Christian Wächter','subject'=>'Rechnungsprüfung · TEST-17','file_name'=>'Original-Rechnung.pdf','mime'=>'application/pdf'];
$message=drMessage($record,"%PDF-Original\x00\xff");
check(base64_decode($message['attachments'][0]['contentBytes'])==="%PDF-Original\x00\xff",'Original byte-identical attachment');
check($message['attachments'][0]['name']==='Original-Rechnung.pdf','Original attachment name');
check($message['toRecipients'][0]['emailAddress']['address']==='versicherung@example.org','Recipient role');
check($message['bccRecipients'][0]['emailAddress']['address']==='Archiv@sv.de','Archive selection remains BCC');
check(str_contains($message['body']['content'],'ohne erneute Prüfung'),'Direct wording');
check(!str_contains($message['body']['content'],'Prüfergebnis:'),'Never claim a direct invoice was checked');
check(str_contains($message['body']['content'],'1.190,00 EUR brutto'),'Exact cents in email');
check(str_contains($message['body']['content'],'Bausachverständiger') && str_contains($message['body']['content'],'cw@sv-netzwerk.eu'),'Full Christian signature');
check(!str_contains($message['body']['content'],'SV-Büro Marc Schütt'),'No wrong office in Christian signature');
$rejected=drValidate(['mode'=>'review','analysis'=>['assessment'=>'Plausibility']],array_replace($values,['decision'=>'rejected','assessment'=>'Doppelt berechnet','review_confirmed'=>true,'reason'=>'Bereits abgerechnet']));
check($rejected['release_amount']===0.0,'Rejected is never a payment');
$record['values']=$rejected;$record['mode']='review';$record['kind']='offer';
$text=drBody($record);
check(str_contains($text,'Angebot')&&str_contains($text,'nicht freigegeben'),'Separate offer rejection');
check(!str_contains($text,'Freigabebetrag:'),'No release amount for rejection');
fails(fn()=>drRecipients('not-an-address'),'Invalid email rejected');
check(count(drRecipients('test@example.org, TEST@example.org'))===1,'Deduplicate recipients');
echo "Rechnung/Angebot, direkte Übernahme, Entscheidungen, Centbeträge und Originalanhang geprüft.\n";
