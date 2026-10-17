<?php
declare(strict_types=1);
require_once __DIR__.'/assistant-fixture.php';
[$script,$path,$index]=$argv;
$cases=assistantTestCases();$folder='race-'.$index;$cases[$folder]=array_merge($cases['fixture-a'],['folder_id'=>$folder]);
$engine=new CaseWorkAssistant(assistantTestDb($path),assistantTestLoader($cases),assistantTestRelease(),'race-session');
try{$c=$engine->open(assistantTestUser(),'race-tab-00000000'.$index,$folder,'TEST-A-001');$r=$engine->start(assistantTestUser(),$c['token'],'race-tab-00000000'.$index,'race-operation-000000'.$index,'check','','assistant',20000000);echo json_encode($r);}
catch(RuntimeException $e){if($e->getMessage()!=='MONTHLY_BUDGET_EXCEEDED')throw $e;echo 'BUDGET_BLOCKED';}
