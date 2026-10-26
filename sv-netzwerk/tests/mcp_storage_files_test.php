<?php
declare(strict_types=1);
// Isolated real SQLite/object-store integration test. No credentials or network.
$testRoot=sys_get_temp_dir().'/svnet-mcp-files-'.bin2hex(random_bytes(6));
mkdir($testRoot);mkdir($testRoot.'/objects');
putenv('IONOS_STORAGE_ROOT='.$testRoot);putenv('PORTAL_STORAGE_BACKEND=ionos');
$db=new PDO('sqlite:'.$testRoot.'/index.sqlite');
$db->exec('CREATE TABLE items(id TEXT PRIMARY KEY,parent TEXT,name TEXT,mime TEXT,meta TEXT,path TEXT,verified INTEGER)');
$q=$db->prepare('INSERT INTO items VALUES(?,?,?,?,?,?,1)');
foreach(['root'=>'','case1'=>'root','case2'=>'root'] as $id=>$parent) $q->execute([$id,$parent,$id,'application/vnd.google-apps.folder',json_encode(['trashed'=>false]),'']);
function requireCaseFolderAccess(string $id,array $user):void { if($id!=='case'.$user['id'])throw new RuntimeException('Case access denied'); }
function ownCase(array $user,string $id):?array {return $id==='case'.$user['id']?['folder_id'=>$id]:null;}
require __DIR__.'/../public/intern/mcp/storage-files.php';
$u=['id'=>1,'role'=>'sachverstaendiger'];$v=['id'=>2,'role'=>'sachverstaendiger'];$n=0;
function check(bool $ok,string $label):void {global $n;if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.++$n.' '.$label.PHP_EOL;}
function denied(callable $f,string $label):void {try{$f();}catch(Throwable $e){check(true,$label);return;}check(false,$label);}
try {
 denied(fn()=>mcpStorageRead($u,['root'=>'case2','action'=>'list']),'foreign case blocked');
 denied(fn()=>mcpStorageWrite(['id'=>1,'role'=>'guest'],['action'=>'mkdir','name'=>'LOGO']),'unprivileged role blocked');
 $folder=mcpStorageWrite($u,['action'=>'mkdir','name'=>'LOGO']);$fid=$folder['item']['id'];
 check($folder['verified']===true,'create and read back folder');
 check(mcpStorageWrite($u,['action'=>'mkdir','name'=>'LOGO'])['item']['id']===$fid,'retry does not duplicate folder');
 $f=mcpStorageWrite($u,['action'=>'upload','parent_id'=>$fid,'name'=>'test.txt','text'=>'Grüße','mime_type'=>'text/plain']);$id=$f['item']['id'];
 $r=mcpStorageRead($u,['action'=>'read','id'=>$id]);
 check(base64_decode($r['content_base64'])==='Grüße','text bytes round trip');
 check($r['sha256']===hash('sha256','Grüße'),'hash read back');
 check(mcpStorageWrite($u,['action'=>'upload','parent_id'=>$fid,'name'=>'test.txt','text'=>'Grüße','mime_type'=>'text/plain'])['reused'],'identical upload idempotent');
 denied(fn()=>mcpStorageWrite($u,['action'=>'upload','parent_id'=>$fid,'name'=>'test.txt','text'=>'other','mime_type'=>'text/plain']),'different content not overwritten');
 denied(fn()=>mcpStorageWrite($u,['action'=>'upload','name'=>'../../.env','text'=>'x']),'path traversal blocked');
 denied(fn()=>mcpStorageWrite($u,['action'=>'upload','name'=>'.env','text'=>'x']),'hidden config name blocked');
 denied(fn()=>mcpStorageWrite($u,['action'=>'upload','name'=>'x','content_base64'=>'@@']),'invalid base64 blocked');
 denied(fn()=>mcpStorageWrite($u,['action'=>'upload','name'=>'x']),'missing content blocked');
 mcpStorageWrite($v,['action'=>'mkdir','name'=>'OTHER']);
 denied(fn()=>mcpStorageRead($v,['action'=>'read','id'=>$id]),'foreign library file blocked');
 denied(fn()=>mcpStorageWrite($u,['action'=>'upload','parent_id'=>'case2','name'=>'x','text'=>'x']),'foreign parent blocked');
 denied(fn()=>mcpStorageWrite($u,['action'=>'rename','id'=>$id,'name'=>'new.txt','expected_version'=>'stale']),'stale rename blocked');
 $r=mcpStorageWrite($u,['action'=>'rename','id'=>$id,'name'=>'new.txt','expected_version'=>$r['item']['version']]);
 check($r['item']['name']==='new.txt','rename verified');
 $r=mcpStorageWrite($u,['action'=>'move','id'=>$id,'expected_version'=>$r['item']['version']]);
 check($r['item']['parents'][0]===$folder['root_id'],'move verified');
 check(base64_decode(mcpStorageRead($u,['action'=>'read','id'=>$id])['content_base64'])==='Grüße','metadata operations preserve bytes');
 denied(fn()=>mcpStorageWrite($u,['action'=>'move','id'=>$folder['root_id'],'expected_version'=>'x']),'library root protected');
 $case=mcpStorageWrite($u,['root'=>'case1','action'=>'mkdir','name'=>'Berichte']);
 check($case['verified'],'own case permitted');
 $binary="\x89PNG\r\n\x00\xff";
 $png=mcpStorageWrite($u,['action'=>'upload','name'=>'test.png','mime_type'=>'image/png','content_base64'=>base64_encode($binary)]);
 check(base64_decode(mcpStorageRead($u,['action'=>'read','id'=>$png['item']['id']])['content_base64'])===$binary,'binary round trip');
 check(count(mcpStorageTools())===2,'read/write tools registered');
 echo "ALL $n TESTS PASSED\n";
} finally {
 // Only remove the uniquely created test directory, never configured production data.
 $db=null;
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testRoot,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
 foreach($it as $p){if($p->isDir())@rmdir($p->getPathname());else @unlink($p->getPathname());}
 @rmdir($testRoot);
}
