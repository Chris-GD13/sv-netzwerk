<?php
declare(strict_types=1);
// Disposable SQLite/filesystem fixtures only; no production configuration or network.
$api=__DIR__.'/../public/intern/api/';
$mode=$argv[1]??'main';
$base=$argv[2]??sys_get_temp_dir().'/svnet-storage-integrity-'.bin2hex(random_bytes(8));
if(!str_contains(str_replace('\\','/',$base),'/svnet-storage-integrity-'))throw new RuntimeException('Disposable store required');
putenv('IONOS_STORAGE_ROOT='.$base);putenv('PORTAL_STORAGE_BACKEND=ionos');
if($mode==='main'){
    mkdir($base);mkdir($base.'/objects');
    $seed=new PDO('sqlite:'.$base.'/index.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $seed->exec('CREATE TABLE items(id TEXT PRIMARY KEY,parent TEXT,name TEXT,mime TEXT,meta TEXT,path TEXT,verified INTEGER)');
    $q=$seed->prepare('INSERT INTO items VALUES(?,?,?,?,?,?,1)');
    $q->execute(['root','','root','application/vnd.google-apps.folder','{}','']);
}
require $api.'ionos-storage.php';
function check(bool $ok,string $name):void {if(!$ok)throw new RuntimeException($name);$GLOBALS['passed'][]=$name;}
function startChild(string $mode,string $base):array {
    $p=proc_open([PHP_BINARY,'-d','extension=pdo_sqlite',__FILE__,$mode,$base],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    if(!is_resource($p))throw new RuntimeException('Child process unavailable');fclose($pipes[0]);return [$p,$pipes];
}
function finishChild(array $child):array {[$p,$pipes]=$child;$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return [proc_close($p),$out,$err];}
function gdCaseCategory(string $name,string $mime):string {return 'Documents';}
function gdEnsureCaseFolder(string $id,string $category):array {return ionosItem($id);}
function gdListChildren(string $id,mixed $mime,int $limit):array {return ionosList(['q'=>"'".$id."' in parents and trashed = false",'pageSize'=>$limit])['files'];}
function gdDownload(string $id):string {return ionosBytes($id);}
function gdFindChildByName(string $id,string $name):?array {foreach(gdListChildren($id,null,1000)as$item)if($item['name']===$name)return $item;return null;}
function gdHttp(string $method,string $url,array $headers,mixed $body,bool $auth):array {return ionosDriveRequest($method,$url,$headers,$body)??throw new RuntimeException('Network forbidden');}
function apiError(int $status,string $message):never {throw new RuntimeException($message);}
// Load the real function in isolation; the HTTP/auth bootstrap is intentionally excluded.
$src=file_get_contents($api.'google-drive-sync.php');$a=strpos($src,'function gdUploadCaseFile(');
// Source uses real LF newlines.
$b=strpos($src,"\nfunction gd",$a+1);if($a===false||$b===false)throw new RuntimeException('Upload function unavailable');eval(substr($src,$a,$b-$a));
$ja=strpos($src,'function gdUploadJson(');$jb=strpos($src,'function gdUploadBytes(',$ja);eval(substr($src,$ja,$jb-$ja));
if($mode==='upload') {echo json_encode(gdUploadCaseFile('root','SE.pdf','application/pdf','same synthetic bytes',123));exit;}
if($mode==='source'){
    $trusted=['profile'=>'christian','caseId'=>'11111111-1111-4111-8111-111111111111','sourceFileId'=>'22222222-2222-4222-8222-222222222222','targetFolderId'=>'root','legacyInventoryResolved'=>true];
    echo json_encode(ionosStoreNewSource($trusted,$trusted,['name'=>'source.pdf','mimeType'=>'application/pdf','parents'=>['root']],'source bytes'));exit;
}
if($mode==='crash'){
    $db=ionosStorageDb();$db->sqliteCreateFunction('test_crash',static function(){exit(86);},0);
    $db->exec("CREATE TEMP TRIGGER injected_crash BEFORE INSERT ON items WHEN NEW.id='fixture' BEGIN SELECT test_crash(); END;");
    ionosWrite([],'crashed content','fixture');exit(99);
}
$passed=[];
$old=ionosWrite(['name'=>'fixture','mimeType'=>'application/pdf','parents'=>['root']],'old content');
// Stable fixture ID via controlled create contract.
ionosWrite(['name'=>'fixture','mimeType'=>'application/pdf','parents'=>['root']],'old content','fixture',['id'=>'fixture','allowCreate'=>true,'rowFingerprint'=>ionosRowFingerprint(null),'sha256'=>null]);
$db=ionosStorageDb();$db->exec("CREATE TEMP TRIGGER injected_failure BEFORE INSERT ON items WHEN NEW.id='fixture' BEGIN SELECT RAISE(FAIL,'TEST_INDEX_FAILURE'); END;");
try{ionosWrite([],'new content','fixture');throw new LogicException('Fault did not fire');}catch(PDOException $e){check(str_contains($e->getMessage(),'TEST_INDEX_FAILURE'),'index failure injected');}
check(ionosBytes('fixture')==='old content','caught failure restores old bytes');
check(!is_file(ionosIntentPath('fixture')),'caught failure completes journal');$db->exec('DROP TRIGGER injected_failure');
[$status]=finishChild(startChild('crash',$base));check($status===86,'process terminated after byte publication before index commit');
check(is_file(ionosIntentPath('fixture')),'crash leaves durable recovery journal');
check(ionosBytes('fixture')==='old content','first reader recovers precrash version');
check(!is_file(ionosIntentPath('fixture')),'reader completes crash journal');
$pre=['id'=>'fixture','rowFingerprint'=>ionosRowFingerprint(ionosRow('fixture')),'sha256'=>hash('sha256','old content')];
ionosWrite([],'latest content','fixture',$pre);
try{ionosWrite([],'stale content','fixture',$pre);throw new LogicException('Stale write accepted');}catch(RuntimeException $e){check($e->getMessage()==='SOURCE_VERSION_CONFLICT','stale original cannot overwrite newer version');}
check(ionosBytes('fixture')==='latest content','CAS preserves latest bytes');
file_put_contents($base.'/objects/fixture','tampered');
try{ionosBytes('fixture');throw new LogicException('Corrupt bytes accepted');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'Hash')||str_contains($e->getMessage(),'passen nicht'),'hash mismatch blocks corrupt read');}
file_put_contents($base.'/objects/fixture','latest content');
$one=gdUploadCaseFile('root','same.pdf','application/pdf','AAAA',123);$two=gdUploadCaseFile('root','same.pdf','application/pdf','BBBB',123);
check(!$one['duplicate']&&!$two['duplicate']&&$one['file']['id']!==$two['file']['id'],'same name size and mtime with changed bytes is retained');
$repeat=gdUploadCaseFile('root','same.pdf','application/pdf','BBBB',456);
check($repeat['duplicate']&&$repeat['file']['id']===$two['file']['id'],'same bytes different mtime reuses stored document');
$a=startChild('upload',$base);$b=startChild('upload',$base);[$sa,$oa,$ea]=finishChild($a);[$sb,$ob,$eb]=finishChild($b);
check($sa===0&&$sb===0,'parallel imports complete '.$ea.$eb);
$ra=json_decode($oa,true,512,JSON_THROW_ON_ERROR);$rb=json_decode($ob,true,512,JSON_THROW_ON_ERROR);
check($ra['file']['id']===$rb['file']['id']&&$ra['duplicate']!==$rb['duplicate'],'parallel identical imports have one stored ID');
check(count(glob($base.'/versions/fixture-*')?:[])>=2,'previous versions retained');
$metaFile=ionosWrite(['name'=>'00_Falldaten.json','mimeType'=>'application/json','parents'=>['root']],json_encode(['other'=>'old','claimsforce_notizen'=>[['id'=>'1','text'=>'fresh server note']]]));
gdUploadJson('root','00_Falldaten.json',['other'=>'new','claimsforce_notizen'=>[]]);
$meta=json_decode(ionosBytes($metaFile['id']),true,512,JSON_THROW_ON_ERROR);
check($meta['other']==='new'&&$meta['claimsforce_notizen']===[['id'=>'1','text'=>'fresh server note']],'generic stale case save preserves latest imported notes');
$a=startChild('source',$base);$b=startChild('source',$base);[$sa,$oa,$ea]=finishChild($a);[$sb,$ob,$eb]=finishChild($b);
check($sa===0&&$sb===0,'parallel source creation completes '.$ea.$eb);
$ra=json_decode($oa,true,512,JSON_THROW_ON_ERROR);$rb=json_decode($ob,true,512,JSON_THROW_ON_ERROR);
check($ra['file']['id']===$rb['file']['id']&&$ra['duplicate']!==$rb['duplicate'],'parallel source retries have one deterministic source ID');
// A journal with a different old index must block, preserving an unknown/newer file.
$before=ionosBytes('fixture');$row=ionosRow('fixture');$intent=['id'=>'fixture','operation'=>'unknown-operation','oldRowFingerprint'=>str_repeat('0',64),'oldSha256'=>hash('sha256','old content'),'newSha256'=>hash('sha256','crashed content'),'backup'=>null];
ionosDurableJson(ionosIntentPath('fixture'),$intent);
try{ionosRecoverPending('fixture');throw new LogicException('Unknown index accepted');}catch(RuntimeException $e){check(str_contains($e->getMessage(),'unbekannter Indexstand'),'unknown newer index blocks recovery');}
check(file_get_contents($base.'/objects/fixture')===$before&&ionosRow('fixture')===$row&&is_file(ionosIntentPath('fixture')),'blocked recovery preserves file index and evidence');
unlink(ionosIntentPath('fixture'));
echo json_encode(['passed'=>count($passed),'tests'=>$passed,'scope'=>'Synthetic local SQLite and actual PHP storage/upload functions; separate PHP workers; no network.'],JSON_PRETTY_PRINT),PHP_EOL;
