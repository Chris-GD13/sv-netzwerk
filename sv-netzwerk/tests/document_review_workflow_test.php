<?php
declare(strict_types=1);
// Exercise the real dispatcher against an isolated IONOS SQLite/object store.
// Authentication and external transports are stubs; no real claims or mailboxes are touched.
require_once __DIR__.'/../public/intern/api/ionos-storage.php';
require_once __DIR__.'/../public/intern/api/document-review-core.php';
require_once __DIR__.'/../public/intern/api/document-review-storage.php';
$temp=sys_get_temp_dir().'/svnet-review-test-'.bin2hex(random_bytes(8));
mkdir($temp,0700);mkdir($temp.'/objects',0700);putenv('IONOS_STORAGE_ROOT='.$temp);putenv('PORTAL_STORAGE_BACKEND=ionos');
$database=new PDO('sqlite:'.$temp.'/index.sqlite');
$database->exec('CREATE TABLE items(id TEXT PRIMARY KEY,parent TEXT,name TEXT,mime TEXT,meta TEXT,path TEXT,verified INTEGER)');
$database->exec('CREATE TABLE case_folder_owners(folder_id TEXT,case_no TEXT)');
$meta=json_encode(['id'=>'test-root','name'=>'Isolated test','mimeType'=>'application/vnd.google-apps.folder','parents'=>[],'trashed'=>false]);
$stmt=$database->prepare('INSERT INTO items VALUES(?,?,?,?,?,?,1)');$stmt->execute(['test-root','','Isolated test','application/vnd.google-apps.folder',$meta,'']);
$folder=ionosWrite(['name'=>'QA Review','mimeType'=>'application/vnd.google-apps.folder','parents'=>['test-root']])['id'];
$stmt=$database->prepare('INSERT INTO case_folder_owners VALUES(?,?)');$stmt->execute([$folder,'QA-NO-CLAIM']);
$sourceBytes="%PDF-1.4\nORIGINAL Rechnung mit Centbeträgen";
$file=ionosWrite(['name'=>'Original.pdf','mimeType'=>'application/pdf','parents'=>[$folder]],$sourceBytes)['id'];
$request=[];$calls=[];$transportFail=false;$prepared=null;
class ReviewResponse extends RuntimeException {public function __construct(public array $data,public int $status=200){parent::__construct('API response');}}
function apiJson(mixed $value,int $status=200):never {throw new ReviewResponse($value,$status);}
function apiError(int $status,string $error):never {throw new ReviewResponse(['ok'=>false,'error'=>$error],$status);}
function requestBody():array {return $GLOBALS['request'];}
function db():PDO {return $GLOBALS['database'];}
function env(string $key,string $default=''):string {return $default;}
function requireCaseFolderAccess(string $folder,array $user):void {if($folder!==$GLOBALS['folder'])apiError(403,'No access');}
function krSenderProfile(array $user):array {return ['email'=>'test@example.org','name'=>'QA Test'];}
function krCaseNo(string $folder):string {return 'QA-NO-CLAIM';}
function krList(string $folder):array {$result=[];foreach(drChildren($folder)as$item){if($item['mimeType']==='application/vnd.google-apps.folder')$result=array_merge($result,krList($item['id']));else$result[]=$item;}return$result;}
function krSelected(string $folder,string $id):array {foreach(krList($folder)as$item)if($item['id']===$id)return['name'=>$item['name'],'mime'=>$item['mimeType'],'bytes'=>ionosBytes($id)];throw new RuntimeException('Wrong case/file');}
function krSign(array $value):string {return base64_encode(json_encode($value));}
function krVerify(string $value):array {return json_decode(base64_decode($value),true,512,JSON_THROW_ON_ERROR);}
function krMs():string {return 'TEST-TRANSPORT';}
function krHttp(string $method,string $url,array $headers=[],mixed $body=null,int $timeout=240):array {
    $GLOBALS['calls'][]=['method'=>$method,'url'=>$url,'body'=>$body];
    if($GLOBALS['transportFail'])throw new RuntimeException('Simulated network ambiguity');
    if(($GLOBALS['draftFail']??false)&&str_ends_with($url,'/messages'))return['status'=>404,'body'=>'{"error":{"code":"ErrorInvalidUser"}}'];
    if(str_ends_with($url,'/sendMail')||str_ends_with($url,'/send'))return['status'=>202,'body'=>''];
    if(str_ends_with($url,'/createUploadSession'))return['status'=>201,'body'=>'{"uploadUrl":"https://test.invalid/upload"}'];
    if($url==='https://test.invalid/upload'){
        $range=implode(' ',$headers);preg_match('/Content-Range: bytes (\d+)-(\d+)\/(\d+)/',$range,$m);
        return['status'=>((int)$m[2]+1===(int)$m[3]?201:202),'body'=>'{}'];
    }
    if(str_ends_with($url,'/messages'))return['status'=>201,'body'=>'{"id":"isolated-draft"}'];
    if(str_ends_with($url,'/attachments'))return['status'=>201,'body'=>'{"id":"attachment"}'];
    throw new RuntimeException('Unexpected external request');
}
$entry=str_replace("\r\n","\n",file_get_contents(__DIR__.'/../public/intern/api/document-review.php'));
$start=strpos($entry,'function drChildren(');$main=strpos($entry,"\ntry {\n    if (!ionosStorageEnabled())");
eval(substr($entry,$start,$main-$start));
$dispatcher=substr($entry,$main);
$dispatcher=str_replace('catch (Throwable $error) { apiError(400,$error->getMessage()); }','catch (Throwable $error) { if ($error instanceof ReviewResponse) throw $error; apiError(400,$error->getMessage()); }',$dispatcher);
function callReview(string $action,array $data):array {
    $GLOBALS['request']=$data;$_GET=['action'=>$action];$_SERVER=['REQUEST_METHOD'=>'POST','HTTP_HOST'=>'test.invalid','HTTP_ORIGIN'=>'https://test.invalid'];$user=['id'=>17];$GLOBALS['user']=$user;
    try {eval($GLOBALS['dispatcher']);}catch(ReviewResponse $response){return['status'=>$response->status]+$response->data;}
    throw new RuntimeException('No response');
}
function expectReview(bool $condition,string $message):void {if(!$condition)throw new RuntimeException($message);}
$prepared=callReview('prepare',['folder_id'=>$folder,'file_id'=>$file,'kind'=>'invoice','mode'=>'direct']);
expectReview($prepared['ok']===true&&count($calls)===0,'Direct mode never calls AI or Graph');
$values=['company'=>'QA Supplier','number'=>'QA-17','gross'=>'1.190,00','release_amount'=>'1.190,00','decision'=>'approved','reason'=>'Abschlagsrechnung (isolierter Test)','to'=>'test@example.org','cc'=>'','bcc'=>''];
$saved=callReview('save',['folder_id'=>$folder,'token'=>$prepared['token'],'values'=>$values]);
expectReview($saved['ok']===true,'Save works with the real IONOS store');
$record=json_decode(ionosBytes($saved['record_id']),true);
expectReview((float)$record['values']['gross']===1190.0&&$record['send_status']==='unsent','Cents and unsent status persist');
$parent=ionosItem(ionosItem($saved['record_id'])['parents'][0]);
expectReview($parent['name']==='06_Freigaben_Zahlungen','Correct existing case subfolder');
$cross=callReview('save',['folder_id'=>'wrong-case','token'=>$prepared['token'],'values'=>$values]);expectReview($cross['status']===403,'Cross-case blocked');
$sent=callReview('send',['folder_id'=>$folder,'record_id'=>$saved['record_id']]);
expectReview($sent['ok']===true,'Small attachment transport');
$message=json_decode($calls[0]['body'],true)['message'];expectReview(base64_decode($message['attachments'][0]['contentBytes'])===$sourceBytes,'Original bytes transmitted');
expectReview(json_decode(ionosBytes($saved['record_id']),true)['send_status']==='sent','Confirmed send persists');
$again=callReview('send',['folder_id'=>$folder,'record_id'=>$saved['record_id']]);expectReview($again['status']===400&&count($calls)===1,'Double send blocked');
$prepared=callReview('prepare',['folder_id'=>$folder,'file_id'=>$file,'kind'=>'offer','mode'=>'direct']);
$saved=callReview('save',['folder_id'=>$folder,'token'=>$prepared['token'],'values'=>$values]);$transportFail=true;
$failed=callReview('send',['folder_id'=>$folder,'record_id'=>$saved['record_id']]);
expectReview($failed['status']===400&&json_decode(ionosBytes($saved['record_id']),true)['send_status']==='uncertain','Ambiguous send remains blocked');
$count=count($calls);callReview('send',['folder_id'=>$folder,'record_id'=>$saved['record_id']]);expectReview(count($calls)===$count,'No retry after ambiguity');$transportFail=false;
$largeBytes=str_repeat('0123456789',400000);$large=ionosWrite(['name'=>'LargeOriginal.pdf','mimeType'=>'application/pdf','parents'=>[$folder]],$largeBytes)['id'];
$prepared=callReview('prepare',['folder_id'=>$folder,'file_id'=>$large,'kind'=>'invoice','mode'=>'direct']);
$saved=callReview('save',['folder_id'=>$folder,'token'=>$prepared['token'],'values'=>$values]);$calls=[];
$sent=callReview('send',['folder_id'=>$folder,'record_id'=>$saved['record_id']]);expectReview($sent['ok']===true,'Large attachment uses draft session');
$parts=array_values(array_filter($calls,fn($call)=>$call['method']==='PUT'));
expectReview(implode('',array_column($parts,'body'))===$largeBytes,'All large attachment bytes survive chunking');
$last=end($calls);expectReview(str_ends_with($last['url'],'/send'),'Send occurs only after full upload');
$prepared=callReview('prepare',['folder_id'=>$folder,'file_id'=>$large,'kind'=>'invoice','mode'=>'direct']);
$saved=callReview('save',['folder_id'=>$folder,'token'=>$prepared['token'],'values'=>$values]);$draftFail=true;$calls=[];
$failed=callReview('send',['folder_id'=>$folder,'record_id'=>$saved['record_id']]);
expectReview($failed['status']===400&&json_decode(ionosBytes($saved['record_id']),true)['send_status']==='unsent','Failed draft is definitely unsent');
expectReview(count($calls)===1&&str_ends_with($calls[0]['url'],'/messages'),'Failed draft never submits send');$draftFail=false;

