<?php
declare(strict_types=1);
// Deterministic interleaving seam: ionosRow returns a checked snapshot, then an
// independent writer moves that item before the caller decodes it or creates CAS.
function row(string $id,string $parent):array {return ['id'=>$id,'parent'=>$parent,'name'=>$id,'mime'=>'application/vnd.google-apps.folder','meta'=>'{}','path'=>'','verified'=>1];}
$rows=['cw-root'=>row('cw-root','root'),'case'=>row('case','cw-root'),'target'=>row('target','case')];$move=null;$reads=[];
function ionosRow(string $id):?array {global $rows,$move,$reads;$reads[]=$id;$snapshot=$rows[$id]??null;if($move===$id){$rows[$id]['parent']='foreign-profile';$move=null;}return $snapshot;}
function ionosRowFingerprint(?array $row):string {return hash('sha256',json_encode($row,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));}
function ionosItem(string $id):never {throw new LogicException('Separate metadata read must not be used in checked contexts');}
function svnetCanAccessCaseProfile(array $user,string $profile):bool {return $profile==='christian';}
function gdFolderId(string $kind):string {return 'cw-root';}
require __DIR__.'/../public/intern/api/claimsforce-source-refresh-core.php';
function verify(bool $ok,string $name):void {if(!$ok)throw new RuntimeException($name);$GLOBALS['tests'][]=$name;}
$tests=[];$caseBefore=$rows['case'];$move='case';
$context=cfrAssertCaseAccessReadOnly('case',[],'christian');
verify($context[0]['rowFingerprint']===ionosRowFingerprint($caseBefore),'case CAS uses the checked row before injected profile move');
verify($context[0]['rowFingerprint']!==ionosRowFingerprint($rows['case']),'injected move invalidates source write context');
$rows['case']=$caseBefore;$targetBefore=$rows['target'];$move='target';
$context=cfrFolderContext('target','case');
verify($context[0]['rowFingerprint']===ionosRowFingerprint($targetBefore),'ancestor CAS uses exactly the row used for parent traversal');
verify($context[0]['rowFingerprint']!==ionosRowFingerprint($rows['target']),'injected ancestor move invalidates source write context');
verify(count(array_filter($reads,static fn($id)=>$id==='case'))===1&&count(array_filter($reads,static fn($id)=>$id==='target'))===1,'no second unvalidated row read builds a context token');
echo json_encode(['passed'=>count($tests),'tests'=>$tests],JSON_PRETTY_PRINT),PHP_EOL;
