<?php
declare(strict_types=1);
require_once __DIR__.'/assistant-fixture.php';
$count=0;
function ok(bool $condition,string $label):void {global $count;if(!$condition)throw new RuntimeException($label);$count++;echo "PASS $label\n";}
function denied(callable $fn,string $why):void {try{$fn();}catch(RuntimeException $e){ok($e->getMessage()===$why,$why);return;}throw new RuntimeException('Missing '.$why);}
putenv('SVNET_AI_TRANSPORT_MODE');
foreach(svAiLegacyTransports() as $id){
 $h=curl_init('https://api.openai.com/v1/responses');
 ok(svAiGuardHandle($h,$id)===$h,'legacy handle preserved: '.$id);
 curl_close($h);
 denied(fn()=>svAiTransportDecision('https://api.openai.com/v1/files',$id,'assistant'),'PAID_AI_DISABLED');
 denied(fn()=>svAiTransportDecision('https://api.openai.com/v1/files',$id,'simulation'),'PAID_AI_DISABLED');
}
denied(fn()=>svAiTransportDecision('https://proxy.invalid','kva-http','assistant'),'PAID_AI_DISABLED');
denied(fn()=>svAiTransportDecision('https://api.openai.com','unlisted','legacy'),'AI_TRANSPORT_UNREGISTERED');
putenv('SVNET_AI_TRANSPORT_MODE=deny');
denied(fn()=>svAiGuardHandle(curl_init('https://api.openai.com/v1/files'),'kva-http'),'PAID_AI_DISABLED');
ok(svAiTransportDecision('https://www.googleapis.com/drive/v3','kva-http','legacy')['allowed'],'Drive/Graph helper unaffected by provider deny');
putenv('SVNET_AI_TRANSPORT_MODE');
$db=assistantTestDb(tempnam(sys_get_temp_dir(),'p3budget-'));
$mock=new SvAiBudgetLedger($db,'simulation');$real=new SvAiBudgetLedger($db,'assistant');
$mock->initializeMutex();$real->schema();$month='2026-10';
denied(fn()=>$real->reserve('unlocked',$month,1),'AI_TRANSACTION_REQUIRED');
$mock->transaction(fn()=>$mock->reserve('mock-full',$month,30000000));
$real->transaction(fn()=>$real->reserve('real-1',$month,20000000));
ok($real->budget($month)['reserved_micro']===20000000,'real account independent of exhausted mock');
denied(fn()=>$real->transaction(fn()=>$real->reserve('real-over',$month,10000001)),'MONTHLY_BUDGET_EXCEEDED');
$real->transaction(fn()=>$real->reserve('real-2',$month,10000000));
ok($real->budget($month)['reserved_micro']===30000000,'global real boundary exactly thirty EUR');
ok(svAiBudgetPolicy('legacy')['limit_micro']===null,'legacy not silently charged to assistant');
$real->transaction(fn()=>$real->settle('real-1',19000000,['provider'=>'synthetic-test']));
ok($real->budget($month)['spent_micro']===19000000&&$real->budget($month)['reserved_micro']===10000000,'actual usage settles original account');
// Raising a stored limit cannot defeat the hard application cap.
$db->exec("UPDATE case_work_ai_budget SET limit_micro=90000000 WHERE month_key='2026-10'");
denied(fn()=>$real->transaction(fn()=>$real->reserve('real-over-again',$month,1000001)),'MONTHLY_BUDGET_EXCEEDED');
ok($real->budget($month)['limit_micro']===30000000,'displayed effective hard cap');
$mock->transaction(fn()=>$mock->settle('mock-full',100000,['real_charge_micro'=>999]));
$usage=json_decode($db->query("SELECT usage_json FROM case_work_usage WHERE operation_id='mock-full'")->fetchColumn(),true);
ok($usage['budget_lane']==='simulation'&&$usage['real_charge_micro']===0,'simulation explicitly records no real fee');
// Real two-user queue coexistence, profile boundaries, stale response, crash reopen.
$cases=assistantTestCases();$release=assistantTestRelease();$path=tempnam(sys_get_temp_dir(),'p3users-');$queue=assistantTestDb($path);
$a=new CaseWorkAssistant($queue,assistantTestLoader($cases),$release,'user-one');$a->schema();
$b=new CaseWorkAssistant($queue,assistantTestLoader($cases),$release,'user-two');
$u=assistantTestUser();$v=assistantTestUser(2);$tab='integration-tab-0001';
$ca=$a->open($u,$tab,'fixture-a','TEST-A-001');$cb=$b->open($v,$tab,'fixture-c','TEST-C-003');
$ja=$a->start($u,$ca['token'],$tab,'integration-one-0001','check','')['job_id'];
$jb=$b->start($v,$cb['token'],$tab,'integration-two-0001','tasks','')['job_id'];
ok($ja!==$jb,'different users keep distinct simultaneous jobs');
$a->step($u,$ca['token'],$tab,$ja);$b->step($v,$cb['token'],$tab,$jb);
$a->open($u,$tab,'fixture-b','TEST-B-002');
denied(fn()=>$a->step($u,$ca['token'],$tab,$ja),'CONTEXT_INVALID');
ok($b->step($v,$cb['token'],$tab,$jb)['status']==='done','one user switching does not cancel other user');
denied(fn()=>$b->open($v,$tab,'fixture-a','TEST-A-001'),'CASE_FORBIDDEN');
$resumed=new CaseWorkAssistant(assistantTestDb($path),assistantTestLoader($cases),$release,'user-one');
$fresh=$resumed->open($u,$tab,'fixture-a','TEST-A-001');$resumed->recover($u,$fresh['token'],$tab,$ja);
ok($resumed->step($u,$fresh['token'],$tab,$ja)['status']==='done','running job recovered after connection reopen');
// Request scope cannot disguise itself with a registered legacy transport ID.
define('SVNET_ASSISTANT_REQUEST',true);
denied(fn()=>svAiGuardHandle(curl_init('https://api.openai.com/v1/responses'),'kva-http'),'PAID_AI_DISABLED');
echo "TOTAL $count integration checks; no curl_exec/network invoked\n";
