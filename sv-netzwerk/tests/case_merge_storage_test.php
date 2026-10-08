<?php
declare(strict_types=1);
require __DIR__.'/../public/intern/api/ionos-storage.php';
require __DIR__.'/../public/intern/api/case-search.php';
function check(bool$value,string$message):void{if(!$value)throw new RuntimeException($message);}
$fixture=sys_get_temp_dir().'/svnet-case-merge-'.bin2hex(random_bytes(8));mkdir($fixture);mkdir($fixture.'/objects');
putenv('IONOS_STORAGE_ROOT='.$fixture);putenv('PORTAL_STORAGE_BACKEND=ionos');
$seed=new PDO('sqlite:'.$fixture.'/index.sqlite');$seed->exec('CREATE TABLE items(id TEXT PRIMARY KEY,parent TEXT,name TEXT,mime TEXT,meta TEXT,object_path TEXT,verified INTEGER)');
$root=['id'=>'root','name'=>'Root','mimeType'=>'application/vnd.google-apps.folder','parents'=>[]];
$s=$seed->prepare('INSERT INTO items VALUES(?,?,?,?,?,?,1)');$s->execute(['root','','Root',$root['mimeType'],json_encode($root),'']);
function gdUserKey(array$user):string{return$user['email'];}
function gdUserCasesRoot(array$user):array{return['id'=>$user['email']];}
function gdFileMeta(string$id):array{return ionosItem($id);}
function gdListChildren(string$id,?string$mime=null,int$limit=1000):array{$s=ionosStorageDb()->prepare('SELECT id FROM items WHERE parent=? AND verified=1');$s->execute([$id]);return array_values(array_filter(array_map(fn($r)=>ionosItem($r['id']),$s->fetchAll()),fn($r)=>$mime===null||$r['mimeType']===$mime));}
function gdCaseMeta(string$id):array{foreach(gdListChildren($id)as$f)if($f['name']===CASE_META_NAME)return json_decode(ionosBytes($f['id']),true);return[];}
function gdAssertCaseAccess(string$id,array$user):void{if(!in_array($user['email'],ionosItem($id)['parents'],true))throw new RuntimeException('Forbidden');}
function gdUploadJson(string$id,string$name,array$data):array{$existing=null;foreach(gdListChildren($id)as$f)if($f['name']===$name)$existing=$f['id'];return ionosWrite(['name'=>$name,'parents'=>[$id],'mimeType'=>'application/json'],json_encode($data),$existing);}
function registerCaseFolderOwner(string$id,array$user,array$meta):void{}
function db():object{return new class{function prepare(string$sql):object{return new class{function execute(array$args):void{}function fetchColumn():int{return 1;}};}};}
const CASE_META_NAME='00_Falldaten.json';
foreach(['christian','marc']as$id)ionosWrite(['name'=>$id,'parents'=>['root'],'mimeType'=>$root['mimeType']]);
// Resolve generated profile root IDs explicitly; IDs and object bytes survive the merge.
$profiles=gdListChildren('root');$profile=$profiles[0]['id'];
$target=ionosWrite(['name'=>'24-171720-9','parents'=>[$profile],'mimeType'=>$root['mimeType']]);
$source=ionosWrite(['name'=>'24-1717209','parents'=>[$profile],'mimeType'=>$root['mimeType']]);
$a=['schaden_nr'=>'24-171720-9','vn_objekt'=>'Klaus Droxler','versicherungsschein_nr'=>'50105410908','schadenart'=>'Elementar','email'=>'test@example.test'];
$b=['schaden_nr'=>'24-1717209','vn_objekt'=>'Klaus Droxler','schadenart'=>'Rechnung','quelle'=>'Outlook','reserve'=>'2683.91'];
gdUploadJson($target['id'],CASE_META_NAME,$a);gdUploadJson($source['id'],CASE_META_NAME,$b);
$category=ionosWrite(['name'=>'04_Rechnungen_KVA','parents'=>[$source['id']],'mimeType'=>$root['mimeType']]);
$file=ionosWrite(['name'=>'invoice.pdf','parents'=>[$category['id']],'mimeType'=>'application/pdf'],'unchanged document bytes');
$user=['email'=>$profile];check(count(caseIdentityFind($user,'241717209'))===2,'Find both variants in IONOS index');$result=caseIdentityMerge($user,['target_id'=>$target['id'],'source_id'=>$source['id']]);
check(count(caseIdentityFind($user,'24-1717209'))===1,'One canonical identity after merge');
check(caseIdentityResolve($source['id'])===$target['id'],'Old ID redirects');
check(count(gdListChildren($profile,$root['mimeType']))===1,'Only one root case');
check(ionosBytes($file['id'])==='unchanged document bytes','Document bytes retained');
check(ionosItem($file['id'])['parents']===[$category['id']],'File IDs and paths retained');
check($result['meta']['email']===$a['email']&&$result['meta']['quelle']==='Outlook','Metadata combined');
check($result['meta']['schadenart']==='Elementar'&&$result['conflicts']['schadenart']['source']==='Rechnung','Conflict retained in audit');
check(gdCaseMeta($source['id'])===$b,'Original source metadata retained');
check(caseIdentityMerge($user,['target_id'=>$target['id'],'source_id'=>$source['id']])['already_merged'],'Retry idempotent');
try{caseIdentityMerge(['email'=>$profiles[1]['id']],['target_id'=>$target['id'],'source_id'=>$source['id']]);throw new LogicException('Cross profile merge accepted');}catch(RuntimeException$e){check($e->getMessage()==='Forbidden','Cross profile blocked');}
echo "IONOS merge storage regression tests passed\n";
