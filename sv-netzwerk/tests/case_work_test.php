<?php
declare(strict_types=1);
require __DIR__.'/../public/intern/api/case-work-core.php';
function krSenderProfile(array $user):array{return ['name'=>$user['full_name'],'email'=>$user['email']];}
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
$sender=cwSender(['full_name'=>'Christian Wächter','email'=>'cw@sv-schuett.eu']);
$requests=cwTaskRequests('Bitte abarbeiten',[],[['subject'=>'26-123456-1 Rechnung']]);
check(count($requests)===2&&$requests[1]['id']==='request-2','Task planning lost source task');
check($sender['email']==='cw@sv-netzwerk.eu','Wrong sender');
check(str_contains($sender['signature'],'Regulierer und Bausachverständiger'),'Missing role');
$history=cwHistoryContext([['instructions'=>'Bitte nur 1.500 EUR empfehlen','created_at'=>'2026-10-10','result_json'=>json_encode(['reply_draft'=>'Entwurf','assessment'=>'Bewertung','open_points'=>['Zahlung nicht bestätigt']])]]);
check($history[0]['role']==='operator'&&$history[1]['role']==='assistant_draft','Authority separation lost');
check(count(cwDocumentRequests(['document_requests'=>[['type'=>'zwischenbericht','instructions'=>'Nr. 3'],['type'=>'zwischenbericht','instructions'=>'Nr. 3 korrigiert']]]))===1,'Duplicate document type');
try{cwDocumentRequests(['document_requests'=>[['type'=>'send_mail','instructions'=>'Send now']]]);throw new LogicException('Forbidden tool accepted');}catch(RuntimeException $e){check(!$e instanceof LogicException,'Invalid action accepted');}
try{cwHistoryContext([['instructions'=>str_repeat('x',260000),'created_at'=>'today','result_json'=>null]]);throw new LogicException('Silent history truncation');}catch(RuntimeException $e){check(!$e instanceof LogicException,'Context limit failed');}
$previous=[['import_id'=>77],['import_id'=>88]];
check(cwImportId(['source_mode'=>'portal'],$previous)===0,'Mobile portal processing inherited an older import proof');
check(cwImportId(['source_mode'=>'claims','import_id'=>99],$previous)===99,'Explicit import lost its identity');
check(cwImportId([],$previous)===88,'Existing clients lost their history compatibility');
try{cwImportId(['source_mode'=>'portal','import_id'=>99],$previous);throw new LogicException('Contradictory source proof accepted');}catch(RuntimeException $e){check(!$e instanceof LogicException,'Source proof conflict accepted');}
try{cwImportId(['source_mode'=>'invented'],$previous);throw new LogicException('Invalid mode accepted');}catch(RuntimeException $e){check(!$e instanceof LogicException,'Invalid source mode accepted');}
echo "case_work_test: ok\n";
