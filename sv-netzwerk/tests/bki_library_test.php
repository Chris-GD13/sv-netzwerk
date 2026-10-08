<?php
declare(strict_types=1);
require_once __DIR__.'/../public/intern/api/bki-library.php';
$root=sys_get_temp_dir().'/bki-test-'.bin2hex(random_bytes(4));mkdir($root);putenv('IONOS_STORAGE_ROOT='.$root);
$bytes='%PDF-1.4 example';$sha=hash('sha256',$bytes);file_put_contents(bklRoot().'/'.$sha.'.pdf',$bytes);
$data=['schema'=>1,'documents'=>[['id'=>$sha,'name'=>'Fixture.pdf','kind'=>'positions','sha256'=>$sha,'size'=>strlen($bytes),'pages'=>[['page'=>1,'text'=>'Synthetic fixture 100.000.001']]]],'positions'=>[['id'=>$sha.':100.000.001','document_id'=>$sha,'source_page'=>1,'position_code'=>'100.000.001','description'=>'Abwasserleitung Beispiel','scope'=>'Rohrleitung Montage','price_mid'=>10,'inherited'=>[]]]];
$path=$root.'/input.json';file_put_contents($path,json_encode($data));$result=bklImport($path);
if($result['positions']!==1||!$result['documents'][0]['on_ionos']||count(bklSearch('Abwasserleitung'))!==1)throw new RuntimeException('Index oder Suche fehlt.');
$data['documents'][0]['sha256']=str_repeat('a',64);file_put_contents($path,json_encode($data));
$failed=false;try{bklImport($path);}catch(Throwable){$failed=true;}if(!$failed||bklStatus()['positions']!==1)throw new RuntimeException('Ungeprüfter Import oder beschädigter Bestand.');
echo "Private library import, search, original binding and rollback passed.\n";
