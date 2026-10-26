<?php
declare(strict_types=1);
require_once __DIR__.'/../public/intern/mcp/native-forms.php';
$base=['request_json'=>'{"action":"create"}','status'=>'done','result_json'=>'{"verified":true,"formId":"new-draft"}'];
$cases=[[$base,false],[array_replace($base,['status'=>'running']),true],[array_replace($base,['status'=>'failed']),true],[array_replace($base,['result_json'=>'{"verified":false,"formId":"new-draft"}']),true],[array_replace($base,['result_json'=>'{"verified":true}']),true],[array_replace($base,['result_json'=>'invalid']),true],[array_replace($base,['request_json'=>'{"action":"inspect"}']),false]];
foreach($cases as [$row,$expected])if(mcpNativeCreateUnresolved($row)!==$expected)throw new RuntimeException('Creation retry guard failed');
echo "7 creation retry guards passed; synthetic records, no production writes.\n";
