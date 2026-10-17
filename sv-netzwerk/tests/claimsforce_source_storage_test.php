<?php
declare(strict_types=1);
$r=__DIR__;$base=sys_get_temp_dir().'/svnet-source-adapter-'.bin2hex(random_bytes(8));mkdir($base);mkdir($base.'/objects');
putenv('IONOS_STORAGE_ROOT='.$base);putenv('PORTAL_STORAGE_BACKEND=ionos');
$seed=new PDO('sqlite:'.$base.'/index.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$seed->exec('CREATE TABLE items(id TEXT PRIMARY KEY,parent TEXT,name TEXT,mime TEXT,meta TEXT,path TEXT,verified INTEGER)');
function seedItem(string $id,string $parent,string $name,string $mime,?string $bytes=null,array $extra=[]): void {
 global $seed,$base;$m=array_replace(['id'=>$id,'name'=>$name,'mimeType'=>$mime,'parents'=>$parent!==''?[$parent]:[]],$extra);$path='';
 if($bytes!==null){$path=$base.'/objects/'.$id;file_put_contents($path,$bytes);$m['size']=(string)strlen($bytes);$m['md5Checksum']=md5($bytes);}
 $q=$seed->prepare('INSERT INTO items VALUES(?,?,?,?,?,?,1)');$q->execute([$id,$parent,$name,$mime,json_encode($m),$path]);
}
$folder='application/vnd.google-apps.folder';seedItem('root','','root',$folder);
foreach(['christian'=>'cw-root','marc'=>'ms-root','holger'=>'hr-root','jens'=>'jens-root'] as $profile=>$id)seedItem($id,'root','root-'.$profile,$folder);
// Real existing profile policy, but synthetic authenticated users and configured roots.
require __DIR__.'/../public/intern/api/profile-routing.php';
$seed->exec("UPDATE items SET name='Schadenfälle Marc Schütt' WHERE id='ms-root'");$seed->exec("UPDATE items SET name='Schadenfälle Holger Roth' WHERE id='hr-root'");$seed->exec("UPDATE items SET name='Schadenfälle Jens Maurer' WHERE id='jens-root'");
foreach(['cw'=>'christian','ms'=>'marc','hr'=>'holger','jens'=>'jens'] as $short=>$profile){
 $parent=$profile==='jens'?'jens-root':$short.'-root';$case='case-'.$short;seedItem($case,$parent,$case,$folder);seedItem('files-'.$short,$case,'Formulare',$folder);
 seedItem('meta-'.$short,$case,'00_Falldaten.json','application/json',json_encode(['claimsforce_claim_id'=>'11111111-1111-4111-8111-111111111111','claimsforce_profile'=>$profile]));
}
function gdFolderId(string $kind): string {return 'cw-root';}
function gdAssertCaseAccess(...$args): never {throw new LogicException('MUTATING_ACCESS_HELPER_MUST_NOT_RUN');}
function gdRegisterCaseOwner(...$args): never {throw new LogicException('OWNER_REGISTRATION_MUST_NOT_RUN');}
function gdCreateFolder(...$args): never {throw new LogicException('FOLDER_CREATION_MUST_NOT_RUN');}
require __DIR__.'/../public/intern/api/ionos-storage.php';
require __DIR__.'/../public/intern/api/claimsforce-source-refresh-core.php';
$results=[];function verify(bool $v,string $m='assertion'): void {if(!$v)throw new RuntimeException($m);}
function testAdapter(string $name,callable $fn): void {global $results;$fn();$results[]=['name'=>$name,'ok'=>true];}
function blockedAdapter(callable $fn,string $code): void {try{$fn();}catch(Throwable $e){verify(str_contains($e->getMessage(),$code),$e->getMessage());return;}throw new RuntimeException('Expected block: '.$code);}
$cw=['email'=>'cw@sv-schuett.eu'];$ms=['email'=>'ms@sv-schuett.eu'];$hr=['email'=>'hr@sv-schuett.eu'];$ws=['email'=>'ws@sv-schuett.eu'];
$req=['claimId'=>'11111111-1111-4111-8111-111111111111','sourceFileId'=>'22222222-2222-4222-8222-222222222222','profile'=>'christian','targetFolderId'=>'files-cw'];
$initialDbHash=hash_file('sha256',$base.'/index.sqlite');$before=ionosStorageDb()->query('SELECT count(*) FROM items')->fetchColumn();
$state=cfrSourceState('case-cw',$cw,'christian',$req);
testAdapter('preflight-new-source-is-read-only-no-folder-or-owner-registration',fn()=>verify($state['kind']==='NEW_SOURCE'&&hash_file('sha256',$base.'/index.sqlite')===$initialDbHash&&ionosStorageDb()->query('SELECT count(*) FROM items')->fetchColumn()===$before));
$created=cfrApplySource('case-cw',$cw,'christian',$req,$state,'native-copy.pdf','application/pdf','first content');$id=$created['file']['id'];
testAdapter('authorized-source-create-has-stable-profile-claim-file-binding',fn()=>verify(!$created['duplicate']&&$created['file']['appProperties']['svClaimsClaimId']===$req['claimId']&&$created['file']['appProperties']['svClaimsProfile']==='christian'));
$replay=cfrApplySource('case-cw',$cw,'christian',$req,$state,'native-copy.pdf','application/pdf','first content');
testAdapter('lost-create-response-reuses-existing-record',fn()=>verify($replay['duplicate']&&$replay['file']['id']===$id));
$read=cfrSourceState('case-cw',$cw,'christian',$req);$updated=cfrApplySource('case-cw',$cw,'christian',$req,$read,'new-size.pdf','application/pdf','next changed content longer');
testAdapter('changed-size-updates-original-ID-and-keeps-name-folder',fn()=>verify(!$updated['duplicate']&&$updated['file']['id']===$id&&$updated['file']['name']==='native-copy.pdf'&&$updated['file']['parents']===['files-cw']));
testAdapter('stale-original-read-state-cannot-overwrite-newer-content',fn()=>blockedAdapter(fn()=>cfrApplySource('case-cw',$cw,'christian',$req,$read,'x','application/pdf','stale older body'),'SOURCE_VERSION_CONFLICT'));
testAdapter('lost-update-response-reconciles-same-content',fn()=>verify(cfrApplySource('case-cw',$cw,'christian',$req,$read,'x','application/pdf','next changed content longer')['duplicate']));
testAdapter('Marc-cannot-access-Christian',fn()=>blockedAdapter(fn()=>cfrSourceState('case-cw',$ms,'christian',$req),'SOURCE_CASE_ACCESS_DENIED'));
testAdapter('Holger-cannot-access-Marc',fn()=>blockedAdapter(fn()=>cfrSourceState('case-ms',$hr,'marc',array_replace($req,['profile'=>'marc','targetFolderId'=>'files-ms'])),'SOURCE_CASE_ACCESS_DENIED'));
testAdapter('Christian-cannot-impersonate-Marc',fn()=>blockedAdapter(fn()=>cfrSourceState('case-ms',$cw,'marc',array_replace($req,['profile'=>'marc','targetFolderId'=>'files-ms'])),'SOURCE_CASE_ACCESS_DENIED'));
testAdapter('Marc-own-case-allowed-by-existing-profile-policy',fn()=>verify(cfrSourceState('case-ms',$ms,'marc',array_replace($req,['profile'=>'marc','targetFolderId'=>'files-ms']))['kind']==='NEW_SOURCE'));
testAdapter('Susanne-authorized-Christian-and-Christian-authorized-Maurer',function()use($req,$ws,$cw){verify(cfrSourceState('case-cw',$ws,'christian',$req)['kind']==='EXISTING_SOURCE');verify(cfrSourceState('case-jens',$cw,'jens',array_replace($req,['profile'=>'jens','targetFolderId'=>'files-jens']))['kind']==='NEW_SOURCE');});
testAdapter('foreign-claim-and-target-folder-rejected',function()use($req,$cw){blockedAdapter(fn()=>cfrSourceState('case-cw',$cw,'christian',array_replace($req,['claimId'=>'33333333-3333-4333-8333-333333333333'])),'SOURCE_CASE_PROFILE_MISMATCH');blockedAdapter(fn()=>cfrSourceState('case-cw',$cw,'christian',array_replace($req,['targetFolderId'=>'files-ms'])),'SOURCE_FOLDER_MISMATCH');});
$sameSizeState=cfrSourceState('case-cw',$cw,'christian',$req);$sameSizeBytes=strtoupper('next changed content longer');
$sameSizeSaved=cfrApplySource('case-cw',$cw,'christian',$req,$sameSizeState,'native-copy.pdf','application/pdf',$sameSizeBytes);
testAdapter('same-name-and-size-changed-original-bytes-update-same-source-ID',fn()=>verify($sameSizeSaved['file']['id']===$id&&ionosBytes($id)===$sameSizeBytes));
$supportBytes=json_encode(['inventory'=>['claimId'=>$req['claimId']]]);
seedItem('supporting-audit','files-cw','ClaimsForce_inventory.json','application/json',$supportBytes,['appProperties'=>['svClaimsSupporting'=>'1','svClaimsClaimId'=>$req['claimId'],'svClaimsProfile'=>'christian','svContentSha256'=>hash('sha256',$supportBytes)]]);
testAdapter('explicitly-bound-supporting-artifact-does-not-block-native-source-refresh',fn()=>verify(cfrSourceState('case-cw',$cw,'christian',$req)['kind']==='EXISTING_SOURCE'));
$seed->exec("UPDATE items SET meta=json_set(meta,'$.appProperties.svClaimsProfile','marc') WHERE id='supporting-audit'");
testAdapter('foreign-profile-supporting-artifact-fails-closed',fn()=>blockedAdapter(fn()=>cfrSourceState('case-cw',$cw,'christian',$req),'SUPPORTING_SOURCE_CONTEXT_MISMATCH'));
$seed->exec("DELETE FROM items WHERE id='supporting-audit'");
// Execute the actual supporting uploader with real local storage and profile ACL.
function gdCaseCategory(string $name,string $mime):string {return 'Formulare';}
function gdFindChildByName(string $folder,string $name):?array {
    $q=ionosStorageDb()->prepare('SELECT id FROM items WHERE parent=? AND name=? AND verified=1');$q->execute([$folder,$name]);$row=$q->fetch();return $row?ionosItem($row['id']):null;
}
function gdDownload(string $id):string {return ionosBytes($id);}
function gdListChildren(string $folder,mixed $mime,int $limit):array {
    $list=ionosList(['q'=>"'".$folder."' in parents and trashed = false",'pageSize'=>$limit])['files'];
    if(isset($GLOBALS['supportingMutation'])){$mutate=$GLOBALS['supportingMutation'];unset($GLOBALS['supportingMutation']);$mutate();}
    return $list;
}
$uploadSource=file_get_contents(__DIR__.'/../public/intern/api/google-drive-sync.php');$uploadStart=strpos($uploadSource,'function gdUploadCaseFile(');$uploadEnd=strpos($uploadSource,"\nfunction gd",$uploadStart+1);eval(substr($uploadSource,$uploadStart,$uploadEnd-$uploadStart));
$supportIdentity=['claimId'=>$req['claimId'],'profile'=>'christian'];$supportAccess=['user'=>$cw,'profile'=>'christian'];
$supportSaved=gdUploadCaseFile('case-cw','audit.json','application/json','verified supporting',123,$supportIdentity,$supportAccess);
testAdapter('supporting-upload-validates-case-profile-and-reads-back-original-bytes',fn()=>verify(ionosBytes($supportSaved['file']['id'])==='verified supporting'));
foreach(['case'=>'case-cw','ancestor'=>'files-cw','metadata'=>'meta-cw'] as $kind=>$mutatedId){
    $before=ionosRow($mutatedId);$countBefore=ionosStorageDb()->query('SELECT count(*) FROM items')->fetchColumn();
    $GLOBALS['supportingMutation']=static function()use($mutatedId,$kind){
        $q=ionosStorageDb()->prepare($kind==='metadata'?"UPDATE items SET meta=json_set(meta,'$.concurrent','changed') WHERE id=?":"UPDATE items SET parent='ms-root' WHERE id=?");$q->execute([$mutatedId]);
    };
    testAdapter('supporting-upload-CAS-blocks-'.$kind.'-move-between-ACL-and-publication',fn()=>blockedAdapter(fn()=>gdUploadCaseFile('case-cw','concurrent-'.$kind.'.json','application/json','not authorized after move',124,$supportIdentity,$supportAccess),'SOURCE_CONTEXT_VERSION_CONFLICT'));
    verify(ionosStorageDb()->query('SELECT count(*) FROM items')->fetchColumn()===$countBefore,'blocked supporting upload created a file');
    $restore=ionosStorageDb()->prepare('UPDATE items SET parent=?,meta=? WHERE id=?');$restore->execute([$before['parent'],$before['meta'],$mutatedId]);
}
$beforeContext=cfrSourceState('case-cw',$cw,'christian',$req);$changedMeta=json_encode(['claimsforce_claim_id'=>$req['claimId'],'claimsforce_profile'=>'christian','internal_note'=>'new context']);ionosWrite([],$changedMeta,'meta-cw');
testAdapter('case-context-change-after-original-read-blocked',fn()=>blockedAdapter(fn()=>cfrApplySource('case-cw',$cw,'christian',$req,$beforeContext,'x','application/pdf','third content'),'SOURCE_CONTEXT_VERSION_CONFLICT'));
testAdapter('storage-CAS-also-checks-case-context-within-transaction',fn()=>blockedAdapter(fn()=>ionosWrite([],'third content',$id,$beforeContext['originalPrecondition']),'SOURCE_CONTEXT_VERSION_CONFLICT'));
$stateCurrent=cfrSourceState('case-cw',$cw,'christian',$req);$bad=$stateCurrent;unset($bad['originalPrecondition']);
testAdapter('missing-original-precondition-does-not-get-fresh-token-at-save',fn()=>blockedAdapter(fn()=>cfrApplySource('case-cw',$cw,'christian',$req,$bad,'x','application/pdf','third content'),'SOURCE_ORIGINAL_READ_REQUIRED'));
$beforeMove=cfrSourceState('case-cw',$cw,'christian',$req);ionosWrite(['parents'=>['ms-root']],null,'case-cw');
testAdapter('case-moved-to-other-profile-blocks-old-read-context',function()use($req,$cw,$beforeMove,$id){blockedAdapter(fn()=>cfrApplySource('case-cw',$cw,'christian',$req,$beforeMove,'x','application/pdf','third content'),'SOURCE_CASE_ACCESS_DENIED');blockedAdapter(fn()=>ionosWrite([],'third content',$id,$beforeMove['originalPrecondition']),'SOURCE_CONTEXT_VERSION_CONFLICT');});
ionosWrite(['parents'=>['cw-root']],null,'case-cw');
testAdapter('administrator-role-alone-does-not-grant-case-access',fn()=>blockedAdapter(fn()=>cfrSourceState('case-cw',['email'=>'admin@example.test','role'=>'admin'],'christian',$req),'kein unterstütztes Bearbeiterprofil'));
seedItem('second-case-meta','case-cw','00_Falldaten.json','application/json',$changedMeta);
testAdapter('duplicate-case-metadata-blocked-not-first-match-selected',fn()=>blockedAdapter(fn()=>cfrSourceState('case-cw',$cw,'christian',$req),'UNIQUE_CASE_METADATA_REQUIRED'));
$seed->exec("DELETE FROM items WHERE id='second-case-meta'");
seedItem('duplicate-ms-root','root','Schadenfälle Marc Schütt',$folder);
testAdapter('ambiguous-profile-root-blocked-without-creation',fn()=>blockedAdapter(fn()=>cfrSourceState('case-ms',$ms,'marc',array_replace($req,['profile'=>'marc','targetFolderId'=>'files-ms'])),'UNIQUE_EXISTING_PROFILE_ROOT_REQUIRED'));
$seed->exec("DELETE FROM items WHERE id='duplicate-ms-root'");
// Existing profile-folder lookup is read only even when the folder is missing.
$seed->exec("DELETE FROM items WHERE id='jens-root'");$missingCount=$seed->query('SELECT count(*) FROM items')->fetchColumn();
testAdapter('missing-profile-root-is-not-automatically-created',function()use($req,$cw,$seed,$missingCount){blockedAdapter(fn()=>cfrSourceState('case-jens',$cw,'jens',array_replace($req,['profile'=>'jens','targetFolderId'=>'files-jens'])),'UNIQUE_EXISTING_PROFILE_ROOT_REQUIRED');verify($seed->query('SELECT count(*) FROM items')->fetchColumn()===$missingCount);});
seedItem('legacy-case','cw-root','legacy-case',$folder);seedItem('legacy-files','legacy-case','Formulare',$folder);seedItem('legacy-meta','legacy-case','00_Falldaten.json','application/json',json_encode(['schaden_nr'=>'synthetic-legacy']));
testAdapter('legacy-metadata-without-Claims-UUID-profile-fails-closed',fn()=>blockedAdapter(fn()=>cfrSourceState('legacy-case',$cw,'christian',array_replace($req,['targetFolderId'=>'legacy-files'])),'TRUSTED_CASE_CONTEXT_REQUIRED'));
seedItem('unbound-pdf','files-ms','same-name.pdf','application/pdf','unknown legacy');
testAdapter('unknown-legacy-file-prevents-new-duplicate-even-if-name-differs',fn()=>blockedAdapter(fn()=>cfrSourceState('case-ms',$ms,'marc',array_replace($req,['profile'=>'marc','targetFolderId'=>'files-ms'])),'LEGACY_SOURCE_BINDING_REQUIRED'));
$properties=ionosItem($id)['appProperties'];ionosWrite(['appProperties'=>array_replace($properties,['svClaimsProfile'=>'marc'])],null,$id);
testAdapter('inconsistent-source-binding-profile-blocked',fn()=>blockedAdapter(fn()=>cfrSourceState('case-cw',$cw,'christian',$req),'SOURCE_BINDING_SCOPE_CONFLICT'));
ionosWrite(['appProperties'=>$properties],null,$id);
$m=ionosItem($id);seedItem('second-copy','files-cw',$m['name'],$m['mimeType'],ionosBytes($id),['appProperties'=>$m['appProperties']]);
testAdapter('two-mapped-physical-copies-block-ambiguous-update',fn()=>blockedAdapter(fn()=>cfrSourceState('case-cw',$cw,'christian',$req),'AMBIGUOUS_SOURCE_BINDING'));
class SourceHttpResponse extends Exception {function __construct(public array $data,public int $status){parent::__construct('Fixture HTTP response');}}
function apiJson(array $data,int $status=200): never {throw new SourceHttpResponse($data,$status);}
function apiError(int $status,string $error): never {throw new SourceHttpResponse(['ok'=>false,'error'=>$error],$status);}
function requestBody(): array {return $GLOBALS['source_http_body'];}
function sourceHttp(string $action,array $actor,string $profile,array $body=[],array $post=[],array $files=[],string $method='POST'): SourceHttpResponse {
    $GLOBALS['source_http_body']=$body;$_POST=$post;$_FILES=$files;$_SERVER['REQUEST_METHOD']=$method;
    try{cfrHandleRequest($action,$actor,$profile);}catch(SourceHttpResponse $r){return $r;}
}
$httpIdentity=array_replace($req,['profile'=>'holger','targetFolderId'=>'files-hr']);$httpBody=['folder_id'=>'case-hr','source_identity'=>$httpIdentity];
$httpState=sourceHttp('claimsforce_source_state',$hr,'holger',$httpBody);
testAdapter('HTTP-state-handler-validates-existing-profile-context',fn()=>verify($httpState->status===200&&$httpState->data['source_state']['kind']==='NEW_SOURCE'));
testAdapter('HTTP-GET-rejected-without-mutation',fn()=>verify(sourceHttp('claimsforce_source_state',$hr,'holger',$httpBody,[],[],'GET')->status===405));
testAdapter('HTTP-wrong-user-rejected',fn()=>verify(sourceHttp('claimsforce_source_state',$ms,'holger',$httpBody)->status===403));
$pdf='%PDF-1.4 synthetic original upload';$upload=$base.'/input.pdf';file_put_contents($upload,$pdf);$file=['file'=>['name'=>'original.pdf','tmp_name'=>$upload,'error'=>UPLOAD_ERR_OK]];
$post=['folder_id'=>'case-hr','source_identity'=>json_encode($httpIdentity),'original_read_state'=>json_encode($httpState->data['source_state']),'source_sha256'=>hash('sha256',$pdf),'operation_id'=>cfrSourceOperationId($httpState->data['source_state'],hash('sha256',$pdf))];
testAdapter('HTTP-corrupt-or-incomplete-upload-blocked-before-write',function()use($hr,$post,$file){$a=sourceHttp('claimsforce_source_upload',$hr,'holger',[],array_replace($post,['source_sha256'=>str_repeat('0',64)]),$file);verify($a->status===400&&$a->data['error']==='SOURCE_BYTES_HASH_MISMATCH');$b=sourceHttp('claimsforce_source_upload',$hr,'holger',[],$post,['file'=>array_replace($file['file'],['error'=>UPLOAD_ERR_PARTIAL])]);verify($b->status===400);});
testAdapter('HTTP-missing-original-state-rejected',fn()=>verify(sourceHttp('claimsforce_source_upload',$hr,'holger',[],array_replace($post,['original_read_state'=>'']),$file)->status===400));
if(function_exists('mime_content_type')){
    $httpSaved=sourceHttp('claimsforce_source_upload',$hr,'holger',[],$post,$file);$httpReplay=sourceHttp('claimsforce_source_upload',$hr,'holger',[],$post,$file);
    testAdapter('HTTP-original-bytes-stored-and-independently-read-back',fn()=>verify($httpSaved->status===200&&!$httpSaved->data['duplicate']&&$httpSaved->data['sourceState']['originalPrecondition']['sha256']===hash('sha256',$pdf)));
    testAdapter('HTTP-lost-response-replay-keeps-one-source-ID',fn()=>verify($httpReplay->status===200&&$httpReplay->data['duplicate']&&$httpReplay->data['file']['id']===$httpSaved->data['file']['id']));
}else testAdapter('HTTP-missing-MIME-dependency-fails-closed',fn()=>verify(sourceHttp('claimsforce_source_upload',$hr,'holger',[],$post,$file)->status===503));
echo json_encode(['passed'=>count($results),'total'=>count($results),'tests'=>$results,'scope'=>'Actual isolated source adapter, HTTP handler and PHP storage; original repository profile-routing policy; synthetic authenticated users/configured roots and local SQLite. API response helpers mocked; no live web request, existing caller migration, live authorization, Claims write or deployment.','profile_policy_sha256'=>hash_file('sha256',__DIR__.'/../public/intern/api/profile-routing.php'),'adapter_sha256'=>hash_file('sha256',__DIR__.'/../public/intern/api/claimsforce-source-refresh-core.php')],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
