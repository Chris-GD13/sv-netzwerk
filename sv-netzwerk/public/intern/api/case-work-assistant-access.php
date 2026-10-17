<?php
declare(strict_types=1);
require_once __DIR__.'/profile-routing.php';

/** Same ownership policy as requireCaseFolderAccess, but throws instead of
 * terminating PHP so already incurred model usage can always be committed. */
function svAssistantCaseAccess(PDO $db,array $sessionUser,string $folder,string $selected=''): array {
    $q=$db->prepare('SELECT * FROM users WHERE id=?');$q->execute([(int)$sessionUser['id']]);$user=$q->fetch(PDO::FETCH_ASSOC);
    if(!$user||empty($user['is_active'])||!in_array($user['role']??'',['administrator','projektleiter','pruefer','sachverstaendiger'],true))throw new RuntimeException('USER_FORBIDDEN');
    if($folder==='')throw new RuntimeException('CASE_FORBIDDEN');
    try{$profile=svnetSelectedProfile($user,$selected);}catch(InvalidArgumentException $e){throw new RuntimeException('USER_FORBIDDEN');}
    if($profile==='jens')throw new RuntimeException('CASE_FORBIDDEN');
    $q=$db->prepare('SELECT user_id,user_email FROM case_folder_owners WHERE folder_id=? LIMIT 1');$q->execute([$folder]);$owner=$q->fetch(PDO::FETCH_ASSOC);
    $ownerProfile=$owner?svnetUserProfile(['email'=>(string)$owner['user_email']]):'';
    if(!$owner||$ownerProfile===''||!svnetCanAccessCaseProfile($user,$ownerProfile))throw new RuntimeException('CASE_FORBIDDEN');
    return $user;
}
