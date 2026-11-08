<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
require_once __DIR__.'/ionos-storage.php';
require_once __DIR__.'/document-review-storage.php';

commonHeaders();
$user=requireAuth();

function cfwSchema(): void {
    db()->exec("CREATE TABLE IF NOT EXISTS claimsforce_workflow_jobs (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      workflow_id VARCHAR(64) NOT NULL UNIQUE,
      folder_id VARCHAR(190) NOT NULL,
      claim_no VARCHAR(190) NOT NULL,
      insurer VARCHAR(255) NULL,
      order_type VARCHAR(190) NOT NULL,
      profile VARCHAR(190) NOT NULL,
      documents_json MEDIUMTEXT NOT NULL,
      instructions TEXT NULL,
      status VARCHAR(40) NOT NULL DEFAULT 'accepted',
      phase VARCHAR(60) NOT NULL DEFAULT 'aktenaufnahme',
      question_json TEXT NULL,
      log_json MEDIUMTEXT NULL,
      created_by INT UNSIGNED NOT NULL,
      created_at DATETIME NOT NULL,
      updated_at DATETIME NOT NULL,
      INDEX idx_cfw_user (created_by,updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function cfwJob(string $workflowId,array $user): array {
    $s=db()->prepare('SELECT * FROM claimsforce_workflow_jobs WHERE workflow_id=:id AND created_by=:uid LIMIT 1');
    $s->execute([':id'=>$workflowId,':uid'=>(int)$user['id']]);$row=$s->fetch();
    if(!$row)apiError(404,'Workflowauftrag nicht gefunden.');
    return $row;
}
function cfwView(array $row): array {
    $files=[];try{$files=array_map(fn($f)=>['id'=>$f['id'],'name'=>$f['name'],'mime'=>$f['mimeType']??'','size'=>(int)($f['size']??0)],drFiles((string)$row['folder_id']));}catch(Throwable){}
    return ['workflow_id'=>$row['workflow_id'],'claim_no'=>$row['claim_no'],'insurer'=>$row['insurer'],'order_type'=>$row['order_type'],'profile'=>$row['profile'],'documents'=>json_decode($row['documents_json'],true)?:[],'instructions'=>$row['instructions']??'','status'=>$row['status'],'phase'=>$row['phase'],'question'=>json_decode((string)($row['question_json']??''),true)?:null,'log'=>json_decode((string)($row['log_json']??''),true)?:[],'files'=>$files,'created_at'=>$row['created_at'],'updated_at'=>$row['updated_at']];
}
function cfwSaveLog(array $row,string $event,string $message): void {
    $log=json_decode((string)($row['log_json']??''),true);if(!is_array($log))$log=[];
    $log[]=['event'=>$event,'message'=>$message,'at'=>gmdate('c')];
    $s=db()->prepare('UPDATE claimsforce_workflow_jobs SET log_json=:log,updated_at=NOW() WHERE workflow_id=:id AND created_by=:uid');$s->execute([':log'=>json_encode($log,JSON_UNESCAPED_UNICODE),':id'=>$row['workflow_id'],':uid'=>(int)$row['created_by']]);
}
try {
    cfwSchema();
    $action=(string)($_GET['action']??'status');
    if($action==='create'&&$_SERVER['REQUEST_METHOD']==='POST'){
        $in=requestBody();$claim=trim((string)($in['claim_no']??''));$profile=trim((string)($in['profile']??''));$type=trim((string)($in['order_type']??''));
        if($claim===''||$profile===''||$type==='')apiError(400,'Schadennummer, Bearbeiterkonto und Auftragstyp sind erforderlich.');
        $documents=array_values(array_filter(array_map('strval',(array)($in['documents']??[]))));
        if(!$documents)apiError(400,'Mindestens ein Dokument muss ausgewählt werden.');
        $workflowId='CFW-'.gmdate('YmdHis').'-'.strtoupper(bin2hex(random_bytes(4)));$folder=drWorkspaceCreate($user);
        $s=db()->prepare('INSERT INTO claimsforce_workflow_jobs(workflow_id,folder_id,claim_no,insurer,order_type,profile,documents_json,instructions,log_json,created_by,created_at,updated_at) VALUES(:wid,:folder,:claim,:insurer,:type,:profile,:docs,:instructions,:log,:uid,NOW(),NOW())');
        $log=[['event'=>'accepted','message'=>'Auftrag angenommen.','at'=>gmdate('c')]];$s->execute([':wid'=>$workflowId,':folder'=>$folder,':claim'=>$claim,':insurer'=>trim((string)($in['insurer']??''))?:null,':type'=>$type,':profile'=>$profile,':docs'=>json_encode($documents,JSON_UNESCAPED_UNICODE),':instructions'=>trim((string)($in['instructions']??'')),':log'=>json_encode($log,JSON_UNESCAPED_UNICODE),':uid'=>(int)$user['id']]);
        apiJson(['ok'=>true,'job'=>cfwView(cfwJob($workflowId,$user))],201);
    }
    $workflowId=trim((string)($_GET['workflow_id']??($_POST['workflow_id']??'')));if($workflowId==='')apiError(400,'Workflow-ID fehlt.');$row=cfwJob($workflowId,$user);
    if($action==='upload'&&$_SERVER['REQUEST_METHOD']==='POST'){
        $uploads=$_FILES['files']??null;if(!$uploads||!is_array($uploads['name'])||count($uploads['name'])>20)apiError(400,'Bitte 1 bis 20 Dateien auswählen.');$total=0;$saved=[];
        foreach($uploads['name'] as $i=>$name){if(($uploads['error'][$i]??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($uploads['tmp_name'][$i]))throw new RuntimeException('Datei nicht vollständig hochgeladen: '.$name);$bytes=file_get_contents($uploads['tmp_name'][$i]);$total+=strlen($bytes);$name=basename(str_replace('\\','/',$name));if($total>80*1024*1024||strlen($bytes)>30*1024*1024)throw new RuntimeException('Maximal 30 MB je Datei und 80 MB insgesamt.');if(!preg_match('/\.(pdf|docx|png|jpe?g|webp)$/i',$name))throw new RuntimeException('Nicht unterstütztes Dateiformat: '.$name);$mime=mime_content_type($uploads['tmp_name'][$i])?:'application/octet-stream';$saved[]=drWorkspaceWrite((string)$row['folder_id'],$name,$mime,$bytes);}
        cfwSaveLog($row,'files_uploaded',count($saved).' Datei(en) übernommen.');apiJson(['ok'=>true,'files'=>$saved,'job'=>cfwView(cfwJob($workflowId,$user))]);
    }
    if($action==='download'){$file=drSelected((string)$row['folder_id'],trim((string)($_GET['file_id']??'')));header('Content-Type: '.($file['mime']??'application/octet-stream'));header('Content-Disposition: attachment; filename="'.str_replace(['"',"\r","\n"],'_',basename($file['name'])).'"');echo $file['bytes'];exit;}
    if($action==='status')apiJson(['ok'=>true,'job'=>cfwView($row)]);
    if($action==='pause'&&$_SERVER['REQUEST_METHOD']==='POST'){ $in=requestBody(); $question=['field'=>trim((string)($in['field']??'')),'prompt'=>trim((string)($in['prompt']??''))];if($question['field']===''||$question['prompt']==='')apiError(400,'Feld und Rückfrage sind erforderlich.');$s=db()->prepare("UPDATE claimsforce_workflow_jobs SET status='waiting_question',question_json=:q,updated_at=NOW() WHERE workflow_id=:id AND created_by=:uid");$s->execute([':q'=>json_encode($question,JSON_UNESCAPED_UNICODE),':id'=>$workflowId,':uid'=>(int)$user['id']]);apiJson(['ok'=>true,'job'=>cfwView(cfwJob($workflowId,$user))]); }
    apiError(404,'Unbekannte Workflowaktion.');
} catch(Throwable $e){ apiError(500,$e->getMessage()); }
