<?php
declare(strict_types=1);
require_once __DIR__.'/../public/intern/mcp/native-forms.php';
function check(bool $condition,string $message):void {if(!$condition)throw new RuntimeException($message);}
$time=0.0;$reads=0;$sleeps=0;
$clock=static function()use(&$time){return $time;};
$sleep=static function()use(&$time,&$sleeps){$time+=0.25;$sleeps++;};
$job=mcpNativeAwaitJob(static function()use(&$reads){return ['id'=>7,'status'=>++$reads<4?'running':'done'];},$clock,$sleep);
check($job['status']==='done'&&$reads===4&&$sleeps===3,'waits for same job completion');
$time=0;$reads=0;$sleeps=0;
$job=mcpNativeAwaitJob(static function()use(&$reads){$reads++;return ['id'=>7,'status'=>'running'];},$clock,$sleep);
check($time===8.0&&$reads===33&&$job['status']==='running','bounded eight second wait, no false completion');
$reads=0;$sleeps=0;
$job=mcpNativeAwaitJob(static function()use(&$reads){$reads++;return ['id'=>7,'status'=>'failed'];},$clock,$sleep);
check($reads===1&&$sleeps===0&&$job['status']==='failed','failure returned immediately');
try {mcpNativeAwaitJob(static fn()=>false,$clock,$sleep);throw new RuntimeException('missing job accepted');}
catch(RuntimeException $error){check($error->getMessage()==='NATIVE_JOB_NOT_FOUND','missing job fails closed');}
echo "4 native poll tests passed; synthetic clock and job reader, no production writes.\n";
