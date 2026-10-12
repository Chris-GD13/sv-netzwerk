<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/public/intern/api/profile-routing.php';
$users=[
 ['email'=>'cw@sv-netzwerk.eu','full_name'=>'Christian Wächter','role'=>'administrator'],
 ['email'=>'ms@sv-schuett.eu','full_name'=>'Marc Schütt','role'=>'administrator'],
 ['email'=>'hr@sv-schuett.eu','full_name'=>'Holger Roth','role'=>'administrator'],
 ['email'=>'jens@example.invalid','full_name'=>'Jens Maurer','role'=>'administrator'],
 ['email'=>'ws@sv-schuett.eu','full_name'=>'Susanne Wächter','role'=>'administrator'],
];
foreach($users as$i=>$user){
 $expected=['christian','marc','holger','jens','christian'][$i];
 foreach(['','christian','marc','holger','jens','unbekannt']as$selected){
  if(svnetSelectedProfile($user,$selected)!==$expected)throw new RuntimeException('Fremdes Profil für '.$user['email']);
 }
 if(svnetIsBackofficeUser($user)!==($i===4))throw new RuntimeException('Administrator darf keine Freigabe erteilen.');
}
foreach(['marc','holger','jens']as$profile){
 $rejected=false;
 try{svnetExpertIdentity($profile,$users[4]);}catch(InvalidArgumentException){$rejected=true;}
 if(!$rejected)throw new RuntimeException('Susanne darf kein fremdes Profil übernehmen.');
}
$rejected=false;
try{svnetSelectedProfile(['email'=>'unknown@example.invalid','role'=>'administrator']);}catch(InvalidArgumentException){$rejected=true;}
if(!$rejected)throw new RuntimeException('Unbekannte Administratoren dürfen nicht auf Christian zurückfallen.');
echo "Profilrouting: eigene Profile, alte Sitzungen und Susanne-Ausnahme geprüft.\n";

foreach($users as$i=>$user){
 $profiles=svnetAccessibleCaseProfiles($user);
 $expected=$i===0||$i===4?['christian','jens']:[['christian'],['marc'],['holger'],['jens']][$i];
 if($profiles!==$expected)throw new RuntimeException('Falscher gemeinsamer Fallbestand.');
 foreach(svnetSupportedProfiles()as$owner){
  if(svnetCanAccessCaseProfile($user,$owner)!==in_array($owner,$expected,true))throw new RuntimeException('Fremdzugriff auf '.$owner);
 }
}
echo "Maurer: nur Christian und Susanne erhalten den gemeinsamen Fallbestand.\n";
foreach($users as$i=>$user){
    $allowed=$i===0||$i===4;
    if(svnetCanRunClaimsStation($user)!==$allowed)throw new RuntimeException('Falsche Importstationsfreigabe.');
    if(svnetClaimsStationProfiles($user)!==($allowed?['christian','jens']:[]))throw new RuntimeException('Fremder Importbestand.');
}
if(!svnetCanRunClaimsStation(['email'=>'cw@sv-schuett.eu']))throw new RuntimeException('Christian-Claims-Adresse fehlt.');
if(svnetCanRunClaimsStation(['email'=>'unknown@example.invalid','role'=>'administrator']))throw new RuntimeException('Unbekannter Administrator erhält Importrecht.');
echo "Christian und Susanne betreiben die Importstation für beide freigegebenen Quellen.\n";

// Exercise the real Drive access helpers with isolated folders and no remote writes.
function apiError(int $status,string $message):never{throw new RuntimeException($message,$status);}
function gdUserKey(array $user):string{return (string)($user['email']??'');}
function gdUserCasesRoot(array $user,bool $sharedSource=false):array{
 $profile=svnetUserProfile($user);
 return ['id'=>'root-'.$profile,'name'=>svnetCasesFolderName($profile)];
}
function gdFileMeta(string $id):array{
 $profile=explode('-',$id)[0];
 return ['id'=>$id,'name'=>$id,'mimeType'=>'application/vnd.google-apps.folder','parents'=>['root-'.$profile]];
}
function gdListChildren(string $root,?string $mime=null,int $limit=1000):array{
 return [gdFileMeta(str_replace('root-','',$root).'-case')];
}
function gdCaseMeta(string $id):array{return ['schaden_nr'=>str_starts_with($id,'jens-')?'26-990077-4':'26-160966-8'];}
function registerCaseFolderOwner(string $id,array $user,array $meta=[]):void{
 $GLOBALS['registered_owner']=['id'=>$id,'profile'=>svnetUserProfile($user),'meta'=>$meta];
}
$source=file_get_contents(dirname(__DIR__).'/public/intern/api/google-drive-sync.php');
$start=strpos($source,'function gdAccessibleCaseRoots(');
$end=strpos($source,'function gdUploadJson(',$start);
eval(substr($source,$start,$end-$start));
foreach([$users[0],$users[4]]as$user){
 $folders=gdAccessibleCaseFolders($user);
 if(array_column($folders,'case_profile')!==['christian','jens'])throw new RuntimeException('Gemeinsame Fallübersicht fehlt.');
 gdAssertCaseAccess('jens-case',$user);
 if(($GLOBALS['registered_owner']['profile']??'')!=='jens')throw new RuntimeException('Maurer-Zuordnung wurde überschrieben.');
 $label=gdCaseResultLabel(['name'=>'26-160966-8'],'jens');
 if(!str_contains($label['name'],'Maurer'))throw new RuntimeException('Maurer-Kennzeichnung fehlt.');
}
foreach([$users[1],$users[2]]as$user){
 if(count(gdAccessibleCaseFolders($user))!==1)throw new RuntimeException('Fremde Fälle in eigener Übersicht.');
 $rejected=false;
 try{gdAssertCaseAccess('jens-case',$user);}catch(RuntimeException$e){$rejected=$e->getCode()===403;}
 if(!$rejected)throw new RuntimeException('Maurer für anderen Bearbeiter freigegeben.');
}
echo "Gemeinsame Übersicht, Maurer-Bearbeitung, Herkunft und Fremdzugriff geprüft.\n";

function ionosStorageEnabled():bool{return false;}
function searchCaseFolderIndex(array$user,string$number,int$limit=30):array{return [];}
require_once dirname(__DIR__).'/public/intern/api/case-identity.php';
foreach([$users[0],$users[4]]as$user){
 $matches=caseIdentityFind($user,'26-990077-4');
 if(array_keys($matches)!==['jens-case'])throw new RuntimeException('Vorhandener Maurer-Fall wird bei Speicherung nicht wiederverwendet.');
}
foreach([$users[1],$users[2]]as$user){
 if(caseIdentityFind($user,'26-990077-4')!==[])throw new RuntimeException('Maurer-Fall über Identitätssuche sichtbar.');
}
echo "Schadennummer findet denselben Maurer-Fall ohne Kopie.\n";
