<?php
declare(strict_types=1);
require_once __DIR__.'/../public/intern/api/document-review-core.php';
function senderFunction(string $file,string $name):void {
    $source=file_get_contents($file);$start=strpos($source,'function '.$name.'(');$next=strpos($source,'\nfunction ',$start+1);
    // All selected helpers are self-contained and followed by another function.
    if($next===false)$next=strpos($source,"\nfunction ",$start+1);
    eval(substr($source,$start,$next-$start));
}
senderFunction(__DIR__.'/../public/intern/api/kva-release.php','krSenderProfile');
senderFunction(__DIR__.'/../public/intern/api/outlook-case-mail.php','omProfile');
senderFunction(__DIR__.'/../public/intern/api/kva-release.php','krFmt');
senderFunction(__DIR__.'/../public/intern/api/kva-release.php','krDraftBody');
foreach ([['Christian Wächter','cw'],['Marc Schütt','ms'],['Holger Roth','hr'],['Susanne Wächter','ws']] as [$name,$local]) {
    $email=$local.'@sv-schuett.eu';$user=['email'=>$email,'full_name'=>'SV Büro Marc Schütt e.K. '.$name];
    $kva=krSenderProfile($user);$review=drReviewSender($kva);$mail=omProfile($user);
    if($kva['email']!==$email||$review['email']!==$email||$review['mailbox']!==$email||$mail['sender']!==$email)throw new RuntimeException('Sender differs between invoice, offer, KVA or technical mail for '.$name);
    if($kva['name']!==$name||$mail['sender_name']!==$name)throw new RuntimeException('Office name overrides actual mailbox identity');
}
$draft=krDraftBody(['quote_number'=>'TEST-17','net'=>1000,'gross'=>1190,'sender_name'=>'Christian Wächter']);
if(!str_contains($draft,reviewSignature('Christian Wächter'))||str_contains($draft,'SV-Büro Marc Schütt'))throw new RuntimeException('KVA signature differs from invoice and offer');
echo "Rechnung, Angebot, KVA und technische Mail verwenden je Profil das verbundene Konto.\n";
