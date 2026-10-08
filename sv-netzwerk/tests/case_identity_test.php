<?php
declare(strict_types=1);
require __DIR__.'/../public/intern/api/case-search.php';
function check(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);}
foreach(['24-171720-9','24-1717209','241717209','24 – 171720 – 9']as$n)check(caseNumberKey($n)==='241717209','Equivalent number: '.$n);
check(caseNumberKey('24-171720-8')!==caseNumberKey('24-171720-9'),'Suffix retained');
check(caseNumberKey('024-1717209')!==caseNumberKey('24-1717209'),'Leading zero retained');
check(caseNumberKey('AB/12')!==caseNumberKey('AB12'),'Slash retained');
foreach(['24-171720-9','24-1717209','241717209']as$n)check(caseSearchMatches(caseSearchText(['schaden_nr'=>'24-171720-9','vn_objekt'=>'Klaus Droxler']),$n),'Search variant');
check(!caseSearchMatches(caseSearchText(['schaden_nr'=>'24-171720-8']),'24-171720-9'),'Different suffix search');
check(caseSearchMatches(caseSearchText(['vn_objekt'=>'Klaus Droxler']),'Klaus Droxler'),'Name search retained');
$cases=['christian'=>['existing'=>['schaden_nr'=>'24-171720-9','vn_objekt'=>'Klaus Droxler','email'=>'saved@example.test','reserve'=>'100']],'marc'=>[]];$created=0;
function ionosStorageEnabled():bool{return false;}
function gdUserKey(array$user):string{return$user['email'];}
function gdUserCasesRoot(array$user):array{return['id'=>$user['email']];}
function gdListChildren(string$parent,?string$mime=null,int$limit=1000):array{global$cases;return array_map(fn($id)=>['id'=>$id,'name'=>$cases[$parent][$id]['schaden_nr']],array_keys($cases[$parent]));}
function gdCaseMeta(string$id):array{global$cases;foreach($cases as$rows)if(isset($rows[$id]))return$rows[$id];return[];}
function gdFileMeta(string$id):array{global$cases;foreach($cases as$profile=>$rows)if(isset($rows[$id]))return['parents'=>[$profile]];return[];}
function gdAssertCaseAccess(string$id,array$user):void{global$cases;if(!isset($cases[$user['email']][$id]))throw new RuntimeException('Forbidden');}
function gdCreateFolder(string$root,string$name):array{global$cases,$created;$id='new'.(++$created);$cases[$root][$id]=[];return['id'=>$id];}
function gdCaseName(array$data):string{return$data['schaden_nr'];}
function gdUploadJson(string$id,string$name,array$data):array{global$cases;foreach($cases as&$rows)if(isset($rows[$id])){$rows[$id]=$data;return['id'=>'meta'];}throw new RuntimeException('Missing');}
function registerCaseFolderOwner(string$id,array$user,array$meta):void{}
function db():object{return new class{function prepare(string$sql):object{return new class{function execute(array$args):void{}function fetchColumn():int{return 1;}function fetchAll(int$mode=0):array{return[];}};}};}
const CASE_META_NAME='00_Falldaten.json';
$user=['email'=>'christian','full_name'=>'Christian'];
$saved=caseIdentitySave($user,['case'=>['schaden_nr'=>'241717209','vn_objekt'=>'Klaus Droxler','email'=>'','quelle'=>'Import']]);
check($saved['folder_id']==='existing'&&$created===0,'Reuse existing rather than create duplicate');
check($saved['meta']['email']==='saved@example.test'&&$saved['meta']['reserve']==='100'&&$saved['meta']['quelle']==='Import','Preserve operational data and augment');
check($saved['meta']['schaden_nr']==='24-171720-9','Preserve display number');
$other=caseIdentitySave(['email'=>'marc','full_name'=>'Marc'],['case'=>['schaden_nr'=>'241717209']]);check($other['folder_id']!=='existing'&&$created===1,'Profile isolation');
$cases['christian']['duplicate']=['schaden_nr'=>'24-1717209'];
try{caseIdentitySave($user,['case'=>['schaden_nr'=>'241717209']]);throw new LogicException('Duplicate accepted');}catch(RuntimeException$e){check(str_contains($e->getMessage(),'zusammenführen'),'Block ambiguous duplicates');}
echo "Case identity regression tests passed\n";
