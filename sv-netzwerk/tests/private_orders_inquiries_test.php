<?php
declare(strict_types=1);
require_once __DIR__.'/../public/intern/api/private-order-classification.php';
require_once __DIR__.'/../public/intern/api/private-order-inquiries.php';
$items = [
    'root'=>['id'=>'root','name'=>'Private','folder'=>['childCount'=>3]],
    'year'=>['id'=>'year','name'=>'2026','folder'=>['childCount'=>3],'parentReference'=>['id'=>'root']],
    'inquiry'=>['id'=>'inquiry','name'=>'Anfrage_Kunde_Ort','folder'=>['childCount'=>10],'parentReference'=>['id'=>'year']],
    'order'=>['id'=>'order','name'=>'Kunde_Ort','folder'=>['childCount'=>10],'parentReference'=>['id'=>'year']],
    'outside'=>['id'=>'outside','name'=>'Fremder Auftrag','folder'=>['childCount'=>1]],
    'archive'=>['id'=>'archive','name'=>'Sonstige Unterlagen','folder'=>['childCount'=>1],'parentReference'=>['id'=>'order']],
];
$writes=[];
$items['legacy']=['id'=>'legacy','name'=>'Gutachtenfälle','folder'=>['childCount'=>1],'parentReference'=>['id'=>'root']];
$items['oldyear']=['id'=>'oldyear','name'=>'2020 und älter','folder'=>['childCount'=>1],'parentReference'=>['id'=>'legacy']];
$items['oldorder']=['id'=>'oldorder','name'=>'Alter Kunde','folder'=>['childCount'=>1],'parentReference'=>['id'=>'oldyear']];
function apiError(int $code,string $message): never { throw new RuntimeException($message,$code); }
function poDriveId(): string {return 'drive';}
function poStandardFolders(): array {return ['Auftrag','Sonstige Unterlagen','E-Mails'];}
function poIsYearFolder(array $item): bool {return preg_match('/^20\d{2}$/',$item['name'])===1;}
function poIsStandardFolder(array $item): bool {return in_array($item['name'],poStandardFolders(),true);}
function poRequest(string $url): array {global $items;preg_match('~/items/([^?]+)~',$url,$m);return $items[urldecode($m[1])]??throw new RuntimeException('Unexpected request');}
function poChildren(string $id): array {global $items;return array_values(array_filter($items,fn($x)=>($x['parentReference']['id']??'')===$id));}
function poRequestJson(string $method,string $url,array $payload): array {global $items,$writes;$writes[]=[$method,$url,$payload];preg_match('~/items/([^/?]+)~',$url,$m);$id=urldecode($m[1]);if($method==='PATCH'){$items[$id]=array_replace_recursive($items[$id],$payload);return $items[$id];}throw new RuntimeException('Unexpected write');}
function check(bool $value,string $message): void {if(!$value)throw new RuntimeException($message);}
check(poIsInquiryName('Anfrage_Kunde')&&!poIsInquiryName('Anfragebogen GmbH'),'Inquiry prefix must be bounded');
check(poValidateTopOrder('oldorder','root')['id']==='oldorder','Nested archive case must remain accessible');
try{poValidateTopOrder('legacy','root');throw new RuntimeException('Archive container treated as customer');}catch(RuntimeException $e){check($e->getCode()===403,'Archive container must never be organized as a case');}
$original=$items['inquiry'];
$result=poConvertInquiry('inquiry','Kunde_Ort_2','root');
check($result['order_id']==='inquiry'&&count($writes)===1,'Convert must reuse the original ID');
check(!isset($writes[0][2]['parentReference']),'Convert must retain the storage location');
poConvertInquiry('inquiry','Kunde_Ort_2','root');check(count($writes)===1,'Convert retry must be idempotent');
$items['inquiry']=$original;$writes=[];
$result=poMergeInquiry('inquiry','order','root');
check($result['order_id']==='order'&&count($writes)===1,'Merge must make one atomic move');
check($items['inquiry']['parentReference']['id']==='archive','Merge must enter the selected order');
check($items['inquiry']['folder']===$original['folder'],'Merge must preserve all children');
check($writes[0][2]['@microsoft.graph.conflictBehavior']==='fail','Existing content must never be overwritten');
poMergeInquiry('inquiry','order','root');check(count($writes)===1,'Merge retry must be idempotent');
foreach([fn()=>poMergeInquiry('outside','order','root'),fn()=>poMergeInquiry('order','order','root'),fn()=>poConvertInquiry('archive','New','root')] as $attempt){$before=count($writes);try{$attempt();throw new RuntimeException('Invalid target accepted');}catch(RuntimeException $e){check(in_array($e->getCode(),[400,403],true),'Expected a scope/selection error');}check(count($writes)===$before,'Rejected requests must not mutate storage');}
echo "private_orders_inquiries_test: ok (conversion, atomic merge, retry, scope, preserved children)\n";

function poRecycleItem(string $id,string $tag): void {global $writes;$writes[]=['RECYCLE',$id,$tag];}
$items['inquiry']=$original;$items['inquiry']['eTag']='version-one';$writes=[];
poDeleteInquiry('inquiry','root');
check($writes===[['RECYCLE','inquiry','version-one']],'Delete must recycle only the scoped inquiry with concurrency protection');
foreach(['order','root','outside','archive','legacy'] as $id){$before=count($writes);try{poDeleteInquiry($id,'root');throw new RuntimeException('Invalid delete accepted');}catch(RuntimeException $e){check(in_array($e->getCode(),[400,403,409],true),'Expected delete boundary rejection');}check(count($writes)===$before,'Rejected delete must not mutate storage');}
echo "private_orders_delete_test: ok (inquiry only, scope, recycle, concurrency)\n";
