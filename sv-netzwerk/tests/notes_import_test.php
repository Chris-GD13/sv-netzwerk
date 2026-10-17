<?php
declare(strict_types=1);
require_once __DIR__.'/../public/intern/api/notes-import-core.php';
function check(bool $ok,string $msg):void{if(!$ok)throw new RuntimeException($msg);}
function rejects(callable $fn,string $message):void{try{$fn();}catch(Throwable $e){check(str_contains($e->getMessage(),$message),'wrong exception '.$e->getMessage());return;}throw new RuntimeException('expected '.$message);}
$id=['folderId'=>'test-A','claimId'=>'11111111-1111-4111-8111-111111111111','profile'=>'christian','claimNumber'=>'26-TEST-A'];
$meta=['claimsforce_claim_id'=>$id['claimId'],'claimsforce_profile'=>'christian','schaden_nr'=>'26-TEST-A','claimsforce_notizen'=>[['id'=>'old','text'=>'saved']],'claimsforce_aufgaben'=>[['id'=>'task']],'reserve'=>'100'];
$read=function()use(&$meta){return $meta;};$write=function($next)use(&$meta){$meta=$next;};
$out=cfnPersist($read,$write,$id,'test-A','christian',[['id'=>'new','text'=>'new'],['id'=>'new','text'=>'new']]);
check(count($out['notes'])===2,'dedupe/retain');check($meta['reserve']==='100'&&count($meta['claimsforce_aufgaben'])===1,'retain unrelated metadata');
check(cfnPersist($read,$write,$id,'test-A','christian',[])['notes']===$out['notes'],'empty source must retain notes');
foreach(['folderId'=>'foreign','claimId'=>'22222222-2222-4222-8222-222222222222','profile'=>'marc','claimNumber'=>'wrong'] as $key=>$value){$foreign=$id;$foreign[$key]=$value;rejects(fn()=>cfnPersist($read,$write,$foreign,'test-A','christian',[]),'IDENTITY_MISMATCH');}
rejects(fn()=>cfnPersist($read,$write,$id,'test-A','marc',[]),'IDENTITY_MISMATCH');
rejects(fn()=>cfnPersist($read,fn($next)=>null,$id,'test-A','christian',[['id'=>'lost','text'=>'not saved']]),'READBACK_MISMATCH');
$old=['id'=>'1','text'=>'old','updatedAt'=>'2026-10-16'];$new=['id'=>'1','text'=>'new','updatedAt'=>'2026-10-17'];check(cfnMergeNotes([$old],[$new])[0]['text']==='new','newer');check(cfnMergeNotes([$new],[$old])[0]['text']==='new','older ignored');rejects(fn()=>cfnMergeNotes([$old],[['id'=>'1','text'=>'ambiguous']]),'VERSION_CONFLICT');
check(cfnIdentity($id)['claimNumber']==='26TESTA','normalization');
echo "notes_import_test: identity, merge, preserved tasks, failed readback and versions passed\n";

$latest=['id'=>'x','text'=>'current','updatedAt'=>'2026-10-17T10:00:00Z'];$older=$latest;$older['updatedAt']='2026-10-17T01:00:00Z';$stale=['id'=>'x','text'=>'stale','updatedAt'=>'2026-10-17T05:00:00Z'];check(cfnMergeNotes([$latest],[$older,$stale])===[$latest],'same text may not regress version');check(cfnMergeNotes([$latest],[['id'=>'x','text'=>'current']])===[$latest],'missing same-text timestamp retains version');
