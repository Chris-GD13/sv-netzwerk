<?php
declare(strict_types=1);
require_once __DIR__.'/assistant-fixture.php';
require_once __DIR__.'/../public/intern/api/case-work-assistant-provider.php';
require_once __DIR__.'/../public/intern/api/case-work-assistant-setup.php';
require_once __DIR__.'/../public/intern/api/case-work-assistant-access.php';
$n=0;function pcheck(bool $ok,string $why):void{global $n;if(!$ok)throw new RuntimeException($why);$n++;echo "PASS $why\n";}
function pfail(callable $fn,string $why):void{try{$fn();}catch(RuntimeException $e){pcheck($e->getMessage()===$why,$why);return;}throw new RuntimeException('Missing '.$why);}
$acl=new PDO('sqlite::memory:');$acl->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$acl->exec("CREATE TABLE users(id INTEGER PRIMARY KEY,email TEXT,role TEXT,is_active INT);CREATE TABLE case_folder_owners(folder_id TEXT,user_id INT,user_email TEXT);INSERT INTO users VALUES(1,'cw@synthetic.invalid','sachverstaendiger',1),(2,'jens@synthetic.invalid','sachverstaendiger',1);INSERT INTO case_folder_owners VALUES('own',1,'cw@synthetic.invalid'),('maurer',2,'jens@synthetic.invalid'),('other',3,'ms@synthetic.invalid')");
pcheck(svAssistantCaseAccess($acl,['id'=>1],'own')['id']===1,'fresh production ACL allows own case');
pcheck(svAssistantCaseAccess($acl,['id'=>1],'maurer')['id']===1,'existing Christian/Maurer delegation preserved');
pfail(fn()=>svAssistantCaseAccess($acl,['id'=>1],'other'),'CASE_FORBIDDEN');
pfail(fn()=>svAssistantCaseAccess($acl,['id'=>2],'maurer'),'CASE_FORBIDDEN');
$acl->exec('UPDATE users SET is_active=0 WHERE id=1');
pfail(fn()=>svAssistantCaseAccess($acl,['id'=>1],'own'),'USER_FORBIDDEN');
pfail(fn()=>svAssistantSyncMaster(['is_active'=>1,'email'=>'other@example.invalid']),'USER_FORBIDDEN');
$master='synthetic master';$r=assistantTestRelease();$r['source_hash']=hash('sha256',$master);foreach($r['rules'] as &$rule)$rule['sources']=[['id'=>'synthetic-master-id']];unset($rule);
pcheck(svAssistantValidateRelease($r,fn()=>$master)['master_text']===$master,'approved release pinned to fresh master bytes');
pfail(fn()=>svAssistantValidateRelease($r,fn()=>'changed'),'RULE_MASTER_CHANGED');
$r['status']='draft';pfail(fn()=>svAssistantValidateRelease($r,fn()=>$master),'RULE_RELEASE_UNAPPROVED');
final class PaidFake {
 public int $calls=0;public string $mode='ok';public ?Closure $during=null;
 public function maximum():int{return 1100000;}
 public function prepare(array $case,array $rules,string $action,string $instruction,string $master):array{if($this->mode==='prepare-fail')throw new RuntimeException('AI_SOURCE_FORMAT_UNSUPPORTED');return ['model'=>'gpt-6-luna','store'=>false,'service_tier'=>'default','max_output_tokens'=>8000,'input'=>[]];}
 public function invoke(array $payload,SvAiPaidPermit $permit):array{$permit->consume($payload);$this->calls++;if($this->during)($this->during)();if($this->mode==='unknown')return ['uncertain'=>true];if($this->mode==='rejected')return ['rejected'=>true,'http_status'=>401];return ['actual_micro'=>15000,'complete'=>true,'usage'=>['provider'=>'simulated-test','input_tokens'=>10000,'output_tokens'=>1000,'real_external_charge'=>false],'result'=>['answer'=>'Synthetischer KI-Entwurf','open_points'=>['Zahlung offen'],'rule_checks'=>['synthetic:1'],'source_checks'=>['invoice-a']]];}
}
$path=tempnam(sys_get_temp_dir(),'sv-paid-');$db=assistantTestDb($path);$cases=assistantTestCases();$fake=new PaidFake();$engine=new CaseWorkAssistant($db,assistantTestLoader($cases),assistantTestRelease(),'paid-session',null,$fake);$engine->schema();$u=assistantTestUser();$tab='paid-integration-tab1';
$ctx=$engine->open($u,$tab,'fixture-a','TEST-A-001');pcheck($ctx['mode']==='live','live mode declared');
$id=$engine->start($u,$ctx['token'],$tab,'paid-operation-0001','check','')['job_id'];pcheck($engine->budget()['reserved_micro']===1100000,'entire-context worst-case reserved before network');
$engine->step($u,$ctx['token'],$tab,$id);$done=$engine->step($u,$ctx['token'],$tab,$id);
pcheck($done['status']==='done'&&$done['result']['simulation']===false,'model result committed only as draft');
pcheck($engine->budget()['spent_micro']===15000&&$engine->budget()['reserved_micro']===0,'actual usage recorded with unused reservation released');
$engine->step($u,$ctx['token'],$tab,$id);pcheck($fake->calls===1,'completed paid step never sends twice');
$fake->mode='unknown';$id=$engine->start($u,$ctx['token'],$tab,'paid-operation-0002','check','')['job_id'];$engine->step($u,$ctx['token'],$tab,$id);$x=$engine->step($u,$ctx['token'],$tab,$id);
pcheck($x['status']==='unknown'&&$engine->budget()['reserved_micro']===1100000,'unknown holds full real-account reservation');
$engine->step($u,$ctx['token'],$tab,$id);pcheck($fake->calls===2,'unknown cannot retry');pfail(fn()=>$engine->control($u,$ctx['token'],$tab,$id,'cancel'),'TRANSITION_FORBIDDEN');
// Simulate operator reconciliation, not an HTTP permission.
$engine->reconcile($u,$ctx['token'],$tab,$id,10000,['provider'=>'simulated-reconciliation']);
$fake->mode='prepare-fail';$id=$engine->start($u,$ctx['token'],$tab,'paid-operation-0003','check','')['job_id'];$engine->step($u,$ctx['token'],$tab,$id);pcheck($engine->step($u,$ctx['token'],$tab,$id)['status']==='failed'&&$fake->calls===2,'preparation failure releases and never dispatches');
$fake->mode='rejected';$id=$engine->start($u,$ctx['token'],$tab,'paid-operation-0004','check','')['job_id'];$engine->step($u,$ctx['token'],$tab,$id);pcheck($engine->step($u,$ctx['token'],$tab,$id)['status']==='failed'&&$engine->budget()['reserved_micro']===0,'explicit provider rejection does not claim success');
$fake->mode='ok';$fake->during=function()use(&$cases){$cases['fixture-a']['revision']='changed';};
$id=$engine->start($u,$ctx['token'],$tab,'paid-operation-0005','report','')['job_id'];$engine->step($u,$ctx['token'],$tab,$id);$before=$engine->budget()['spent_micro'];$x=$engine->step($u,$ctx['token'],$tab,$id);
pcheck((svAssistantLatestRejection($db,(int)$u['id'])['http_status']??null)===401,'later reservation and completion retain previous rejection diagnostic');
pcheck(svAssistantLatestRejection($db,999999)===null,'rejection diagnostic never crosses users');
pcheck($x['status']==='failed'&&!isset($x['result']['answer'])&&$engine->budget()['spent_micro']===$before+15000,'case change during network discards answer but records usage');
pfail(fn()=>SvAiPaidPermit::issue($db,'unreserved',str_repeat('0',64)),'AI_DISPATCH_NOT_RESERVED');
// Actual provider payload: fixed endpoint/model, no tools, source hashes and unsupported formats.
$real=new SvAssistantProvider('synthetic-key',fn($id)=>['name'=>'test.txt','mime'=>'text/plain','bytes'=>'text']);$case=assistantTestCases()['fixture-b'];$case['documents']=[['id'=>'text','sha256'=>hash('sha256','text')]];
$payload=$real->prepare($case,[],'invoices','test','synthetic master');pcheck($payload['model']==='gpt-6-luna'&&$payload['store']===false&&!isset($payload['tools']),'fixed cheap model, no retention/tools');
$case['documents'][0]['sha256']=hash('sha256','other');pfail(fn()=>$real->prepare($case,[],'check','',''),'CASE_CHANGED');
unset($payload['_svnet_gaps']);
// Permit reuse/target/payload controls are checked before network; no curl_exec invoked.
$ledger=new SvAiBudgetLedger($db,'assistant');$op='permit-test';$hash=hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
$ledger->transaction(function()use($ledger,$op,$hash){$ledger->reserve($op,date('Y-m'),1100000);$ledger->dispatch($op,$hash);});
$permit=SvAiPaidPermit::issue($db,$op,$hash);$permit->consume($payload);pfail(fn()=>$permit->consume($payload),'AI_DISPATCH_INVALID');
$permit=SvAiPaidPermit::issue($db,$op,$hash);$bad=$payload;$bad['max_output_tokens']=9000;pfail(fn()=>$permit->consume($bad),'AI_DISPATCH_INVALID');
// Read past the API spreadsheet augmentation limit without executing formulas/macros.
if(!class_exists('ZipArchive'))throw new RuntimeException('Test needs zip extension');
$tmp=tempnam(sys_get_temp_dir(),'sv-xlsx-test-');$z=new ZipArchive();$z->open($tmp,ZipArchive::OVERWRITE);$rows='';for($i=1;$i<=1001;$i++)$rows.='<row r="'.$i.'"><c r="A'.$i.'"><v>'.$i.'</v></c></row>';
$z->addFromString('xl/worksheets/sheet1.xml','<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$rows.'</sheetData></worksheet>');$z->close();$sheet=file_get_contents($tmp);unlink($tmp);
pcheck(str_contains(svAssistantSpreadsheetText($sheet),'A1001: 1001'),'full local spreadsheet includes row 1001');
$reader=new SvAssistantProvider('synthetic-key',fn()=>['name'=>'demo.xlsm','mime'=>'application/vnd.ms-excel.sheet.macroEnabled.12','bytes'=>$sheet]);$case['documents'][0]['sha256']=hash('sha256',$sheet);
$p=$reader->prepare($case,[],'invoices','','');pcheck(str_contains(json_encode($p),'A1001: 1001')&&!str_contains(json_encode($p),'file_data'),'XLSM supplied as complete read-only text, not truncated provider sheet');
echo "TOTAL $n paid-adapter checks; all transports simulated, actual external cost zero\n";
