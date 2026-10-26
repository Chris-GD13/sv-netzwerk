<?php
declare(strict_types=1);
require __DIR__.'/../public/intern/mcp/claims-source.php';
function check(bool $ok): void {if(!$ok)throw new RuntimeException('assertion failed');}
$claim='11111111-1111-4111-8111-111111111111';$source='22222222-2222-4222-8222-222222222222';
check(mcpSourceKey($claim,'message',$source)===$claim.':message:'.$source);
foreach([[$claim,'messages',$source],[$claim,'document','../file'],[$claim,'bad','']] as $args){try{mcpSourceKey(...$args);throw new RuntimeException('must reject');}catch(InvalidArgumentException $expected){}}
$result=['source'=>'claimsforce-source-read','sourceProfile'=>'christian','claimId'=>$claim,'kind'=>'message','sourceId'=>$source,'fetchedAt'=>gmdate('c'),'readOnly'=>true,'message'=>['id'=>$source,'body'=>'test']];
$row=['id'=>1,'status'=>'done','result_json'=>json_encode($result)];
check(mcpSourceSnapshot($row,'christian',$claim,'message',$source)['available']);
$inventory=[['id'=>$source,'name'=>'invoice.pdf','contentRead'=>false]];
$mailSnapshot=['available'=>true,'result'=>$result+['documents'=>$inventory,'attachmentMetadataStatus'=>'available']];
$mailSnapshot['result']['message']['attachments']=$inventory;
$mailOutput=json_decode(mcpSourceContent($mailSnapshot)[0]['text'],true);
check(!array_key_exists('documents',$mailOutput['result']));
check($mailOutput['result']['message']===$mailSnapshot['result']['message']);
check($mailOutput['result']['attachmentMetadataStatus']==='available');
$listSnapshot=$mailSnapshot;$listSnapshot['result']['kind']='messages';
check(json_decode(mcpSourceContent($listSnapshot)[0]['text'],true)['result']['documents']===$inventory);
check($mailSnapshot['result']['documents']===$inventory);
check(!mcpSourceSnapshot($row,'jens',$claim,'message',$source)['available']);
check(!mcpSourceSnapshot($row,'christian',$source,'message',$source)['available']);
check(!mcpSourceSnapshot(array_replace($row,['status'=>'running']),'christian',$claim,'message',$source)['available']);
check(mcpSourceSnapshot(array_replace($row,['status'=>'failed','message'=>'SOURCE_ENDPOINT_INVALID']),'christian',$claim,'message',$source)['reason']==='SOURCE_READ_FAILED');
check(mcpSourceSnapshot(array_replace($row,['status'=>'failed','message'=>'SOURCE_ENDPOINT_INVALID']),'christian',$claim,'message',$source)['detail']==='SOURCE_ENDPOINT_INVALID');
$result['fetchedAt']=gmdate('c',time()-600);$row['result_json']=json_encode($result);
check(mcpSourceSnapshot($row,'christian',$claim,'message',$source)['stale']);
try{mcpSourceContent(['result'=>['kind'=>'document','base64'=>'YWJj','sha256'=>str_repeat('0',64)]]);throw new RuntimeException('hash must fail');}catch(RuntimeException $e){check($e->getMessage()==='SOURCE_DOCUMENT_HASH_MISMATCH');}
echo "PASS: request validation, profile/claim/source isolation, pending/stale distinction, original byte integrity\n";
