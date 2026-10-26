<?php
declare(strict_types=1);

function mcpSourceKey(string $claimId,string $kind,string $sourceId): string {
    $uuid='/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D';
    if(!preg_match($uuid,$claimId)||!in_array($kind,['messages','message','document'],true)||($kind==='messages'?$sourceId!=='':!preg_match($uuid,$sourceId)))throw new InvalidArgumentException('SOURCE_READ_REQUEST_INVALID');
    return $claimId.':'.$kind.($sourceId!==''?':'.$sourceId:'');
}
function mcpSourceSnapshot(array $row,string $profile,string $claimId,string $kind,string $sourceId): array {
    if(in_array($row['status']??'',['queued','running','failed'],true))return ['available'=>false,'job_id'=>(int)$row['id'],'status'=>$row['status'],'reason'=>$row['status']==='failed'?'SOURCE_READ_FAILED':'SOURCE_READ_PENDING','detail'=>(string)($row['message']??''),'retry'=>false];
    $r=json_decode((string)($row['result_json']??''),true);
    if(($row['status']??'')!=='done'||!is_array($r)||($r['source']??'')!=='claimsforce-source-read'||($r['sourceProfile']??'')!==$profile||($r['claimId']??'')!==$claimId||($r['kind']??'')!==$kind||($r['sourceId']??'')!==$sourceId||($r['readOnly']??false)!==true)return ['available'=>false,'reason'=>'NO_VERIFIED_SOURCE_READ'];
    $stamp=strtotime((string)($r['fetchedAt']??''));
    if(!$stamp||$stamp>time()+60)return ['available'=>false,'reason'=>'INVALID_SOURCE_TIME'];
    return ['available'=>true,'stale'=>time()-$stamp>300,'job_id'=>(int)$row['id'],'result'=>$r];
}
function mcpClaimsSource(array $user,string $folder,string $kind,string $sourceId,bool $refresh): array {
    requireCaseFolderAccess($folder,$user);
    if(!ownCase($user,$folder))throw new RuntimeException('SOURCE_CASE_ACCESS_DENIED');
    $meta=mcpDriveCaseMeta($folder,mcpDriveCaseFiles($folder));
    $profile=svnetUserProfile($user);$claimId=(string)($meta['claimsforce_claim_id']??'');
    if($profile===''||($meta['claimsforce_profile']??'')!==$profile)throw new RuntimeException('SOURCE_PROFILE_MISMATCH');
    $key=mcpSourceKey($claimId,$kind,$sourceId);$email=(string)$user['email'];
    $s=db()->prepare("SELECT id,status,message,result_json FROM claimsforce_import_jobs WHERE requested_by=:email AND profile=:profile AND sync_mode='source' AND claim_number=:key ORDER BY id DESC LIMIT 1");
    $s->execute([':email'=>$email,':profile'=>$profile,':key'=>$key]);
    $snapshot=mcpSourceSnapshot($s->fetch()?:[],$profile,$claimId,$kind,$sourceId);
    if(!$refresh)return $snapshot;
    $lock='mcp-source-read:'.substr(hash('sha256',$email.$profile),0,40);
    $s=db()->prepare('SELECT GET_LOCK(:key,2)');$s->execute([':key'=>$lock]);
    if((int)$s->fetchColumn()!==1)throw new RuntimeException('Quellenabruf wird bereits vorbereitet.');
    try{
        $s=db()->prepare("SELECT id,sync_mode,status,claim_number FROM claimsforce_import_jobs WHERE requested_by=:email AND profile=:profile AND status IN ('queued','running') ORDER BY id DESC LIMIT 1");
        $s->execute([':email'=>$email,':profile'=>$profile]);$pending=$s->fetch();
        if(!$pending){
            $s=db()->prepare("INSERT INTO claimsforce_import_jobs(profile,status,requested_by,message,phase,sync_mode,claim_number,source_profiles_json,created_at) VALUES(:profile,'queued',:email,'Claimsforce-Quelle gezielt lesen','CF-QUEUED','source',:key,:profiles,NOW())");
            $s->execute([':profile'=>$profile,':email'=>$email,':key'=>$key,':profiles'=>json_encode([$profile])]);
            $pending=['id'=>(int)db()->lastInsertId(),'sync_mode'=>'source','status'=>'queued','claim_number'=>$key];
        }
        $snapshot['refresh']=$pending;
        $snapshot['refresh_notice']=$pending['claim_number']===$key?'Gezielter Leseabruf vorgemerkt. Mit denselben Parametern und refresh=false erneut lesen.':'Ein anderer Auftrag läuft. Danach diesen Quellenabruf mit refresh=true anfordern.';
        return $snapshot;
    }finally{$s=db()->prepare('SELECT RELEASE_LOCK(:key)');$s->execute([':key'=>$lock]);}
}
function mcpSourceContent(array $snapshot): array {
    $r=$snapshot['result']??null;
    // The message list is the document inventory entrypoint. Repeating every
    // case filename for each single mail wastes context and causes compaction.
    // Keep the complete mail and its named attachments unchanged.
    if(is_array($r)&&($r['kind']??'')==='message')unset($snapshot['result']['documents']);
    if(!is_array($r)||($r['kind']??'')!=='document')return [['type'=>'text','text'=>json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]];
    $bytes=base64_decode((string)($r['base64']??''),true);
    if($bytes===false||strlen($bytes)>8*1024*1024||!hash_equals((string)($r['sha256']??''),hash('sha256',$bytes)))throw new RuntimeException('SOURCE_DOCUMENT_HASH_MISMATCH');
    unset($snapshot['result']['base64']);
    $blocks=[['type'=>'text','text'=>json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]];
    $mime=(new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
    if(str_starts_with($bytes,"PK\x03\x04")&&in_array($mime,['application/zip','application/vnd.openxmlformats-officedocument.wordprocessingml.document'],true))return array_merge($blocks,mcpDocxContent($bytes));
    if($mime==='application/pdf'){
        $text=mcpDrivePdfText($bytes);
        if($text!=='')$blocks[]=['type'=>'text','text'=>$text];
        else $blocks[]=['type'=>'text','text'=>json_encode(mcpPdfOcr($bytes),JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)];
    }elseif(in_array($mime,['image/png','image/jpeg','image/webp'],true))$blocks[]=['type'=>'image','mimeType'=>$mime,'data'=>base64_encode($bytes)];
    elseif(str_starts_with($mime,'text/'))$blocks[]=['type'=>'text','text'=>mb_substr($bytes,0,500000)];
    else $blocks[]=['type'=>'text','text'=>'Dokument gelesen, aber dieses Format ist noch nicht als Text darstellbar: '.$mime.'. Keine vollständige Auswertung behaupten.'];
    return $blocks;
}
