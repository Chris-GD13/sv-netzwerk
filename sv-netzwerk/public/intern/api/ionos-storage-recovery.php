<?php
declare(strict_types=1);
/** Recoverable filesystem publication coordinated by the SQLite writer lock. */
function ionosIntentPath(string $id): string {
    if(!preg_match('/^[A-Za-z0-9_-]+$/D',$id))throw new RuntimeException('Ungültige Wiederanlauf-ID.');
    return ionosStorageRoot().'/pending/'.$id.'.json';
}
function ionosDurableJson(string $path,array $value): void {
    $dir=dirname($path);if(!is_dir($dir)&&!mkdir($dir,0770,true))throw new RuntimeException('Wiederanlaufverzeichnis fehlt.');
    $tmp=tempnam($dir,'intent-');$body=json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    if($tmp===false)throw new RuntimeException('Wiederanlaufdatei fehlt.');
    $f=fopen($tmp,'wb');if(!$f)throw new RuntimeException('Wiederanlaufdatei nicht schreibbar.');
    try{if(fwrite($f,$body)!==strlen($body)||!fflush($f)||(function_exists('fsync')&&!fsync($f)))throw new RuntimeException('Wiederanlaufdatei nicht gesichert.');}finally{fclose($f);}
    chmod($tmp,0660);if(!rename($tmp,$path))throw new RuntimeException('Wiederanlaufdatei konnte nicht übernommen werden.');
}
function ionosRow(string $id): ?array {
    $s=ionosStorageDb()->prepare('SELECT * FROM items WHERE id=?');$s->execute([$id]);$r=$s->fetch();return $r?:null;
}
function ionosRowFingerprint(?array $row): string {
    return hash('sha256',json_encode($row,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
}
function ionosRestoreBytes(string $backup,string $path): void {
    $tmp=tempnam(ionosStorageRoot().'/objects','restore-');
    if($tmp===false||!copy($backup,$tmp))throw new RuntimeException('Vorversion konnte nicht wiederhergestellt werden.');
    chmod($tmp,0660);
    if(function_exists('posix_geteuid')&&posix_geteuid()===0){$owner=stat(ionosStorageRoot().'/objects');if(!$owner||!chown($tmp,$owner['uid'])||!chgrp($tmp,$owner['gid']))throw new RuntimeException('Dienstbesitzer konnte nicht erhalten werden.');}
    if(!rename($tmp,$path))throw new RuntimeException('Vorversion konnte nicht übernommen werden.');
}
/** Caller holds BEGIN IMMEDIATE. No unknown/newer record is ever overwritten. */
function ionosRecoverIntentLocked(string $id): string {
    $intentPath=ionosIntentPath($id);if(!is_file($intentPath))return 'NO_PENDING_OPERATION';
    $i=json_decode((string)file_get_contents($intentPath),true,512,JSON_THROW_ON_ERROR);
    if(($i['id']??'')!==$id||!preg_match('/^[a-f0-9]{64}$/D',$i['newSha256']??''))throw new RuntimeException('Ungültiger Wiederanlaufnachweis.');
    $row=ionosRow($id);$meta=$row?json_decode($row['meta'],true,512,JSON_THROW_ON_ERROR):[];
    $path=ionosStorageRoot().'/objects/'.$id;$currentSha=is_file($path)?hash_file('sha256',$path):null;
    if(($meta['_svnet_write_operation']??'')===$i['operation']){
        if($currentSha!==$i['newSha256']||($meta['sha256Checksum']??null)!==$currentSha||($meta['md5Checksum']??null)!==md5_file($path)||(string)($meta['size']??'')!==(string)filesize($path))throw new RuntimeException('Bestätigte Dateiversion fehlt; Wiederanlauf gesperrt.');
        $outcome='COMMITTED_VERSION_VERIFIED';
    }else{
        if(ionosRowFingerprint($row)!==$i['oldRowFingerprint'])throw new RuntimeException('Neuerer oder unbekannter Indexstand; Wiederanlauf gesperrt.');
        if($currentSha!==$i['oldSha256']){
            if($currentSha!==null&&$currentSha!==$i['newSha256'])throw new RuntimeException('Unbekannter Dateiinhalt; Wiederanlauf gesperrt.');
            if($i['oldSha256']!==null){
                $backup=$i['backup']??'';
                if(!preg_match('/^[A-Za-z0-9_-]+-[a-f0-9]{16}$/D',$backup))throw new RuntimeException('Ungültige Vorversion.');
                $backupPath=ionosStorageRoot().'/versions/'.$backup;
                if(!is_file($backupPath)||hash_file('sha256',$backupPath)!==$i['oldSha256'])throw new RuntimeException('Geprüfte Vorversion fehlt.');
                ionosRestoreBytes($backupPath,$path);
            }elseif(is_file($path)&&!unlink($path))throw new RuntimeException('Unbestätigte neue Datei konnte nicht zurückgenommen werden.');
        }
        if((is_file($path)?hash_file('sha256',$path):null)!==$i['oldSha256'])throw new RuntimeException('Vorversion konnte nicht verifiziert werden.');
        $outcome='ROLLED_BACK_TO_PREVIOUS_VERSION';
    }
    if(!unlink($intentPath))throw new RuntimeException('Wiederanlaufnachweis konnte nicht abgeschlossen werden.');
    return $outcome;
}
function ionosRecoverPending(string $id): string {
    // Do not return bytes while another process is between file publication and COMMIT.
    // Always acquire the same SQLite writer lock, even when the intent is not yet visible.
    $db=ionosStorageDb();$db->exec('BEGIN IMMEDIATE');
    try{$r=ionosRecoverIntentLocked($id);$db->exec('COMMIT');return $r;}
    catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
function ionosReadBytesConsistent(string $id): string {
    $db=ionosStorageDb();$db->exec('BEGIN IMMEDIATE');
    try{
        ionosRecoverIntentLocked($id);$m=ionosItem($id);
        if($m['mimeType']==='application/vnd.google-apps.folder')throw new RuntimeException('Ordner enthält keine Datei.');
        $bytes=file_get_contents(ionosStorageRoot().'/objects/'.$id);
        if($bytes===false)throw new RuntimeException('IONOS-Datei konnte nicht gelesen werden.');
        if(isset($m['md5Checksum'])&&!hash_equals($m['md5Checksum'],md5($bytes)))throw new RuntimeException('Datei und Index passen nicht zusammen.');
        if(isset($m['sha256Checksum'])&&!hash_equals($m['sha256Checksum'],hash('sha256',$bytes)))throw new RuntimeException('Dateiinhalt stimmt nicht mit dem bestätigten Hash überein.');
        $db->exec('COMMIT');return $bytes;
    }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
}
/** New-source retry contract only. Trusted context must come from existing server
 * access checks, never request data. Legacy source binding must be resolved first.
 * No HTTP endpoint or importer invokes this isolated candidate yet.
 */
function ionosStoreNewSource(array $trusted,array $received,array $meta,string $bytes): array {
    foreach(['profile','caseId','sourceFileId'] as $k){
        if(!is_string($trusted[$k]??null)||trim($trusted[$k])===''||($received[$k]??null)!==$trusted[$k])throw new RuntimeException('SOURCE_IDENTITY_MISMATCH');
    }
    foreach(['caseId','sourceFileId'] as $k)if(!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$trusted[$k]))throw new RuntimeException('CANONICAL_CLAIMS_UUID_REQUIRED');
    if(($trusted['legacyInventoryResolved']??false)!==true)throw new RuntimeException('LEGACY_SOURCE_BINDING_REQUIRED');
    if(($meta['parents'][0]??null)!==($trusted['targetFolderId']??null)||!is_string($trusted['targetFolderId']??null))throw new RuntimeException('SOURCE_FOLDER_MISMATCH');
    $sourceHash=hash('sha256',json_encode([$trusted['profile'],$trusted['caseId'],$trusted['sourceFileId']],JSON_THROW_ON_ERROR));
    $id='ionos_src_'.$sourceHash;$contentHash=hash('sha256',$bytes);$db=ionosStorageDb();
    for($attempt=0;$attempt<2;$attempt++){
        $db->exec('BEGIN IMMEDIATE');
        try{
            ionosRecoverIntentLocked($id);$row=ionosRow($id);
            if($row!==null){
                $m=ionosItem($id);
                if(($m['appProperties']['svSourceIdentityHash']??null)!==$sourceHash)throw new RuntimeException('SOURCE_BINDING_CONFLICT');
                foreach(['profile'=>'svClaimsProfile','caseId'=>'svClaimsClaimId','sourceFileId'=>'svClaimsSourceFileId'] as $k=>$property)if(($m['appProperties'][$property]??null)!==$trusted[$k])throw new RuntimeException('SOURCE_BINDING_CONFLICT');
                if(($m['parents'][0]??null)!==$trusted['targetFolderId'])throw new RuntimeException('SOURCE_FOLDER_MISMATCH');
                if(!is_file(ionosStorageRoot().'/objects/'.$id)||hash_file('sha256',ionosStorageRoot().'/objects/'.$id)!==$contentHash)throw new RuntimeException('SOURCE_CONTENT_UPDATE_REQUIRES_PRECONDITION');
                $db->exec('COMMIT');return ['duplicate'=>true,'file'=>$m];
            }
            $pre=['id'=>$id,'rowFingerprint'=>ionosRowFingerprint(null),'sha256'=>null,'allowCreate'=>true,'contextRecords'=>$trusted['contextRecords']??[]];
            $db->exec('COMMIT');
        }catch(Throwable $e){$db->exec('ROLLBACK');throw $e;}
        $meta['appProperties']=array_replace($meta['appProperties']??[],['svSourceIdentityHash'=>$sourceHash,'svClaimsProfile'=>$trusted['profile'],'svClaimsClaimId'=>$trusted['caseId'],'svClaimsSourceFileId'=>$trusted['sourceFileId']]);
        try{return ['duplicate'=>false,'file'=>ionosWrite($meta,$bytes,$id,$pre)];}
        catch(Throwable $e){if($e->getMessage()!=='SOURCE_VERSION_CONFLICT'||$attempt===1)throw $e;}
    }
    throw new RuntimeException('SOURCE_RETRY_UNRESOLVED');
}

/** Serialize read/list/create cycles across PHP workers. No timeout waits forever. */
function ionosWithImportLock(string $scope,callable $operation): mixed {
    $dir=ionosStorageEnabled()?ionosStorageRoot().'/locks':sys_get_temp_dir().'/svnet-import-locks';
    if(!is_dir($dir)&&!@mkdir($dir,0770,true)&&!is_dir($dir))throw new RuntimeException('IMPORT_LOCK_UNAVAILABLE');
    $lock=fopen($dir.'/'.hash('sha256',$scope).'.lock','c');
    if(!$lock)throw new RuntimeException('IMPORT_LOCK_UNAVAILABLE');
    $deadline=microtime(true)+5;
    try{
        while(!flock($lock,LOCK_EX|LOCK_NB)){if(microtime(true)>=$deadline)throw new RuntimeException('IMPORT_ALREADY_RUNNING');usleep(20000);}
        try{return $operation();}finally{flock($lock,LOCK_UN);}
    }finally{fclose($lock);}
}
