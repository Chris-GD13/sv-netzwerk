<?php
declare(strict_types=1);
require_once __DIR__.'/../public/intern/api/profile-routing.php';
require_once __DIR__.'/../public/intern/api/private-business-access.php';
foreach (['cw@sv-schuett.eu','cw@sv-netzwerk.eu','ws@sv-schuett.eu','ks@sv-schuett.eu'] as $email) {
    if (!pbCanCreate(['email'=>$email]) || pbPrivateProfile(['email'=>$email]) !== 'christian') throw new RuntimeException('Explicit grant missing: '.$email);
}
foreach ([['email'=>'ms@sv-schuett.eu','role'=>'administrator'], ['email'=>'hr@sv-schuett.eu'], ['email'=>'other@example.com','full_name'=>'Christian Wächter','role'=>'administrator'], ['email'=>'ks@other.eu'], []] as $user) {
    if (pbCanCreate($user)) throw new RuntimeException('Unapproved account accepted');
}
$api=file_get_contents(__DIR__.'/../public/intern/api/private-business.php');
$orders=file_get_contents(__DIR__.'/../public/intern/api/private-orders.php');
$guard=file_get_contents(__DIR__.'/../public/intern/private-business-check.php');
$build=file_get_contents(__DIR__.'/../scripts/protect-internal-pages.mjs');
foreach ([$api,$orders,$guard] as $source) if (!str_contains($source,'pbCanCreate($user)')) throw new RuntimeException('Server access check missing');
if (!str_contains($build,'private-business-check.php')) throw new RuntimeException('Direct page guard missing');
echo "private_business_access_test: ok (Christian, Susanne, Katja only; no role/name bypass; page and API guards)\n";
