<?php
declare(strict_types=1);

/** Own standalone review -> explicitly selected, authorized existing case.
 * Source stays intact. Durable intent + content reuse makes interrupted copies resumable.
 */
function drAdoptReview(string $workspace,string $recordId,string $target,string $expected,array $user): array {
    if(!drStandalone($workspace)||$target===''||drStandalone($target))throw new RuntimeException('Freie Prüfung und vorhandenen Zielschaden auswählen.');
    $sourcePath=drWorkspacePath($workspace);
    $verify=function()use($target,$expected,$user):string{
        requireCaseFolderAccess($target,$user);$number=krCaseNo($target);
        if($number===''||$expected===''||!hash_equals($number,$expected))throw new RuntimeException('Der Zielschaden hat sich geändert. Bitte erneut öffnen.');
        return $number;
    };
    $number=$verify();$source=drSelected($workspace,$recordId);$record=json_decode($source['bytes'],true,512,JSON_THROW_ON_ERROR);
    if(($record['folder_id']??'')!==$workspace||(int)($record['user_id']??0)!==(int)$user['id']||!isset($record['values']['decision']))throw new RuntimeException('Die gespeicherte Entscheidung gehört nicht zu dieser freien Prüfung.');
    if(in_array($record['send_status']??'',['sending','uncertain'],true))throw new RuntimeException('Zuerst den unklaren Versandstatus der freien Prüfung klären.');
    $files=[];$bytesTotal=0;
    foreach($record['sources']??[['id'=>$record['file_id'],'sha256'=>$record['sha256']]] as $meta){
        $file=drSelected($workspace,$meta['id']);if(!hash_equals($meta['sha256'],hash('sha256',$file['bytes'])))throw new RuntimeException('Originalbeleg wurde seit der Prüfung geändert.');$files[$meta['id']]=$file;
    }
    if(!empty($record['reference_id'])&&!isset($files[$record['reference_id']]))$files[$record['reference_id']]=drSelected($workspace,$record['reference_id']);
    foreach($files as $file)$bytesTotal+=strlen($file['bytes']);
    if(count($files)>13||$bytesTotal>50*1024*1024)throw new RuntimeException('Zu viele oder zu große Belege für eine Übernahme.');
    $lock=fopen($sourcePath.'/adopt-'.$recordId.'.lock','c');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))throw new RuntimeException('Die Übernahme läuft bereits.');
    try{
        $journalPath=$sourcePath.'/adopt-'.$recordId.'.json';$hash=hash('sha256',$source['bytes']);
        $save=function(array $journal)use($journalPath):void{
            $temp=$journalPath.'.tmp';if(file_put_contents($temp,json_encode($journal,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),LOCK_EX)===false||!rename($temp,$journalPath))throw new RuntimeException('Übernahmestand konnte nicht gesichert werden.');
        };
        if(is_file($journalPath)){
            $journal=json_decode(file_get_contents($journalPath),true,512,JSON_THROW_ON_ERROR);
            if($journal['target']!==$target||$journal['number']!==$number)throw new RuntimeException('Diese Prüfung ist bereits einem anderen Schaden zugeordnet.');
            if(!hash_equals($journal['source_hash'],$hash))throw new RuntimeException('Die freie Prüfung wurde nach Beginn der Übernahme geändert.');
        }else{$journal=['target'=>$target,'number'=>$number,'source_hash'=>$hash,'key'=>hash('sha256',$workspace.'|'.$recordId.'|'.$target),'started_at'=>gmdate('c'),'files'=>[]];$save($journal);}
        if(isset($journal['result'])){
            drSelected($target,$journal['result']['record_id']);
            foreach($journal['files'] as $copied)if(!hash_equals($copied['hash'],hash('sha256',drSelected($target,$copied['id'])['bytes'])))throw new RuntimeException('Übernommene Unterlagen wurden verändert. Bitte den Fall prüfen.');
            return $journal['result']+['replayed'=>true];
        }
        $verify();$parent='';foreach(drChildren($target) as $child)if($child['mimeType']==='application/vnd.google-apps.folder'&&str_starts_with($child['name'],'04_')){$parent=$child['id'];break;}
        if($parent==='')$parent=ionosWrite(['name'=>'04_Rechnungen_KVA','mimeType'=>'application/vnd.google-apps.folder','parents'=>[$target]])['id'];
        foreach($files as $sourceId=>$file){
            $verify();$fileHash=hash('sha256',$file['bytes']);$id='';
            foreach(drChildren($parent) as $existing)if($existing['mimeType']!=='application/vnd.google-apps.folder'&&(int)($existing['size']??-1)===strlen($file['bytes'])&&hash_equals($fileHash,hash('sha256',ionosBytes($existing['id'])))){$id=$existing['id'];break;}
            if($id==='')$id=ionosWrite(['name'=>$number.'_'.basename($file['name']),'mimeType'=>$file['mime'],'parents'=>[$parent]],$file['bytes'])['id'];
            if(!hash_equals($fileHash,hash('sha256',ionosBytes($id))))throw new RuntimeException('Originalbeleg konnte nicht identisch zurückgelesen werden.');
            $journal['files'][$sourceId]=['id'=>$id,'hash'=>$fileHash];$save($journal);
        }
        $copy=$record;$copy['folder_id']=$target;$copy['case_no']=$number;$copy['key']=substr($journal['key'],0,32);$copy['saved_at']=$journal['started_at'];
        $copy['file_id']=$journal['files'][$record['file_id']]['id'];
        if(!empty($record['reference_id']))$copy['reference_id']=$journal['files'][$record['reference_id']]['id'];
        foreach($copy['sources']??[] as $i=>$meta)$copy['sources'][$i]['id']=$journal['files'][$meta['id']]['id'];
        $copy['adoption']=['key'=>$journal['key'],'workspace'=>$workspace,'record_id'=>$recordId,'original_case_no'=>$record['case_no']??'','original_send_status'=>$record['send_status']??'unsent','adopted_at'=>$journal['started_at']];
        $copy['adoption_requires_review']=true;$copy['send_status']='unsent';$copy['mail_policy_version']=0;
        unset($copy['mail_body'],$copy['mail_subject'],$copy['sent_at'],$copy['outlook_draft_id'],$copy['send_started_at']);
        $verify();$archive=drArchive($target);$name=$number.'_'.($record['kind']==='invoice'?'Rechnungspruefung':'Angebotspruefung').'_Uebernahme_'.substr($journal['key'],0,10).'.json';$id='';
        foreach(drChildren($archive) as $existing)if($existing['name']===$name){$saved=json_decode(ionosBytes($existing['id']),true,512,JSON_THROW_ON_ERROR);if(($saved['adoption']['key']??'')!==$journal['key'])throw new RuntimeException('Abweichendes Prüfergebnis im Zielschaden.');$id=$existing['id'];break;}
        if($id==='')$id=ionosWrite(['name'=>$name,'mimeType'=>'application/json','parents'=>[$archive]],json_encode($copy,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT))['id'];
        $saved=json_decode(drSelected($target,$id)['bytes'],true,512,JSON_THROW_ON_ERROR);if(($saved['adoption']['key']??'')!==$journal['key']||($saved['case_no']??'')!==$number)throw new RuntimeException('Prüfergebnis konnte nicht bestätigt zurückgelesen werden.');
        $journal['result']=['folder_id'=>$target,'claim_number'=>$number,'record_id'=>$id,'documents'=>count(array_unique(array_column($journal['files'],'id'))),'requires_review'=>true];$save($journal);return $journal['result']+['replayed'=>false];
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
