<?php
declare(strict_types=1);
require_once __DIR__.'/../public/intern/api/energy-access.php';
$cases = [
    [['email'=>'cw@sv-schuett.eu'],true],
    [['email'=>' CW@SV-SCHUETT.EU '],true],
    [['email'=>'info@sv-schuett.eu','role'=>'administrator'],false],
    [['email'=>'cw@sv-netzwerk.eu','role'=>'administrator'],false],
    [['email'=>'ws@sv-schuett.eu','svnet_expert_profile'=>'christian'],false],
    [['email'=>'fakecw@sv-schuett.eu','full_name'=>'Christian Wächter'],false],
    [['role'=>'administrator','full_name'=>'Christian Wächter'],false],
    [[],false],
];
foreach ($cases as [$user,$expected]) {
    if (svnetCanAccessEnergy($user) !== $expected) throw new RuntimeException('Energy authorization failed');
}
foreach (['https://evil.example','//evil.example','../auth.php','EVCC',''] as $input) {
    if (svnetEnergyDestination($input) !== null) throw new RuntimeException('Unapproved redirect');
}
if (svnetEnergyDestination('evcc') !== 'http://10.77.66.1:7070/#/') throw new RuntimeException('Wrong evcc destination');
echo "Energy access: owner only, delegated accounts rejected, redirect allowlist verified.\n";
