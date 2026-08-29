<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
commonHeaders();
$user=requireAuth();
if(!in_array((string)($user['role']??''),['administrator','projektleiter','pruefer','sachverstaendiger'],true))apiError(403,'Keine Berechtigung.');

db()->exec("CREATE TABLE IF NOT EXISTS claimsforce_import_jobs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,profile VARCHAR(30) NOT NULL,status VARCHAR(30) NOT NULL DEFAULT 'queued',requested_by VARCHAR(255) NOT NULL,message VARCHAR(500) NULL,result_json MEDIUMTEXT NULL,created_at DATETIME NOT NULL,started_at DATETIME NULL,finished_at DATETIME NULL,INDEX idx_claims_queue(status,created_at),INDEX idx_claims_user(requested_by,id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
db()->exec("CREATE TABLE IF NOT EXISTS claimsforce_task_status(profile VARCHAR(30) PRIMARY KEY,open_count INT UNSIGNED NOT NULL,updated_at DATETIME NOT NULL,source_job_id BIGINT UNSIGNED NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
db()->exec("INSERT IGNORE INTO claimsforce_task_status(profile,open_count,updated_at,source_job_id) VALUES('christian',1,NOW(),NULL),('jens',6,NOW(),NULL)");

function cqEnsureColumns():void{
    $columns=[
        'heartbeat_at'=>'DATETIME NULL',
        'attempt_count'=>'INT UNSIGNED NOT NULL DEFAULT 0',
        'phase'=>'VARCHAR(40) NULL',
        'progress_current'=>'INT UNSIGNED NOT NULL DEFAULT 0',
        'progress_total'=>'INT UNSIGNED NOT NULL DEFAULT 0',
        'diagnostic_json'=>'TEXT NULL',
        'schedule_key'=>'VARCHAR(80) NULL'
    ];
    $check=db()->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table AND COLUMN_NAME=:column');
    foreach($columns as$name=>$definition){
        $check->execute([':table'=>'claimsforce_import_jobs',':column'=>$name]);
        if((int)$check->fetchColumn()===0){
            try{db()->exec('ALTER TABLE claimsforce_import_jobs ADD COLUMN '.$name.' '.$definition);}
            catch(PDOException$e){
                $check->execute([':table'=>'claimsforce_import_jobs',':column'=>$name]);
                if((int)$check->fetchColumn()===0)throw$e;
            }
        }
    }
    $index=db()->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:table AND INDEX_NAME=:index');
    $index->execute([':table'=>'claimsforce_import_jobs',':index'=>'uq_claims_schedule_key']);
    if((int)$index->fetchColumn()===0){
        try{db()->exec('ALTER TABLE claimsforce_import_jobs ADD UNIQUE INDEX uq_claims_schedule_key(schedule_key)');}
        catch(PDOException$e){
            $index->execute([':table'=>'claimsforce_import_jobs',':index'=>'uq_claims_schedule_key']);
            if((int)$index->fetchColumn()===0)throw$e;
        }
    }
}
cqEnsureColumns();

function cqProfile(array$user):string{
    $email=mb_strtolower((string)($user['email']??''),'UTF-8');
    $name=mb_strtolower((string)($user['full_name']??''),'UTF-8');
    if(str_contains($email,'hr@')||str_contains($name,'holger'))return'holger';
    if(str_contains($email,'ms@')||str_contains($name,'marc'))return'marc';
    return'christian';
}
function cqVisibleProfiles(array$user):array{
    $email=mb_strtolower(trim((string)($user['email']??'')),'UTF-8');
    $name=mb_strtolower(trim((string)($user['full_name']??'')),'UTF-8');
    if(str_contains($email,'hr@')||str_contains($name,'holger'))return['holger'=>'Holger'];
    if(str_contains($email,'ms@')||str_contains($name,'marc'))return['marc'=>'Marc'];
    if(str_contains($email,'cw@')||str_contains($name,'christian'))return['christian'=>'Christian','jens'=>'Maurer'];
    $selected=(string)($_SESSION['svnet_selected_expert']??'christian');
    return match($selected){'holger'=>['holger'=>'Holger'],'marc'=>['marc'=>'Marc'],'jens'=>['jens'=>'Maurer'],default=>['christian'=>'Christian','jens'=>'Maurer']};
}
function cqRow(int$id):array{
    $s=db()->prepare('SELECT id,profile,status,message,result_json,created_at,started_at,heartbeat_at,attempt_count,phase,progress_current,progress_total,diagnostic_json,finished_at,requested_by FROM claimsforce_import_jobs WHERE id=:id LIMIT 1');
    $s->execute([':id'=>$id]);
    $row=$s->fetch(PDO::FETCH_ASSOC)?:[];
    if($row){
        $row['result']=$row['result_json']?json_decode((string)$row['result_json'],true):null;
        $row['diagnostic']=$row['diagnostic_json']?json_decode((string)$row['diagnostic_json'],true):null;
        unset($row['result_json'],$row['diagnostic_json']);
    }
    return$row;
}
function cqPhase(mixed$value):string{
    $phase=preg_replace('/[^A-Z0-9_-]/','',strtoupper((string)$value))?:'CF-RUN';
    return mb_substr($phase,0,40);
}
function cqFailStaleRuns():void{
    db()->exec("UPDATE claimsforce_import_jobs SET status='failed',message='Import wurde unterbrochen und aus Sicherheitsgründen nicht automatisch neu gestartet.',phase='CF-FAIL-STALE',heartbeat_at=NOW(),finished_at=NOW() WHERE status='running' AND COALESCE(heartbeat_at,started_at,created_at)<DATE_SUB(NOW(),INTERVAL 2 MINUTE)");
}

$action=(string)($_GET['action']??'status');
$body=$_SERVER['REQUEST_METHOD']==='POST'?requestBody():[];

if($action==='summary'){
    $visible=cqVisibleProfiles($user);
    $placeholders=implode(',',array_fill(0,count($visible),'?'));
    $s=db()->prepare('SELECT profile,open_count,updated_at FROM claimsforce_task_status WHERE profile IN ('.$placeholders.')');
    $s->execute(array_keys($visible));
    $stored=[];
    foreach($s->fetchAll(PDO::FETCH_ASSOC)as$row)$stored[(string)$row['profile']]=$row;
    $items=[];
    foreach($visible as$profile=>$label){
        $row=$stored[$profile]??null;
        $items[]=['profile'=>$profile,'label'=>$label,'open_count'=>$row===null?null:(int)$row['open_count'],'updated_at'=>$row['updated_at']??null];
    }
    apiJson(['ok'=>true,'items'=>$items]);
}

if($action==='enqueue'){
    $profile=cqProfile($user);
    if(($user['role']??'')==='administrator'&&in_array((string)($body['profile']??''),['christian','holger','marc','jens'],true))$profile=(string)$body['profile'];
    $stop=db()->prepare("UPDATE claimsforce_import_jobs SET status='failed',message='Durch einen neuen manuellen Import ersetzt.',phase='CF-FAIL-REPLACED',heartbeat_at=NOW(),finished_at=NOW() WHERE requested_by=:u AND profile=:p AND status IN ('queued','running')");
    $stop->execute([':p'=>$profile,':u'=>(string)($user['email']??'')]);
    $s=db()->prepare("INSERT INTO claimsforce_import_jobs(profile,status,requested_by,message,phase,created_at) VALUES(:p,'queued',:u,'Import wartet auf die zentrale Importstation.','CF-QUEUED',NOW())");
    $s->execute([':p'=>$profile,':u'=>(string)($user['email']??'')]);
    apiJson(['ok'=>true,'job'=>cqRow((int)db()->lastInsertId())]);
}
if($action==='status'){
    $row=cqRow((int)($_GET['id']??0));
    if(!$row)apiError(404,'Importauftrag nicht gefunden.');
    if(($user['role']??'')!=='administrator'&&!hash_equals((string)($user['email']??''),(string)$row['requested_by']))apiError(403,'Keine Berechtigung.');
    unset($row['requested_by']);
    apiJson(['ok'=>true,'job'=>$row]);
}
if($action==='mine'){
    $s=db()->prepare('SELECT id FROM claimsforce_import_jobs WHERE requested_by=:u ORDER BY id DESC LIMIT 20');
    $s->execute([':u'=>(string)($user['email']??'')]);
    $jobs=[];
    foreach($s->fetchAll(PDO::FETCH_ASSOC)as$row){
        $job=cqRow((int)$row['id']);
        unset($job['requested_by']);
        $jobs[]=$job;
    }
    apiJson(['ok'=>true,'jobs'=>$jobs]);
}
if(($user['role']??'')!=='administrator')apiError(403,'Nur die zentrale Importstation darf Aufträge übernehmen.');

if($action==='schedule'){
    $now=new DateTimeImmutable('now',new DateTimeZone('Europe/Berlin'));
    $weekday=(int)$now->format('N');
    $clock=(int)$now->format('Hi');
    if($weekday>5||$clock<230||$clock>=800)apiJson(['ok'=>true,'scheduled'=>false,'reason'=>'outside-window']);
    $profile='christian';
    $scheduleKey='claims-auto-'.$now->format('Y-m-d').'-'.$profile;
    $s=db()->prepare("INSERT IGNORE INTO claimsforce_import_jobs(profile,status,requested_by,message,phase,schedule_key,created_at) VALUES(:p,'queued',:u,'Automatischer Werktagsimport wartet auf die zentrale Importstation.','CF-AUTO-QUEUED',:k,NOW())");
    $s->execute([':p'=>$profile,':u'=>'system:claimsforce',':k'=>$scheduleKey]);
    apiJson(['ok'=>true,'scheduled'=>$s->rowCount()===1,'schedule_key'=>$scheduleKey]);
}

if($action==='active'){
    cqFailStaleRuns();
    $row=db()->query("SELECT id FROM claimsforce_import_jobs WHERE status='running' ORDER BY heartbeat_at DESC,id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    apiJson(['ok'=>true,'job'=>$row?cqRow((int)$row['id']):null]);
}
if($action==='claim'){
    cqFailStaleRuns();
    db()->beginTransaction();
    $row=db()->query("SELECT id FROM claimsforce_import_jobs WHERE status='queued' ORDER BY created_at,id LIMIT 1 FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
    if(!$row){db()->commit();apiJson(['ok'=>true,'job'=>null]);}
    $id=(int)$row['id'];
    $s=db()->prepare("UPDATE claimsforce_import_jobs SET status='running',message='Import wird auf der zentralen Station ausgeführt.',phase='CF-CLAIMED',started_at=NOW(),heartbeat_at=NOW(),attempt_count=attempt_count+1 WHERE id=:id AND status='queued'");
    $s->execute([':id'=>$id]);
    db()->commit();
    apiJson(['ok'=>true,'job'=>cqRow($id)]);
}
if($action==='heartbeat'){
    $id=(int)($body['id']??0);
    $message=mb_substr(trim((string)($body['message']??'Import läuft.')),0,500);
    $phase=cqPhase($body['phase']??'CF-RUN');
    $current=max(0,(int)($body['current']??0));
    $total=max(0,(int)($body['total']??0));
    $diagnostic=is_array($body['diagnostic']??null)?$body['diagnostic']:[];
    $s=db()->prepare("UPDATE claimsforce_import_jobs SET message=:m,phase=:p,progress_current=:c,progress_total=:t,diagnostic_json=:d,heartbeat_at=NOW() WHERE id=:id AND status='running'");
    $s->execute([':m'=>$message,':p'=>$phase,':c'=>$current,':t'=>$total,':d'=>json_encode($diagnostic,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),':id'=>$id]);
    if($s->rowCount()!==1)apiError(409,'Importauftrag läuft nicht mehr.');
    apiJson(['ok'=>true,'job'=>cqRow($id)]);
}
if($action==='complete'){
    $id=(int)($body['id']??0);
    $ok=($body['ok']??false)===true;
    $job=cqRow($id);
    $message=mb_substr(trim((string)($body['message']??($ok?'Import abgeschlossen.':'Import fehlgeschlagen.'))),0,500);
    $s=db()->prepare("UPDATE claimsforce_import_jobs SET status=:s,message=:m,phase=:p,result_json=:r,heartbeat_at=NOW(),finished_at=NOW() WHERE id=:id AND status='running'");
    $s->execute([':s'=>$ok?'done':'failed',':m'=>$message,':p'=>$ok?'CF-DONE-07':'CF-FAIL-99',':r'=>json_encode($body['result']??null,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),':id'=>$id]);
    if($s->rowCount()!==1)apiError(409,'Importauftrag wurde bereits abgeschlossen oder erneut eingeplant.');
    $result=is_array($body['result']??null)?$body['result']:[];
    if($ok&&$job&&is_numeric($result['openTasks']??null)){
        $count=max(0,(int)$result['openTasks']);
        $status=db()->prepare('INSERT INTO claimsforce_task_status(profile,open_count,updated_at,source_job_id) VALUES(:profile,:count,NOW(),:job) ON DUPLICATE KEY UPDATE open_count=VALUES(open_count),updated_at=VALUES(updated_at),source_job_id=VALUES(source_job_id)');
        $status->execute([':profile'=>(string)$job['profile'],':count'=>$count,':job'=>$id]);
    }
    apiJson(['ok'=>true,'job'=>cqRow($id)]);
}
apiError(404,'Unbekannte Aktion.');
