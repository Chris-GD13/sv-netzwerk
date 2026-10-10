<?php
declare(strict_types=1);
require_once __DIR__.'/gf-jobs-schema.php';
require_once __DIR__.'/profile-routing.php';
require_once __DIR__.'/case-work-core.php';
function cwQueueDocuments(int $turnId,string $folder,array $user,bool $saveText=false):int {
    $key='case-work-documents:'.$turnId;
    $lock=db()->prepare('SELECT GET_LOCK(:k,5)');$lock->execute([':k'=>$key]);
    if((int)$lock->fetchColumn()!==1)throw new RuntimeException('Dokumentauftrag wird bereits angelegt.');
    try {
        $s=db()->prepare('SELECT w.*,a.status,a.result_json FROM case_work_turns w JOIN case_analysis_jobs a ON a.id=w.analysis_id WHERE w.id=:id AND w.folder_id=:f AND w.user_id=:u');
        $s->execute([':id'=>$turnId,':f'=>$folder,':u'=>$user['id']]);$turn=$s->fetch(PDO::FETCH_ASSOC);
        if(!$turn)throw new RuntimeException('Dokumentauftrag gehört nicht zu diesem Fall und Benutzer.');
        if ($turn['document_id']) return (int)$turn['document_id'];
        if ($turn['status'] !== 'done') return 0;
        $result=json_decode((string)$turn['result_json'],true,512,JSON_THROW_ON_ERROR);
        $requests=cwDocumentRequests($result);
        if($saveText)$requests=[['type'=>'nachtrag_stellungnahme','instructions'=>'Diesen vollständigen Fall-Work-Text unverändert als Word speichern.']];
        if (!$requests) return 0;
        if(count($requests)===1&&$requests[0]['type']==='nachtrag_stellungnahme'){
            require_once __DIR__.'/case-work-docx.php';
            $text=trim((string)($result['reply_draft']??''));if($text==='')throw new RuntimeException('Kein vollständiger Text für Word vorhanden.');
            $number=trim((string)($result['case_no']??''));
            $bytes=cwTextDocx(($number!==''?'Schaden-Nr. '.$number."\n\n":'').$text);
            $parent='';
            foreach(ionosList(['q'=>"'".str_replace("'","\\'",$folder)."' in parents and trashed=false",'pageSize'=>1000])['files'] as $file){
                if(($file['mimeType']??'')==='application/vnd.google-apps.folder'&&str_starts_with($file['name'],'05_')){$parent=$file['id'];break;}
            }
            if($parent==='')$parent=ionosWrite(['name'=>'05_Berichte_Nachtraege','mimeType'=>'application/vnd.google-apps.folder','parents'=>[$folder]])['id'];
            $name=preg_replace('/[^\p{L}\p{N}._-]/u','_',$number?:'Fall').'_Nachtrag_Fall-Work_'.date('Y-m-d').'_'.$turnId.'.docx';
            $file=ionosWrite(['name'=>$name,'mimeType'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document','parents'=>[$parent]],$bytes);
            $created=['ok'=>true,'created'=>[['id'=>$file['id'],'name'=>$name,'type'=>'nachtrag_stellungnahme','template_used'=>null]],'count'=>1,'text_preserved'=>true];
            gfJobsTable();db()->beginTransaction();
            $s=db()->prepare("INSERT INTO gf_ai_jobs(folder_id,status,progress,message,payload_json,result_json,created_by,created_at,finished_at,heartbeat_at) VALUES(:f,'done',100,'Geprüfter Fall-Work-Text unverändert als Word gespeichert.',:p,:r,:u,NOW(),NOW(),NOW())");
            $s->execute([':f'=>$folder,':p'=>json_encode(['folder_id'=>$folder,'work_turn_id'=>$turnId],JSON_THROW_ON_ERROR),':r'=>json_encode($created,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),':u'=>$user['email']]);
            $id=(int)db()->lastInsertId();$s=db()->prepare('UPDATE case_work_turns SET document_id=:d WHERE id=:id');$s->execute([':d'=>$id,':id'=>$turnId]);db()->commit();return $id;
        }
        if (!is_executable('/usr/bin/php') || !is_executable('/usr/bin/setsid') || !function_exists('exec')) throw new RuntimeException('Dokumentverarbeitung ist auf diesem Server nicht verfügbar.');
        gfJobsTable();
        $documentUser=svnetIsBackofficeUser($user)&&$turn['expert_profile']!==''?svnetExpertIdentity($turn['expert_profile'],$user):$user;
        $payload=['folder_id'=>$folder, 'user_name'=>(string)($documentUser['full_name'] ?? $documentUser['email']),
            'order'=>['instructions'=>cwRules()."\nAktueller Betreiberauftrag:\n".$turn['instructions']."\nDokumentaufträge (Ausarbeitung, keine neuen Originalnachweise):\n".json_encode($requests,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),
                'outputs'=>array_column($requests,'type'),'work_mode'=>'vollpruefung','open_points'=>'beides','automatic'=>true]];
        db()->beginTransaction();
        $s=db()->prepare("INSERT INTO gf_ai_jobs(folder_id,status,progress,message,payload_json,created_by,created_at,heartbeat_at) VALUES(:f,'dispatching',0,'Dokumente aus Fall-Work werden erstellt.',:p,:u,NOW(),NOW())");
        $s->execute([':f'=>$folder,':p'=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR),':u'=>$user['email']]);
        $id=(int)db()->lastInsertId();
        $s = db()->prepare('UPDATE case_work_turns SET document_id=:d WHERE id=:id'); $s->execute([':d'=>$id, ':id'=>$turn['id']]);
        db()->commit();
        $output=[]; $exit=0;
        exec('/usr/bin/setsid -f /usr/bin/php '.escapeshellarg(__DIR__.'/gf-ai-worker-cli.php').' >/dev/null 2>&1 </dev/null',$output,$exit);
        if ($exit !== 0) throw new RuntimeException('Dokumentauftrag gespeichert, Hintergrundverarbeitung konnte nicht gestartet werden.');
        return $id;

    } catch(Throwable $error) {
        if(db()->inTransaction())db()->rollBack();throw $error;
    } finally {
        $s=db()->prepare('SELECT RELEASE_LOCK(:k)');$s->execute([':k'=>$key]);
    }
}
