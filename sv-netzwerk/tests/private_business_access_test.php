<?php
declare(strict_types=1);
require_once __DIR__.'/../public/intern/api/profile-routing.php';
require_once __DIR__.'/../public/intern/api/private-business-access.php';
foreach (['cw@sv-schuett.eu','cw@sv-netzwerk.eu','ws@sv-schuett.eu','ks@sv-schuett.eu'] as $email) {
    if (!pbCanCreate(['email'=>$email]) || pbPrivateProfile(['email'=>$email]) !== 'christian') throw new RuntimeException('Explicit grant missing: '.$email);
}
$marc = ['email'=>'ms@sv-schuett.eu','full_name'=>'Marc Schütt','role'=>'sachverstaendiger'];
if (!pbCanCreate($marc) || pbPrivateProfile($marc) !== 'marc') throw new RuntimeException('Marc must create in his own inventory');
if (!pbCanCreate(['email'=>' MS@SV-SCHUETT.EU ']) || pbPrivateProfile(array_merge($marc,['svnet_expert_profile'=>'christian'])) !== 'marc') throw new RuntimeException('Marc account identity must bind the private profile');
if (svnetAccessibleCaseProfiles($marc) !== ['marc']) throw new RuntimeException('Offer grant must not expand insurance case access');
foreach ([['email'=>'hr@sv-schuett.eu'], ['email'=>'other@example.com','full_name'=>'Christian Wächter','role'=>'administrator'], ['email'=>'other@example.com','full_name'=>'Marc Schütt','svnet_expert_profile'=>'marc'], ['email'=>'ms@other.eu'], ['email'=>'ks@other.eu'], []] as $user) {
    if (pbCanCreate($user)) throw new RuntimeException('Unapproved account accepted');
}
$api=file_get_contents(__DIR__.'/../public/intern/api/private-business.php');
$orders=file_get_contents(__DIR__.'/../public/intern/api/private-orders.php');
$guard=file_get_contents(__DIR__.'/../public/intern/private-business-check.php');
$build=file_get_contents(__DIR__.'/../scripts/protect-internal-pages.mjs');
foreach ([$api,$orders,$guard] as $source) if (!str_contains($source,'pbCanCreate($user)')) throw new RuntimeException('Server access check missing');
if (!str_contains($build,'private-business-check.php')) throw new RuntimeException('Direct page guard missing');
if (strpos($api,"=== 'access'") > strpos($api,'if(!pbCanCreate($user))')) throw new RuntimeException('Read-only access decision must precede the record guard');
echo "private_business_access_test: ok (explicit accounts; Marc own inventory; no role/name bypass; page and API guards)\n";

require_once __DIR__.'/../public/intern/api/private-business-core.php';
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);pbSchema($db);
$original=pbSave($db,'marc',['request_key'=>pbUuid(),'data'=>['company'=>'Teamtest','scope'=>'Prüfung','billing_street'=>'Testweg 1','billing_postcode'=>'12345','billing_city'=>'Testort']]);
foreach(['cw@sv-schuett.eu','ws@sv-schuett.eu','ks@sv-schuett.eu','ms@sv-schuett.eu'] as $email){
    $profile=pbTeamRecordProfile($db,$original['id'],['email'=>$email]);
    if($profile!=='marc')throw new RuntimeException('Owner changed during shared access');
    $r=pbRecord($db,$original['id'],$profile);
    $updated=pbSave($db,$profile,['id'=>$r['id'],'revision'=>$r['revision'],'request_key'=>pbUuid(),'data'=>$r['data']],['email'=>$email]);
    if($updated['profile']!=='marc'||$updated['number']!==$original['number'])throw new RuntimeException('Shared edit lost inventory or number');
}
try{pbTeamRecordProfile($db,$original['id'],['email'=>'hr@sv-schuett.eu']);throw new LogicException('Unapproved team access');}catch(RuntimeException $e){if($e->getCode()!==403)throw $e;}
echo "shared business editing: ok (four explicit users; original inventory retained; other users denied)\n";
