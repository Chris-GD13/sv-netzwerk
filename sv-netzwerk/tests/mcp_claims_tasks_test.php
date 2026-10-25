<?php
require_once __DIR__.'/../public/intern/mcp/claims-tasks.php';
function expectTask(bool $ok,string $name): void { if (!$ok) throw new RuntimeException($name); echo "PASS $name\n"; }
$payload=['source'=>'claimsforce-tasks-api','sourceProfile'=>'christian','tasks'=>[['id'=>'task-1','claimId'=>'claim-1']],'fetchedAt'=>gmdate('c'),'openTasks'=>1];
$row=['id'=>12,'status'=>'done','result_json'=>json_encode($payload)];
expectTask(mcpClaimsTaskSnapshot($row,'christian')['tasks'][0]['id']==='task-1','actual task retained');
expectTask(!mcpClaimsTaskSnapshot($row,'marc')['available'],'foreign profile blocked');
expectTask(!mcpClaimsTaskSnapshot(array_replace($row,['status'=>'failed']),'christian')['available'],'failed read not accepted');
expectTask(!mcpClaimsTaskSnapshot(['status'=>'done','result_json'=>'{"openTasks":1}'],'christian')['available'],'counter alone is not a task list');
$payload['fetchedAt']='2020-01-01T00:00:00Z';$row['result_json']=json_encode($payload);
expectTask(mcpClaimsTaskSnapshot($row,'christian')['stale'],'old source marked stale');
