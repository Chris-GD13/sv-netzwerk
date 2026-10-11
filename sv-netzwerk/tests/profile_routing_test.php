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
