<?php
declare(strict_types=1);
require_once __DIR__.'/ionos-storage.php';
require_once __DIR__.'/case-rules.php';
require_once __DIR__.'/services/RuleDriveRead.php';
require_once __DIR__.'/administration-access.php';
function svAssistantValidateRelease(array $release,callable $readMaster): array {
    if(($release['status']??'')!=='approved'||!preg_match('/^[a-f0-9]{64}$/',(string)($release['source_hash']??'')))throw new RuntimeException('RULE_RELEASE_UNAPPROVED');
    $ids=[];
    foreach($release['rules']??[] as $rule){
        if(($rule['status']??'')!=='approved')continue;
        foreach($rule['sources']??[] as $source)if(is_array($source)&&!empty($source['id']))$ids[(string)$source['id']]=true;
    }
    if(count($ids)!==1)throw new RuntimeException('RULE_MASTER_AMBIGUOUS');
    $id=(string)array_key_first($ids);
    if(!preg_match('/^[a-zA-Z0-9_-]{10,190}$/',$id))throw new RuntimeException('RULE_MASTER_INVALID');
    $master=$readMaster($id);
    if(!hash_equals($release['source_hash'],hash('sha256',$master)))throw new RuntimeException('RULE_MASTER_CHANGED');
    return $release+['master_text'=>$master];
}
function svAssistantRelease(): array {
    $path=env('CASE_WORK_RULE_RELEASE','');$expected=env('CASE_WORK_RULE_RELEASE_SHA256','');
    if($path!==''){
        if(!is_file($path)||!preg_match('/^[a-f0-9]{64}$/',$expected)||!hash_equals($expected,hash_file('sha256',$path)))throw new RuntimeException('RULE_RELEASE_NOT_CONFIGURED');
        $bytes=file_get_contents($path);
    }else{
        $files=array_values(array_filter(caIonosRuleFiles(false),fn($f)=>$f['name']==='SV-Assistent-Regelrelease.txt'));
        if(count($files)!==1)throw new RuntimeException('RULE_RELEASE_NOT_CONFIGURED');
        $bytes=ionosBytes($files[0]['id']);
    }
    $release=json_decode($bytes,true,512,JSON_THROW_ON_ERROR);
    return svAssistantValidateRelease($release,function(string $id):string{
        $r=svRuleHttp('GET','https://www.googleapis.com/drive/v3/files/'.rawurlencode($id).'?alt=media&supportsAllDrives=true');
        if($r['status']!==200)throw new RuntimeException('RULE_DRIVE_ACCESS_DENIED');
        return $r['body'];
    });
}
function svAssistantLatestRejection(PDO $db,int $userId): ?array {
    $join=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?"'assistant:' || a.id":"CONCAT('assistant:',a.id)";
    $q=$db->prepare("SELECT u.usage_json FROM case_work_ai_usage u JOIN case_analysis_jobs a ON u.operation_id=$join WHERE a.user_id=? AND JSON_EXTRACT(u.usage_json,'$.provider_rejected')=true ORDER BY a.id DESC LIMIT 1");
    $q->execute([$userId]);$raw=$q->fetchColumn();return $raw===false?null:json_decode((string)$raw,true,512,JSON_THROW_ON_ERROR);
}
function svAssistantConnections(array $user): array {
    if(empty($user['is_active'])||!in_array($user['role']??'',['administrator','projektleiter','pruefer','sachverstaendiger'],true))throw new RuntimeException('USER_FORBIDDEN');
    $storage=ionosStorageEnabled();$release=null;$ruleError=null;
    try{$release=svAssistantRelease();}catch(Throwable $e){$ruleError=preg_match('/^[A-Z_]+$/',$e->getMessage())?$e->getMessage():'RULE_CHECK_FAILED';}
    $summary='Anmeldung bestätigt · Fallablage '.($storage?'IONOS bereit':'nicht bereit').' · ';
    $summary.=$release?'Regelstand '.$release['id'].' mit führender Drive-Masterdatei abgeglichen':'Regelanbindung offen ('.$ruleError.'). Freigegebenen Regelrelease in der Administration als SV-Assistent-Regelrelease.txt importieren bzw. vorhandene Drive-Verbindung prüfen.';
    $ai=trim(env('OPENAI_API_KEY',''))!=='';
    $summary.=$ai?' · KI-Zugang vorhanden · Zusatzbudget maximal 30 EUR/Monat inklusive Sicherheitspuffer':' · KI-Zugang fehlt';
    if($ai){
        require_once __DIR__.'/assistant-model-readiness.php';
        $catalogue=svAssistantModelCatalogue(trim(env('OPENAI_API_KEY','')));
        $summary.=' · Modellkatalog HTTP '.$catalogue['http_status'].' · verfügbare Budgetmodelle: '.implode(', ',$catalogue['available']);
        try{$last=svAssistantLatestRejection(db(),(int)$user['id']);if($last)$summary.=' · letzte Anbieterablehnung HTTP '.(int)$last['http_status'].' '.($last['provider_error']['code']??'');}catch(Throwable){}
    }
    $summary.=' · Claimsforce-Notizen nur manuell · Work-Übergabe manuell.';
    return ['storage_ready'=>$storage,'rules_ready'=>(bool)$release,'release'=>$release['id']??null,'paid_assistant_enabled'=>$ai&&(bool)$release,'claims_notes'=>'manual','can_manage_rules'=>svnetCanManageWorkRules($user),'summary'=>$summary];
}

/** Explicit admin synchronization. Only canonical master MD copies, never case files. */
function svAssistantSyncMaster(array $user): array {
    if(empty($user['is_active'])||!svnetCanManageWorkRules($user))throw new RuntimeException('USER_FORBIDDEN');
    $release=svAssistantRelease();$bytes=$release['master_text'];$confirmed=[];
    $files=array_values(array_filter(caIonosRuleFiles(false),fn($file)=>strcasecmp($file['name'],'MASTER-ARBEITSSTANDARD.md')===0));
    if(!$files)throw new RuntimeException('RULE_PORTAL_MASTER_MISSING');
    foreach($files as $file){
        $id=$file['id'];ionosIntentPath($id);$db=ionosStorageDb();$db->exec('BEGIN IMMEDIATE');
        try{ionosRecoverIntentLocked($id);$row=ionosRow($id);$meta=ionosItem($id);$old=file_get_contents(ionosStorageRoot().'/objects/'.$id);if($old===false)throw new RuntimeException('RULE_MASTER_READ_FAILED');$pre=['id'=>$id,'rowFingerprint'=>ionosRowFingerprint($row),'sha256'=>hash('sha256',$old)];$db->exec('COMMIT');}
        catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
        if(!hash_equals($release['source_hash'],$pre['sha256']))ionosWrite($meta,$bytes,$id,$pre);
        if(!hash_equals($release['source_hash'],hash('sha256',ionosBytes($id))))throw new RuntimeException('RULE_SYNC_READBACK_FAILED');
        $confirmed[]=$file['path'];
    }
    return ['summary'=>count($confirmed).' vorhandene Master-Kopie(n) mit der führenden Drive-Datei abgeglichen und unabhängig rückgelesen. Vorversionen bleiben gesichert.','source_hash'=>$release['source_hash'],'confirmed'=>$confirmed];
}
