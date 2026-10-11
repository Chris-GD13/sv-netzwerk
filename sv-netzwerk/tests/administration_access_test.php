<?php
declare(strict_types=1);
require_once __DIR__.'/../public/intern/api/administration-access.php';
$cases = [
    [['email'=>'cw@sv-schuett.eu','role'=>'sachverstaendiger'], true],
    [['email'=>' INFO@SV-SCHUETT.EU ','role'=>'pruefer'], true],
    [['email'=>'cw@sv-netzwerk.eu','role'=>'administrator'], false],
    [['email'=>'cw@sv-netzwerk.de','role'=>'administrator'], false],
    [['email'=>'admin@example.invalid','role'=>'administrator'], false],
    [['email'=>'hr@sv-schuett.eu','role'=>'administrator'], false],
    [['email'=>'ms@sv-schuett.eu','role'=>'administrator'], false],
    [['email'=>'ws@sv-schuett.eu','role'=>'administrator','svnet_expert_profile'=>'christian'], false],
    [['email'=>'unknown@example.invalid','full_name'=>'Christian Wächter','role'=>'pruefer'], false],
    [['email'=>'fakecw@sv-schuett.eu','role'=>'administrator'], false],
    [[], false],
];
foreach ($cases as [$user, $allowed]) {
    if (svnetCanManageWorkRules($user) !== $allowed) throw new RuntimeException('Falsche Administrationsfreigabe.');
}
echo "Administration: nur die zwei freigegebenen Konten; Rollen und delegierte Profile ohne Sonderzugriff.\n";
