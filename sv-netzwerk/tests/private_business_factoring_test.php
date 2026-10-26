<?php
declare(strict_types=1);
require_once __DIR__.'/../public/intern/api/private-business-pdf.php';
require_once __DIR__.'/../public/intern/api/private-business-factoring.php';
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);pbSchema($db);
$r=pbSave($db,'marc',['request_key'=>pbUuid(),'data'=>['company'=>'Prüfkunde','billing_street'=>'Testweg 1','billing_postcode'=>'12345','billing_city'=>'Testort','scope'=>'Prüfung']]);
$before=pbDocuments($r);
$in=['customer_key'=>pbFactoringCustomerKey($r),'status'=>'confirmed','limit_cents'=>2500000,'available_cents'=>2200000,'source_url'=>'https://factoringplus.dv-portal.de/debtors/test'];
$f=pbFactoringSave($db,$r,$in);
if($f['stale']||$f['limit_cents']!==2500000||$f['available_cents']!==2200000)throw new RuntimeException('Cent-exact internal readback failed');
$read=pbRecord($db,$r['id'],'marc');
if($before!==pbDocuments($read)||isset($read['data']['factoring']))throw new RuntimeException('Internal limit leaked into document source');
$changed=pbSave($db,'marc',['id'=>$r['id'],'revision'=>$r['revision'],'request_key'=>pbUuid(),'data'=>array_replace($r['data'],['billing_street'=>'Anderer Weg 2'])]);
if(!pbFactoringRead($db,$changed)['stale'])throw new RuntimeException('Customer changes must invalidate the displayed limit');
try{pbFactoringSave($db,$r,$in);throw new LogicException('Concurrent edit ignored');}catch(RuntimeException $e){if($e->getCode()!==409)throw $e;}
foreach([['status'=>'pending'],['limit_cents'=>1.23],['source_url'=>'https://evil.example/debtors/test']] as $invalid){try{pbFactoringSave($db,$changed,array_replace($in,['customer_key'=>pbFactoringCustomerKey($changed)],$invalid));throw new LogicException('Invalid result accepted');}catch(RuntimeException $e){if($e->getCode()!==400)throw $e;}}
echo "private_business_factoring: ok (separate storage, cent amounts, stale customer protection, unchanged PDFs, invalid results rejected)\n";
