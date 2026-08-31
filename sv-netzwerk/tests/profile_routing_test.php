<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/public/intern/api/profile-routing.php';

function expectSame(string $actual, string $expected, string $label): void
{
    if ($actual !== $expected) throw new RuntimeException($label.': erwartet '.$expected.', erhalten '.$actual);
}

$susanne=['email'=>'susanne@example.invalid','full_name'=>'Susanne Wächter','role'=>'administrator'];
$matrix=[
    ['label'=>'Susanne -> Christian','user'=>$susanne,'selected'=>'christian','expected'=>'christian'],
    ['label'=>'Susanne -> Holger','user'=>$susanne,'selected'=>'holger','expected'=>'holger'],
    ['label'=>'Susanne -> Marc','user'=>$susanne,'selected'=>'marc','expected'=>'marc'],
    ['label'=>'Susanne -> Jens','user'=>$susanne,'selected'=>'jens','expected'=>'jens'],
    ['label'=>'kein Profil -> Christian','user'=>$susanne,'selected'=>'','expected'=>'christian'],
    ['label'=>'Christian -> Christian','user'=>['email'=>'cw@example.invalid','full_name'=>'Christian Wächter'],'selected'=>null,'expected'=>'christian'],
    ['label'=>'Holger -> Holger','user'=>['email'=>'hr@example.invalid','full_name'=>'Holger Roth'],'selected'=>null,'expected'=>'holger'],
    ['label'=>'Marc -> Marc','user'=>['email'=>'ms@example.invalid','full_name'=>'Marc Schütt'],'selected'=>null,'expected'=>'marc'],
    ['label'=>'Jens -> Jens','user'=>['email'=>'jens@example.invalid','full_name'=>'Jens Maurer'],'selected'=>null,'expected'=>'jens'],
];

foreach($matrix as $row) expectSame(svnetSelectedProfile($row['user'],$row['selected']),$row['expected'],$row['label']);

if(!svnetIsBackofficeUser($susanne))throw new RuntimeException('Susanne muss als Backoffice erkannt werden.');
foreach(array_slice($matrix,5)as$row)if(svnetIsBackofficeUser($row['user']))throw new RuntimeException($row['label'].' darf nicht als Backoffice erkannt werden.');

foreach(['christian','holger','marc','jens']as$profile){
    $identity=svnetExpertIdentity($profile,$susanne);
    expectSame(svnetUserProfile($identity),$profile,'Identität '.$profile);
    if(!str_starts_with(svnetCasesFolderName($profile),'Schadenfälle '))throw new RuntimeException('Fallordner fehlt für '.$profile);
}

$rejected=false;
try{svnetSelectedProfile($susanne,'unbekannt');}catch(InvalidArgumentException){$rejected=true;}
if(!$rejected)throw new RuntimeException('Ein unbekanntes Profil darf nicht auf Christian zurückfallen.');

echo "Profilrouting: 9 Zuordnungen und unbekanntes Profil geprüft.\n";