$workspace=callReview('workspace',[])['folder_id'];
$main=drWorkspaceWrite($workspace,'Sammelangebot.pdf','application/pdf',"%PDF-main\x00")['id'];
$proof=drWorkspaceWrite($workspace,'Messprotokoll.pdf','application/pdf',"%PDF-proof\xff")['id'];
$prepared=callReview('prepare',['folder_id'=>$workspace,'file_id'=>$main,'support_ids'=>[$proof,$main,$proof],'kind'=>'offer','mode'=>'direct','comment'=>'Demontage und Trocknung laut Begleitmail bereits freigegeben.','case_no'=>'26-859059','attach_support'=>true]);
expectReview($prepared['ok']===true&&count($prepared['source_names'])===2,'Standalone multi-document preparation deduplicates originals');
$preview=krVerify($prepared['token']);expectReview(str_contains($preview['comment'],'bereits freigegeben'),'Comment retained in signed preview');
$saved=callReview('save',['folder_id'=>$workspace,'token'=>$prepared['token'],'values'=>$values,'case_no'=>'26-859059']);
expectReview($saved['ok']===true&&str_contains($saved['subject'],'26-859059'),'Standalone decision saves without a case');
$calls=[];$sent=callReview('send',['folder_id'=>$workspace,'record_id'=>$saved['record_id']]);
expectReview($sent['ok']===true,'Standalone multi-attachment send');
$message=json_decode($calls[0]['body'],true)['message'];
expectReview(count($message['attachments'])===2&&base64_decode($message['attachments'][1]['contentBytes'])==="%PDF-proof\xff",'Supporting original attached byte-identically');
$GLOBALS['user']=['id'=>18];try{drSelected($workspace,$main);throw new RuntimeException('Owner isolation failed');}catch(ReviewResponse $r){expectReview($r->status===403,'Standalone owner isolation');}$GLOBALS['user']=['id'=>17];
$other=callReview('workspace',[])['folder_id'];$wrong=callReview('prepare',['folder_id'=>$other,'file_id'=>$main,'kind'=>'offer','mode'=>'direct']);expectReview($wrong['status']===400,'Cross-workspace source blocked');
$prepared=callReview('prepare',['folder_id'=>$folder,'file_id'=>$large,'support_ids'=>[$file],'kind'=>'offer','mode'=>'direct']);
$saved=callReview('save',['folder_id'=>$folder,'token'=>$prepared['token'],'values'=>$values]);$calls=[];
$sent=callReview('send',['folder_id'=>$folder,'record_id'=>$saved['record_id']]);expectReview($sent['ok']===true,'Mixed large and small original attachments');
expectReview(count(array_filter($calls,fn($c)=>str_ends_with($c['url'],'/attachments')))===1,'Small proof attached to large draft');
expectReview(str_ends_with(end($calls)['url'],'/send'),'Mixed attachment draft sent only after all attachments');
echo "Isolierter Workflow: Fallzuordnung, echte IONOS-Speicherung, Direktmodus ohne KI, Originalanhang, große Anhänge und Wiederholschutz geprüft.\n";
