<?php
declare(strict_types=1);
/** Adapter for the existing IONOS storage.
 * $profile MUST come from the existing authenticated server profile resolver.
 * It must never be copied from the incoming request. Existing profile-routing policy
 * is reused, without owner registration or automatic creation of profile folders.
 */
function cfrUuid(string $v): void {if(!preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$v))throw new RuntimeException('CANONICAL_CLAIMS_UUID_REQUIRED');}
function cfrDescendant(string $id,string $root): bool {
    $seen=[];
    for($n=0;$n<100;$n++){
        if($id===$root)return true;
        if(isset($seen[$id]))throw new RuntimeException('SOURCE_FOLDER_CYCLE');$seen[$id]=true;
        $m=ionosItem($id);$id=(string)($m['parents'][0]??'');if($id==='')return false;
    }
    throw new RuntimeException('SOURCE_FOLDER_DEPTH_LIMIT');
}
/** Metadata and its CAS token must be derived from exactly one verified SQLite row. */
function cfrReadItemRow(string $id): array {
    $row=ionosRow($id);
    if($row===null||(int)$row['verified']!==1)throw new RuntimeException('SOURCE_VERIFIED_ITEM_REQUIRED');
    $meta=json_decode($row['meta'],true,512,JSON_THROW_ON_ERROR);
    $meta['id']=$row['id'];$meta['name']=$row['name'];$meta['mimeType']=$row['mime'];$meta['parents']=$row['parent']!==''?[$row['parent']]:[];
    return [$row,$meta];
}
function cfrMetadataContext(array $row): array {return ['id'=>$row['id'],'kind'=>'metadata','rowFingerprint'=>ionosRowFingerprint($row)];}
function cfrAssertCaseAccessReadOnly(string $folder,array $user,string $profile): array {
    if(!svnetCanAccessCaseProfile($user,$profile))throw new RuntimeException('SOURCE_CASE_ACCESS_DENIED');
    $legacy=gdFolderId('cases');[$rootRow,$root]=cfrReadItemRow($legacy);$baseRootRow=$rootRow;
    if($profile!=='christian'){
        $parent=(string)($root['parents'][0]??'');
        if($parent==='')throw new RuntimeException('EXISTING_PROFILE_ROOT_REQUIRED');
        $q=ionosStorageDb()->prepare("SELECT id FROM items WHERE parent=? AND name=? AND mime='application/vnd.google-apps.folder' AND verified=1");$q->execute([$parent,svnetCasesFolderName($profile)]);$matches=$q->fetchAll();
        if(count($matches)!==1)throw new RuntimeException('UNIQUE_EXISTING_PROFILE_ROOT_REQUIRED');
        [$rootRow,$root]=cfrReadItemRow($matches[0]['id']);
        if($rootRow['parent']!==$parent||$rootRow['name']!==svnetCasesFolderName($profile))throw new RuntimeException('SOURCE_CASE_ACCESS_DENIED');
    }
    [$caseRow,$case]=cfrReadItemRow($folder);
    if(($root['mimeType']??'')!=='application/vnd.google-apps.folder'||($case['mimeType']??'')!=='application/vnd.google-apps.folder'||($case['trashed']??false)||($root['trashed']??false)||!in_array($root['id'],$case['parents']??[],true))throw new RuntimeException('SOURCE_CASE_ACCESS_DENIED');
    return $baseRootRow['id']===$rootRow['id']?[cfrMetadataContext($caseRow),cfrMetadataContext($rootRow)]:[cfrMetadataContext($caseRow),cfrMetadataContext($rootRow),cfrMetadataContext($baseRootRow)];
}
/** Bind every target ancestor so a concurrent folder move invalidates the write. */
function cfrFolderContext(string $target,string $case): array {
    $records=[];$seen=[];
    while($target!==$case){
        if(isset($seen[$target])||count($seen)>=100)throw new RuntimeException('SOURCE_FOLDER_CYCLE');
        $seen[$target]=true;[$row,$m]=cfrReadItemRow($target);
        if($m['mimeType']!=='application/vnd.google-apps.folder'||($m['trashed']??false))throw new RuntimeException('SOURCE_FOLDER_MISMATCH');
        $records[]=cfrMetadataContext($row);
        $target=(string)($m['parents'][0]??'');if($target==='')throw new RuntimeException('SOURCE_FOLDER_MISMATCH');
    }
    return $records;
}
/** Supporting artifacts have an authenticated case binding but no original-file UUID. */
function cfrSupportingContext(string $folder,array $user,string $profile,array $identity): array {
    if(($identity['profile']??null)!==$profile)throw new RuntimeException('SUPPORTING_SOURCE_CONTEXT_MISMATCH');
    cfrUuid((string)($identity['claimId']??''));
    $context=cfrAssertCaseAccessReadOnly($folder,$user,$profile);
    $q=ionosStorageDb()->prepare("SELECT id FROM items WHERE parent=? AND name='00_Falldaten.json' AND verified=1");$q->execute([$folder]);$rows=$q->fetchAll();
    if(count($rows)!==1)throw new RuntimeException('UNIQUE_CASE_METADATA_REQUIRED');
    $id=$rows[0]['id'];[$row,$item]=cfrReadItemRow($id);
    if($row['parent']!==$folder||$row['name']!=='00_Falldaten.json'||$row['mime']!=='application/json'||($item['trashed']??false))throw new RuntimeException('SOURCE_CASE_METADATA_CONTEXT_MISMATCH');
    $bytes=ionosBytes($id);$meta=json_decode($bytes,true,512,JSON_THROW_ON_ERROR);
    if(($meta['claimsforce_claim_id']??null)!==$identity['claimId']||($meta['claimsforce_profile']??null)!==$profile)throw new RuntimeException('SUPPORTING_SOURCE_CONTEXT_MISMATCH');
    return array_merge($context,[['id'=>$id,'kind'=>'content','rowFingerprint'=>ionosRowFingerprint($row),'sha256'=>hash('sha256',$bytes)]]);
}
function cfrContext(string $folder,array $user,string $profile,array $request): array {
    $accessRecords=cfrAssertCaseAccessReadOnly($folder,$user,$profile);
    foreach(['claimId','sourceFileId'] as $k)cfrUuid((string)($request[$k]??''));
    if($profile===''||($request['profile']??null)!==$profile)throw new RuntimeException('SOURCE_PROFILE_MISMATCH');
    $q=ionosStorageDb()->prepare("SELECT id FROM items WHERE parent=? AND name='00_Falldaten.json' AND verified=1");$q->execute([$folder]);$rows=$q->fetchAll();
    if(count($rows)!==1)throw new RuntimeException('UNIQUE_CASE_METADATA_REQUIRED');
    $id=$rows[0]['id'];[$row,$item]=cfrReadItemRow($id);
    if($row['parent']!==$folder||$row['name']!=='00_Falldaten.json'||$row['mime']!=='application/json'||($item['trashed']??false))throw new RuntimeException('SOURCE_CASE_METADATA_CONTEXT_MISMATCH');
    $bytes=ionosBytes($id);$meta=json_decode($bytes,true,512,JSON_THROW_ON_ERROR);
    if(empty($meta['claimsforce_claim_id'])||empty($meta['claimsforce_profile']))throw new RuntimeException('TRUSTED_CASE_CONTEXT_REQUIRED');
    if($meta['claimsforce_claim_id']!==$request['claimId']||$meta['claimsforce_profile']!==$profile)throw new RuntimeException('SOURCE_CASE_PROFILE_MISMATCH');
    $target=(string)($request['targetFolderId']??'');
    if($target===''||!cfrDescendant($target,$folder)||ionosItem($target)['mimeType']!=='application/vnd.google-apps.folder')throw new RuntimeException('SOURCE_FOLDER_MISMATCH');
    return ['profile'=>$profile,'caseId'=>$request['claimId'],'sourceFileId'=>$request['sourceFileId'],'targetFolderId'=>$target,
        'contextRecords'=>array_merge($accessRecords,cfrFolderContext($target,$folder),[['id'=>$id,'kind'=>'content','rowFingerprint'=>ionosRowFingerprint($row),'sha256'=>hash('sha256',$bytes)]])];
}
function cfrInventory(string $folder,string $sourceHash,array $ctx): array {
    $queue=[$folder];$seen=[];$matches=[];$unbound=[];$q=ionosStorageDb()->prepare('SELECT id FROM items WHERE parent=? AND verified=1');
    while($queue){
        $parent=array_shift($queue);if(isset($seen[$parent]))throw new RuntimeException('SOURCE_FOLDER_CYCLE');$seen[$parent]=true;
        if(count($seen)>10000)throw new RuntimeException('SOURCE_INVENTORY_LIMIT');
        $q->execute([$parent]);$children=$q->fetchAll();
        foreach($children as $child){
            $m=ionosItem($child['id']);if(($m['trashed']??false)===true)continue;
            if($m['mimeType']==='application/vnd.google-apps.folder'){$queue[]=$m['id'];continue;}
            if($parent===$folder&&$m['name']==='00_Falldaten.json')continue;
            $props=$m['appProperties']??[];
            if(($props['svClaimsSupporting']??null)==='1'){
                if(($props['svClaimsProfile']??null)!==$ctx['profile']||($props['svClaimsClaimId']??null)!==$ctx['caseId']||!is_string($props['svContentSha256']??null)||!hash_equals($props['svContentSha256'],hash('sha256',ionosBytes($m['id']))))throw new RuntimeException('SUPPORTING_SOURCE_CONTEXT_MISMATCH');
                continue;
            }
            $binding=$m['appProperties']['svSourceIdentityHash']??null;
            $props=$m['appProperties']??[];
            if(!is_string($binding)||!preg_match('/^[a-f0-9]{64}$/D',$binding)||empty($props['svClaimsProfile'])||empty($props['svClaimsClaimId'])||empty($props['svClaimsSourceFileId'])){$unbound[]=$m['id'];continue;}
            if($props['svClaimsProfile']!==$ctx['profile']||$props['svClaimsClaimId']!==$ctx['caseId']||hash('sha256',json_encode([$props['svClaimsProfile'],$props['svClaimsClaimId'],$props['svClaimsSourceFileId']],JSON_THROW_ON_ERROR))!==$binding)throw new RuntimeException('SOURCE_BINDING_SCOPE_CONFLICT');
            if($binding===$sourceHash)$matches[]=$m['id'];
        }
    }
    if(count($matches)>1)throw new RuntimeException('AMBIGUOUS_SOURCE_BINDING');
    return ['matches'=>$matches,'unbound'=>$unbound];
}
function cfrSourceStateUnlocked(string $folder,array $user,string $profile,array $request): array {
    $ctx=cfrContext($folder,$user,$profile,$request);$key=hash('sha256',json_encode([$ctx['profile'],$ctx['caseId'],$ctx['sourceFileId']],JSON_THROW_ON_ERROR));
    $inv=cfrInventory($folder,$key,$ctx);
    if(!$inv['matches']){
        // Compatibility boundary: old imports have no trustworthy Claims UUID binding.
        // Do not guess from name, timestamp, size or a possibly copied document.
        if($inv['unbound'])throw new RuntimeException('LEGACY_SOURCE_BINDING_REQUIRED: Vorhandene Altdateien sind keinem eindeutig geprueften Claims-Quelldokument zugeordnet. Der Dateiimport bleibt bis zur kontrollierten Zuordnung gesperrt; es wurde kein Ersatzdokument angelegt.');
        return ['kind'=>'NEW_SOURCE','context'=>$ctx,'sourceHash'=>$key,'originalPrecondition'=>null];
    }
    $id=$inv['matches'][0];$row=ionosRow($id);$bytes=ionosBytes($id);
    return ['kind'=>'EXISTING_SOURCE','context'=>$ctx,'sourceHash'=>$key,'portalFileId'=>$id,
        'originalPrecondition'=>['id'=>$id,'rowFingerprint'=>ionosRowFingerprint($row),'sha256'=>hash('sha256',$bytes),'contextRecords'=>$ctx['contextRecords']]];
}
/** The same case lock is shared with manual imports, including their folder creation. */
function cfrSourceState(string $folder,array $user,string $profile,array $request): array {
    return ionosWithImportLock('case:'.$folder,fn()=>cfrSourceStateUnlocked($folder,$user,$profile,$request));
}
function cfrApplySource(string $folder,array $user,string $profile,array $request,array $readState,string $name,string $mime,string $bytes,?string $providedOperationId=null): array {
    return ionosWithImportLock('case:'.$folder,fn()=>cfrApplySourceUnlocked($folder,$user,$profile,$request,$readState,$name,$mime,$bytes,$providedOperationId));
}
function cfrSourceOperationId(array $state,string $newHash): string {
    if(!preg_match('/^[a-f0-9]{64}$/D',$newHash)||empty($state['context']['contextRecords'])||empty($state['sourceHash'])||!in_array($state['kind']??null,['NEW_SOURCE','EXISTING_SOURCE'],true))throw new InvalidArgumentException('SOURCE_ORIGINAL_READ_REQUIRED');
    $records=array_map(static fn($r)=>[$r['id'],$r['kind'],$r['rowFingerprint'],$r['sha256']??null],$state['context']['contextRecords']);
    return hash('sha256',json_encode(['claimsforce-source-refresh-v1',$state['sourceHash'],$state['kind'],$state['context']['targetFolderId'],$state['portalFileId']??null,$state['originalPrecondition']['rowFingerprint']??null,$state['originalPrecondition']['sha256']??null,$records,$newHash],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
}
function cfrApplySourceUnlocked(string $folder,array $user,string $profile,array $request,array $readState,string $name,string $mime,string $bytes,?string $providedOperationId=null): array {
    $operationId=cfrSourceOperationId($readState,hash('sha256',$bytes));
    if($providedOperationId!==null&&!hash_equals($operationId,$providedOperationId))throw new InvalidArgumentException('SOURCE_OPERATION_ID_MISMATCH');
    $current=cfrSourceStateUnlocked($folder,$user,$profile,$request);
    foreach(['profile','caseId','sourceFileId','targetFolderId'] as $k)if(($readState['context'][$k]??null)!==$current['context'][$k])throw new RuntimeException('SOURCE_READ_STATE_IDENTITY_MISMATCH');
    if(($readState['context']['contextRecords']??null)!==$current['context']['contextRecords'])throw new RuntimeException('SOURCE_CONTEXT_VERSION_CONFLICT');
    if(($readState['sourceHash']??null)!==$current['sourceHash'])throw new RuntimeException('SOURCE_BINDING_CONFLICT');
    if($current['kind']==='EXISTING_SOURCE'){
        $id=$current['portalFileId'];
        // A lost response can be reconciled by exact bytes; no second update occurs.
        if(hash('sha256',$bytes)===$current['originalPrecondition']['sha256'])return ['duplicate'=>true,'file'=>ionosItem($id),'sourceState'=>$current,'operationId'=>$operationId];
        if($readState['kind']!=='EXISTING_SOURCE'||($readState['portalFileId']??null)!==$id||!is_array($readState['originalPrecondition']??null))throw new RuntimeException('SOURCE_ORIGINAL_READ_REQUIRED');
        $pre=$readState['originalPrecondition'];$pre['contextRecords']=$readState['context']['contextRecords'];
        $properties=array_replace(ionosItem($id)['appProperties']??[],['svLastSourceOperationId'=>$operationId]);
        $saved=ionosWrite(['appProperties'=>$properties], $bytes, $id,$pre);
        if(hash('sha256',ionosBytes($id))!==hash('sha256',$bytes))throw new RuntimeException('SOURCE_READBACK_MISMATCH');
        return ['duplicate'=>false,'file'=>$saved,'sourceState'=>cfrSourceStateUnlocked($folder,$user,$profile,$request),'operationId'=>$operationId];
    }
    if(($readState['kind']??null)!=='NEW_SOURCE')throw new RuntimeException('SOURCE_READ_STATE_MISMATCH');
    $ctx=$current['context'];$ctx['legacyInventoryResolved']=true;
    $saved=ionosStoreNewSource($ctx,$ctx,['name'=>$name,'mimeType'=>$mime,'parents'=>[$ctx['targetFolderId']],'appProperties'=>['svLastSourceOperationId'=>$operationId]],$bytes);
    if(hash('sha256',ionosBytes($saved['file']['id']))!==hash('sha256',$bytes))throw new RuntimeException('SOURCE_READBACK_MISMATCH');
    return $saved+['sourceState'=>cfrSourceStateUnlocked($folder,$user,$profile,$request),'operationId'=>$operationId];
}
/** HTTP adapter; profile is resolved by the existing authenticated router. */
function cfrHandleRequest(string $action,array $authenticatedUser,string $validatedProfile): never {
    if(!ionosStorageEnabled())apiError(503,'IONOS-Quellspeicher ist nicht verfügbar.');
    if(($_SERVER['REQUEST_METHOD']??'')!=='POST')apiError(405,'POST erforderlich.');
    try{
        if($action==='claimsforce_source_state'){
            $body=requestBody();$identity=$body['source_identity']??null;$folder=trim((string)($body['folder_id']??''));
            if(!is_array($identity)||$folder==='')throw new InvalidArgumentException('SOURCE_CONTEXT_REQUIRED');
            $result=['source_state'=>cfrSourceState($folder,$authenticatedUser,$validatedProfile,$identity)];
        }elseif($action==='claimsforce_source_upload'){
            $folder=trim((string)($_POST['folder_id']??''));$identity=json_decode((string)($_POST['source_identity']??''),true,512,JSON_THROW_ON_ERROR);$read=json_decode((string)($_POST['original_read_state']??''),true,512,JSON_THROW_ON_ERROR);
            if($folder===''||!is_array($identity)||!is_array($read))throw new InvalidArgumentException('SOURCE_CONTEXT_REQUIRED');
            $file=$_FILES['file']??null;
            if(!is_array($file)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new InvalidArgumentException('SOURCE_UPLOAD_INCOMPLETE');
            $bytes=file_get_contents((string)$file['tmp_name']);$sha=(string)($_POST['source_sha256']??'');
            if($bytes===false||!preg_match('/^[a-f0-9]{64}$/D',$sha)||!hash_equals($sha,hash('sha256',$bytes)))throw new InvalidArgumentException('SOURCE_BYTES_HASH_MISMATCH');
            $operationId=(string)($_POST['operation_id']??'');
            if(!preg_match('/^[a-f0-9]{64}$/D',$operationId)||!hash_equals(cfrSourceOperationId($read,$sha),$operationId))throw new InvalidArgumentException('SOURCE_OPERATION_ID_MISMATCH');
            if(!function_exists('mime_content_type'))throw new RuntimeException('SOURCE_MIME_DETECTOR_UNAVAILABLE');
            $name=basename((string)($file['name']??'Original.pdf'));$mime=mime_content_type((string)$file['tmp_name'])?:'application/octet-stream';
            $result=cfrApplySource($folder,$authenticatedUser,$validatedProfile,$identity,$read,$name,$mime,$bytes,$operationId);
        }else throw new InvalidArgumentException('UNKNOWN_SOURCE_ACTION');
    }catch(InvalidArgumentException|JsonException $e){apiError(400,$e->getMessage());}
    catch(RuntimeException $e){apiError(str_contains($e->getMessage(),'ACCESS_DENIED')?403:(str_contains($e->getMessage(),'UNAVAILABLE')?503:409),$e->getMessage());}
    apiJson(['ok'=>true]+$result);
}
