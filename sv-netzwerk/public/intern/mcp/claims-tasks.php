<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/api/profile-routing.php';

function mcpClaimsTaskSnapshot(array $row,string $profile): array {
    $result=json_decode((string)($row['result_json']??''),true);
    if (($row['status']??'')!=='done' || !is_array($result) || ($result['source']??'')!=='claimsforce-tasks-api'
        || ($result['sourceProfile']??'')!==$profile || !is_array($result['tasks']??null)) {
        return ['available'=>false,'reason'=>'NO_VERIFIED_TASK_SNAPSHOT'];
    }
    $stamp=strtotime((string)($result['fetchedAt']??''));
    return ['available'=>true,'source'=>'Claimsforce Aufgaben','profile'=>$profile,'fetched_at'=>$result['fetchedAt'],
        'stale'=>!$stamp || time()-$stamp>300, 'open_count'=>$result['openTasks']??null,'tasks'=>$result['tasks'],
        'job_id'=>(int)$row['id'],'notice'=>'Originale Aufgabenantwort des angemeldeten Claimsforce-Kontos. Aufgabenstatus und verlinkte Nachricht prüfen; keine Zahlung oder Erledigung aus dem Listeneintrag ableiten.'];
}

function mcpClaimsTasks(array $user,bool $refresh): array {
    $profile=svnetUserProfile($user);
    if ($profile==='') throw new RuntimeException('Kein persönliches Claimsforce-Profil zugeordnet.');
    $email=(string)$user['email'];
    $s=db()->prepare("SELECT id,status,result_json FROM claimsforce_import_jobs WHERE requested_by=:email AND profile=:profile AND sync_mode='tasks' AND status='done' ORDER BY id DESC LIMIT 1");
    $s->execute([':email'=>$email,':profile'=>$profile]);
    $snapshot=mcpClaimsTaskSnapshot($s->fetch()?:[],$profile);
    if (!$refresh) return $snapshot;
    // Reuse the existing import station. Never replace an active import.
    $lock='mcp-task-refresh:'.substr(hash('sha256',$email.$profile),0,40);
    $s=db()->prepare('SELECT GET_LOCK(:key,2)');$s->execute([':key'=>$lock]);
    if ((int)$s->fetchColumn()!==1) throw new RuntimeException('Aufgabenabruf wird bereits vorbereitet.');
    try {
        $s=db()->prepare("SELECT id,sync_mode,status FROM claimsforce_import_jobs WHERE requested_by=:email AND profile=:profile AND status IN ('queued','running') ORDER BY id DESC LIMIT 1");
        $s->execute([':email'=>$email,':profile'=>$profile]);$pending=$s->fetch();
        if (!$pending) {
            $s=db()->prepare("INSERT INTO claimsforce_import_jobs(profile,status,requested_by,message,phase,sync_mode,source_profiles_json,created_at) VALUES(:profile,'queued',:email,'Claimsforce-Aufgaben für den Agenten lesen','CF-QUEUED','tasks',:profiles,NOW())");
            $s->execute([':profile'=>$profile,':email'=>$email,':profiles'=>json_encode([$profile])]);
            $pending=['id'=>(int)db()->lastInsertId(),'sync_mode'=>'tasks','status'=>'queued'];
        }
        $snapshot['refresh']=$pending;
        $snapshot['refresh_notice']='Abruf angefordert, noch nicht abgeschlossen. Vor Bearbeitung list_claimsforce_tasks ohne refresh erneut lesen. Bei laufendem anderem Import wird dieser nicht unterbrochen. Die angemeldete bestehende Browser-Brücke muss aktiv sein.';
        return $snapshot;
    } finally { $s=db()->prepare('SELECT RELEASE_LOCK(:key)');$s->execute([':key'=>$lock]); }
}
