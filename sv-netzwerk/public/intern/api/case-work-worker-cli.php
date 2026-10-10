<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/config.php';
$turnId=(int)($argv[1]??0);if($turnId<1)exit(1);
$job=0;
try{
    $lock=db()->prepare('SELECT GET_LOCK(:k,0)');$lock->execute([':k'=>'case-work-turn:'.$turnId]);if((int)$lock->fetchColumn()!==1)exit;
    $s=db()->prepare('SELECT w.*,a.status FROM case_work_turns w JOIN case_analysis_jobs a ON a.id=w.analysis_id WHERE w.id=:id');$s->execute([':id'=>$turnId]);$turn=$s->fetch(PDO::FETCH_ASSOC);
    if(!$turn||$turn['status']!=='running')exit;
    $job=(int)$turn['analysis_id'];
    $s=db()->prepare('SELECT id,email,full_name,role,is_active FROM users WHERE id=:id AND is_active=1');$s->execute([':id'=>$turn['user_id']]);$user=$s->fetch(PDO::FETCH_ASSOC);
    if(!$user||!in_array($user['role'],['administrator','projektleiter','pruefer','sachverstaendiger'],true))throw new RuntimeException('Benutzerberechtigung nicht mehr gültig.');
    $s=db()->prepare('SELECT user_id FROM case_folder_owners WHERE folder_id=:f');$s->execute([':f'=>$turn['folder_id']]);if((int)$s->fetchColumn()!==(int)$user['id'])throw new RuntimeException('Fallzuordnung nicht mehr gültig.');
    define('SVNET_CASE_WORK_USER',$user);define('SVNET_CASE_ANALYSIS_LIBRARY_ONLY',true);
    require_once __DIR__.'/case-analysis.php';require_once __DIR__.'/case-work-core.php';
    $s=db()->prepare('SELECT w.instructions,w.created_at,a.result_json FROM case_work_turns w LEFT JOIN case_analysis_jobs a ON a.id=w.analysis_id WHERE w.folder_id=:f AND w.user_id=:u AND w.id<:id ORDER BY w.id');
    $s->execute([':f'=>$turn['folder_id'],':u'=>$user['id'],':id'=>$turnId]);$history=cwHistoryContext($s->fetchAll(PDO::FETCH_ASSOC));
    $import=null;
    if($turn['import_id']){$s=db()->prepare('SELECT id,status,finished_at,result_json FROM claimsforce_import_jobs WHERE id=:id AND requested_by=:u');$s->execute([':id'=>$turn['import_id'],':u'=>$user['email']]);$import=$s->fetch(PDO::FETCH_ASSOC);if($import){$import['result']=json_decode((string)$import['result_json'],true);unset($import['result_json']);}}
    caRun($job,$turn['folder_id'],$turn['task_id'],$turn['instructions'],$user,['history'=>$history,'import'=>$import]);
}catch(Throwable $error){
    error_log('[case-work-worker '.$turnId.'] '.$error->getMessage());
    if($job){$s=db()->prepare("UPDATE case_analysis_jobs SET status='failed',message=:m,updated_at=NOW() WHERE id=:id");$s->execute([':m'=>$error->getMessage(),':id'=>$job]);}
    exit(1);
}
