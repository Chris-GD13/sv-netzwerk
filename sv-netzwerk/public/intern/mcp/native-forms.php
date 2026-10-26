<?php
declare(strict_types=1);
// One bounded wait avoids rapid identical tool calls while the browser works.
// This only reads the already-authorized job; it never starts/retries a write.
function mcpNativeAwaitJob(callable $read, ?callable $clock=null, ?callable $sleep=null): array {
    $clock ??= static fn(): float => hrtime(true)/1e9;
    $sleep ??= static fn() => usleep(250000);
    $deadline=$clock()+8.0;
    do {
        $job=$read();
        if(!$job)throw new RuntimeException('NATIVE_JOB_NOT_FOUND');
        if(!in_array($job['status'],['queued','running'],true)||$clock()>=$deadline)return $job;
        $sleep();
    } while(true);
}
// Native draft requests remain in the existing authenticated station queue.
function mcpNativeSchema(): void {
    db()->exec("CREATE TABLE IF NOT EXISTS claimsforce_native_operations(operation_id CHAR(36) PRIMARY KEY,user_id INT UNSIGNED NOT NULL,folder_id VARCHAR(190) NOT NULL,profile VARCHAR(30) NOT NULL,request_hash CHAR(64) NOT NULL,request_json MEDIUMTEXT NOT NULL,job_id BIGINT UNSIGNED NULL,created_at DATETIME NOT NULL,INDEX idx_native_user(user_id,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
// A different station must not retry an uncertain creation with a fresh ID.
function mcpNativeCreateUnresolved(array $row): bool {
    $request=json_decode((string)($row['request_json']??''),true);
    if(($request['action']??'')!=='create')return false;
    $result=json_decode((string)($row['result_json']??''),true);
    return ($row['status']??'')!=='done'||($result['verified']??false)!==true||empty($result['formId']);
}
function mcpNativeForms(array $user,array $args): array {
    // User correction 26.10: continue original forms, never replace with blanks.
    if(($args['action']??'')==='create')throw new RuntimeException('NATIVE_CREATE_DISABLED_USE_EXISTING_ORIGINAL');
    $folder=trim((string)($args['id']??''));requireCaseFolderAccess($folder,$user);
    if(!ownCase($user,$folder))throw new RuntimeException('NATIVE_CASE_ACCESS_DENIED');
    $meta=mcpDriveCaseMeta($folder,mcpDriveCaseFiles($folder));$profile=svnetUserProfile($user);
    if(empty($meta['claimsforce_profile'])||empty($meta['claimsforce_claim_id']))throw new RuntimeException('NATIVE_SOURCE_BINDING_REQUIRED: Portalakte hat noch keine bestaetigte Claimsforce-Zuordnung; dies ist kein Anmeldefehler.');
    if($profile===''||($meta['claimsforce_profile']??'')!==$profile)throw new RuntimeException('NATIVE_PROFILE_MISMATCH');
    $uuid='/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/Di';
    $operation=(string)($args['operation_id']??'');$claim=(string)($meta['claimsforce_claim_id']??'');
    if(!preg_match($uuid,$operation)||!preg_match($uuid,$claim))throw new InvalidArgumentException('NATIVE_ID_INVALID');
    mcpNativeSchema();
    $s=db()->prepare('SELECT * FROM claimsforce_native_operations WHERE operation_id=:id');$s->execute([':id'=>$operation]);$existing=$s->fetch();
    if($existing&&((int)$existing['user_id']!==(int)$user['id']||$existing['folder_id']!==$folder))throw new RuntimeException('NATIVE_OPERATION_ACCESS_DENIED');
    if(($args['action']??'')==='poll'){
        if(!$existing)throw new RuntimeException('NATIVE_OPERATION_NOT_FOUND');
        $s=db()->prepare('SELECT id,status,message,result_json FROM claimsforce_import_jobs WHERE id=:id AND requested_by=:email AND profile=:profile');
        if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
        $job=mcpNativeAwaitJob(static function()use($s,$existing,$user,$profile){
            $s->execute([':id'=>$existing['job_id'],':email'=>$user['email'],':profile'=>$profile]);
            return $s->fetch();
        });
        return ['operation_id'=>$operation,'job_id'=>(int)$job['id'],'status'=>$job['status'],'detail'=>$job['message'],'result'=>$job['status']==='done'?json_decode((string)$job['result_json'],true):null];
    }
    $action=(string)($args['action']??'');$form=(string)($args['form_id']??'');
    if(!in_array($action,['inspect','save','inspect_templates','create'],true)||($form!==''&&!preg_match($uuid,$form)))throw new InvalidArgumentException('NATIVE_ACTION_INVALID');
    $request=['operationId'=>$operation,'action'=>$action,'profile'=>$profile,'caseId'=>$claim,'formId'=>$form,'claimNumber'=>(string)($args['claim_number']??'')];
    if($form!==''&&$request['claimNumber']==='')throw new InvalidArgumentException('NATIVE_CLAIM_NUMBER_REQUIRED');
    if($action==='save'){
        $changes=$args['changes']??null;$expected=(string)($args['expected_hash']??'');
        if($form===''||!preg_match('/^[a-f0-9]{64}$/D',$expected)||!is_array($changes)||!count($changes)||count($changes)>100)throw new InvalidArgumentException('NATIVE_PATCH_INVALID');
        $request['expectedHash']=$expected;$request['changes']=$changes;
    }
    if($action==='create'){
        $template=(string)($args['template_name']??'');$expected=(string)($args['expected_hash']??'');
        if($form!==''||$request['claimNumber']===''||!in_array($template,['SV Schlusserklärung','SV Zahlungsbefürwortung'],true)||!preg_match('/^[a-f0-9]{64}$/D',$expected))throw new InvalidArgumentException('NATIVE_CREATE_INVALID');
        $request['templateName']=$template;$request['expectedHash']=$expected;
    }
    $json=json_encode($request,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$hash=hash('sha256',$json);
    if(strlen($json)>100000)throw new InvalidArgumentException('NATIVE_REQUEST_TOO_LARGE');
    if($existing){if(!hash_equals($existing['request_hash'],$hash))throw new RuntimeException('NATIVE_OPERATION_CONFLICT');return ['operation_id'=>$operation,'job_id'=>(int)$existing['job_id'],'status'=>'existing','next'=>'poll'];}
    $creationLock=null;
    if($action==='create'){
        $creationLock='native-create:'.substr(hash('sha256',$profile.':'.$claim),0,48);
        $s=db()->prepare('SELECT GET_LOCK(:name,0)');$s->execute([':name'=>$creationLock]);
        if((int)$s->fetchColumn()!==1)throw new RuntimeException('NATIVE_CREATE_CASE_BUSY');
    }
    try{
        if($creationLock!==null){
            $s=db()->prepare('SELECT n.request_json,j.status,j.result_json FROM claimsforce_native_operations n LEFT JOIN claimsforce_import_jobs j ON j.id=n.job_id WHERE n.folder_id=:folder AND n.profile=:profile');
            $s->execute([':folder'=>$folder,':profile'=>$profile]);
            foreach($s->fetchAll() as $row)if(mcpNativeCreateUnresolved($row))throw new RuntimeException('NATIVE_CREATE_PRIOR_OPERATION_REQUIRES_REVIEW');
        }
        db()->beginTransaction();
        $s=db()->prepare('INSERT INTO claimsforce_native_operations(operation_id,user_id,folder_id,profile,request_hash,request_json,created_at) VALUES(:id,:user,:folder,:profile,:hash,:json,NOW())');
        $s->execute([':id'=>$operation,':user'=>$user['id'],':folder'=>$folder,':profile'=>$profile,':hash'=>$hash,':json'=>$json]);
        $s=db()->prepare("INSERT INTO claimsforce_import_jobs(profile,status,requested_by,message,phase,sync_mode,claim_number,source_profiles_json,created_at) VALUES(:profile,'queued',:email,'Natives Claimsforce-Formular bearbeiten',:phase,'nativeform',:operation,:profiles,NOW())");
        $s->execute([':profile'=>$profile,':email'=>$user['email'],':operation'=>$operation,':profiles'=>json_encode([$profile]),':phase'=>in_array($action,['create','inspect_templates'],true)?'CF-NATIVE-CREATE-QUEUED':'CF-QUEUED']);$job=(int)db()->lastInsertId();
        $s=db()->prepare('UPDATE claimsforce_native_operations SET job_id=:job WHERE operation_id=:id');$s->execute([':job'=>$job,':id'=>$operation]);db()->commit();
        return ['operation_id'=>$operation,'job_id'=>$job,'status'=>'queued','next'=>'poll','saved'=>false];
    }catch(Throwable $e){if(db()->inTransaction())db()->rollBack();throw $e;}
    finally{if($creationLock!==null){$s=db()->prepare('SELECT RELEASE_LOCK(:name)');$s->execute([':name'=>$creationLock]);}}
}
