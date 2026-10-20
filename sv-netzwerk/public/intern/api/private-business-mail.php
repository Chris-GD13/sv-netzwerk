<?php
declare(strict_types=1);
require_once __DIR__.'/svnet-branding.php';
function pbMailSender(array $user,array $record=[]): string {return isset($record['data'])?pbOfficeEmail($record['data']):pbUserOfficeEmail($user);}
function pbRecipients(string $value): array {
    $result=[];foreach(preg_split('/[;,]/',$value)as$part){$email=trim($part);if($email==='')continue;if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Ungültige Empfängeradresse: '.$email);$result[strtolower($email)]=$email;}
    if(!$result||count($result)>10)throw new RuntimeException('Bitte 1 bis 10 Empfängeradressen eingeben, mit Semikolon getrennt.');return array_values($result);
}
function pbGraph(string $method,string $path,?array $payload=null,bool $raw=false): array|string {
    $ch=curl_init('https://graph.microsoft.com/v1.0/'.$path);$headers=['Authorization: Bearer '.poToken(),'Content-Type: application/json'];curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>60,CURLOPT_HTTPHEADER=>$headers]);if($payload!==null)curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));elseif(in_array($method,['POST','PATCH','PUT'],true))curl_setopt($ch,CURLOPT_POSTFIELDS,'');$body=curl_exec($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);if(!is_string($body)||$status<200||$status>=300)throw new RuntimeException('Outlook hat den Vorgang nicht bestätigt (HTTP '.$status.'). Bitte den Versandstatus prüfen, bevor Sie erneut senden.');return$raw?$body:($body===''?[]:json_decode($body,true,512,JSON_THROW_ON_ERROR));
}
function pbDispatchUpdate(PDO $db,string $key,string $state,array $detail): void {$q=$db->prepare('UPDATE private_business_dispatches SET state=?,detail_json=? WHERE id=?');$q->execute([$state,json_encode($detail,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),$key]);}
function pbDispatchVerify(PDO $db,array $r,array $dispatch): array {
    $detail=json_decode($dispatch['detail_json'],true,512,JSON_THROW_ON_ERROR);if($dispatch['state']==='sent'&&empty($detail['archive_pending']))return['state'=>'sent','detail'=>$detail];
    if(!empty($detail['internet_message_id'])){
        $query=http_build_query(['$filter'=>"internetMessageId eq '".str_replace("'","''",$detail['internet_message_id'])."'",'$select'=>'id,internetMessageId,sentDateTime,subject','$top'=>2]);
        $found=pbGraph('GET','users/'.rawurlencode($detail['sender']).'/mailFolders/sentitems/messages?'.$query);
        if(count($found['value']??[])===1){$sent=$found['value'][0];$detail['sent_at']=$sent['sentDateTime'];$detail['sent_item_id']=$sent['id'];$detail['archive_pending']=true;pbDispatchUpdate($db,$dispatch['id'],'sent',$detail);
            try{$eml=pbGraph('GET','users/'.rawurlencode($detail['sender']).'/messages/'.rawurlencode($sent['id']).'/$value',null,true);$folder=pbFolder((string)$r['folder_id'],'E-Mails');$file=pbPut($folder['id'],$r['number'].'_Versand_'.substr($dispatch['id'],0,8).'.eml',$eml,'message/rfc822');$detail['archived_mail_id']=$file['id'];$detail['archive_pending']=false;pbDispatchUpdate($db,$dispatch['id'],'sent',$detail);}catch(Throwable$e){$detail['archive_error']=$e->getMessage();pbDispatchUpdate($db,$dispatch['id'],'sent',$detail);}
            pbEvent($db,$r['id'],'sent',['dispatch_id'=>$dispatch['id'],'recipient'=>$detail['to'],'revision'=>$dispatch['revision']]);return['state'=>'sent','detail'=>$detail];
        }
    }
    return ['state'=>$dispatch['state'],'detail'=>$detail];
}
function pbSend(PDO $db,array $r,array $user,array $in): array {
    $lock='pb-send-'.$r['id'];$q=$db->prepare('SELECT GET_LOCK(?,0)');$q->execute([$lock]);
    if((int)$q->fetchColumn()!==1)throw new RuntimeException('Ein Versandvorgang läuft bereits. Bitte den Versandstatus prüfen.',409);
    try{return pbSendLocked($db,$r,$user,$in);}finally{$q=$db->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lock]);}
}
function pbSendLocked(PDO $db,array $r,array $user,array $in): array {
    $key=(string)($in['dispatch_key']??'');if(!preg_match('/^[a-f0-9-]{36}$/i',$key))throw new RuntimeException('Versandkennung fehlt.');
    $q=$db->prepare('SELECT * FROM private_business_dispatches WHERE id=?');$q->execute([$key]);if($old=$q->fetch()){if($old['record_id']!==$r['id']||$old['profile']!==$r['profile'])throw new RuntimeException('Versandkennung gehört zu einem anderen Vorgang.',403);return pbDispatchVerify($db,$r,$old);}
    $q=$db->prepare("SELECT id FROM private_business_dispatches WHERE record_id=? AND state<>'sent' LIMIT 1");$q->execute([$r['id']]);if($q->fetchColumn())throw new RuntimeException('Ein früherer Versand ist noch nicht abschließend bestätigt. Bitte zuerst seinen Versandstatus prüfen.',409);
    if(pbMissing($r['data']))throw new RuntimeException('Bitte vor dem Versand alle erforderlichen Angaben ergänzen.');
    if((int)($in['revision']??0)!==(int)$r['revision'])throw new RuntimeException('Der gespeicherte Stand hat sich geändert. Bitte neu laden.',409);
    $recipients=pbRecipients((string)($in['to']??''));$to=implode('; ',$recipients);$subject=trim((string)($in['subject']??''));$body=trim((string)($in['body']??''));if($subject===''||$body===''||mb_strlen($subject)>300||mb_strlen($body)>15000)throw new RuntimeException('Bitte Empfänger, Betreff und Nachricht vollständig prüfen.');
    $q=$db->prepare('SELECT kind,item_id,filename FROM private_business_documents WHERE record_id=? AND revision=?');$q->execute([$r['id'],$r['revision']]);$files=$q->fetchAll();$attachments=[];foreach($files as$f){if($f['kind']==='packet')continue;$v=poRequest('https://graph.microsoft.com/v1.0/drives/'.rawurlencode(poDriveId()).'/items/'.rawurlencode($f['item_id']).'/content',true);$attachments[]=['@odata.type'=>'#microsoft.graph.fileAttachment','name'=>$f['filename'],'contentType'=>'application/pdf','contentBytes'=>base64_encode($v['body'])];}if(count($attachments)!==3)throw new RuntimeException('Die drei PDF-Unterlagen sind noch nicht vollständig abgelegt. Bitte erneut speichern.');
    $detail=['sender'=>pbMailSender($user,$r),'to'=>$to,'subject'=>$subject,'body'=>$body,'revision'=>$r['revision']];$q=$db->prepare('INSERT INTO private_business_dispatches(id,record_id,profile,revision,state,detail_json,created_at) VALUES(?,?,?,?,?,?,?)');$q->execute([$key,$r['id'],$r['profile'],$r['revision'],'preparing',json_encode($detail,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),pbNow()]);
    try{$message=['subject'=>$subject,'body'=>['contentType'=>'Text','content'=>$body],'toRecipients'=>array_map(static fn($email)=>['emailAddress'=>['address'=>$email]],$recipients),'attachments'=>$attachments];$draft=pbGraph('POST','users/'.rawurlencode($detail['sender']).'/messages',svnetBrandMailMessage($message));$detail['draft_id']=$draft['id'];$detail['internet_message_id']=$draft['internetMessageId']??'';pbDispatchUpdate($db,$key,'sending',$detail);pbGraph('POST','users/'.rawurlencode($detail['sender']).'/messages/'.rawurlencode($draft['id']).'/send');pbDispatchUpdate($db,$key,'accepted',$detail);$dispatch=['id'=>$key,'state'=>'accepted','detail_json'=>json_encode($detail),'revision'=>$r['revision']];return pbDispatchVerify($db,$r,$dispatch);
    }catch(Throwable$e){$detail['error']=$e->getMessage();pbDispatchUpdate($db,$key,'uncertain',$detail);throw$e;}
}
