<?php
declare(strict_types=1);
define('SVNET_CASE_ANALYSIS_LIBRARY_ONLY', true);
require_once __DIR__.'/case-analysis.php';
require_once __DIR__.'/case-work-core.php';
require_once __DIR__.'/claimsforce-queue-utils.php';
require_once __DIR__.'/gf-jobs-schema.php';
require_once __DIR__.'/profile-routing.php';

function cwRows(string $folder, int $userId): array {
    $s = db()->prepare('SELECT w.*,a.status,a.message,a.result_json,a.updated_at FROM case_work_turns w LEFT JOIN case_analysis_jobs a ON a.id=w.analysis_id WHERE w.folder_id=:f AND w.user_id=:u ORDER BY w.id');
    $s->execute([':f'=>$folder, ':u'=>$userId]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') apiError(405, 'POST erforderlich.');
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '' && parse_url($origin, PHP_URL_HOST) !== ($_SERVER['HTTP_HOST'] ?? '')) apiError(403, 'Fremder Ursprung.');
    $body = requestBody(); $folder = trim((string)($body['folder_id'] ?? '')); $uid = (int)$user['id'];
    requireCaseFolderAccess($folder, $user); caJobs();
    db()->exec("CREATE TABLE IF NOT EXISTS case_work_turns (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, folder_id VARCHAR(190) NOT NULL, user_id BIGINT NOT NULL,
        request_key VARCHAR(64) NOT NULL, instructions TEXT NOT NULL, task_id TEXT NOT NULL, analysis_id BIGINT UNSIGNED NULL,
        import_id BIGINT UNSIGNED NULL, document_id BIGINT UNSIGNED NULL, expert_profile VARCHAR(30) NOT NULL DEFAULT '', created_at DATETIME NOT NULL,
        UNIQUE KEY request_once(user_id,request_key), INDEX work_context(user_id,folder_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $columns=db()->query('SHOW COLUMNS FROM case_work_turns')->fetchAll(PDO::FETCH_COLUMN);
    if(!in_array('expert_profile',$columns,true))db()->exec("ALTER TABLE case_work_turns ADD COLUMN expert_profile VARCHAR(30) NOT NULL DEFAULT ''");
    $profile=svnetIsBackofficeUser($user)?svnetSelectedProfile($user,(string)($_SESSION['svnet_selected_expert']??'')):svnetUserProfile($user);
    $effective=svnetIsBackofficeUser($user)?svnetExpertIdentity($profile,$user):$user;
    $action = (string)($body['action'] ?? 'history');
    if ($action === 'history') {
        $rows = cwRows($folder, $uid);
        foreach ($rows as &$row) {
            if ($row['status']==='running' && strtotime((string)$row['updated_at'])<time()-3600) {
                caUpdate((int)$row['analysis_id'],'failed','Bearbeitung unterbrochen. Der gespeicherte Auftrag kann erneut gestellt werden.');
                $row['status']='failed';$row['message']='Bearbeitung unterbrochen. Bitte Auftrag erneut stellen.';
            }
            $row['result'] = $row['result_json'] ? json_decode($row['result_json'], true, 512, JSON_THROW_ON_ERROR) : null;
            unset($row['result_json'], $row['user_id'], $row['request_key']);
        }
        unset($row);
        apiJson(['ok'=>true, 'turns'=>$rows]);
    }
    $lockKey = 'case-work:'.substr(hash('sha256', $folder.'|'.$uid), 0, 40);
    $lock = db()->prepare('SELECT GET_LOCK(:k,0)'); $lock->execute([':k'=>$lockKey]);
    if ((int)$lock->fetchColumn() !== 1) apiError(409, 'Dieser Fall wird bereits bearbeitet. Bitte Status aktualisieren.');
    $rows = cwRows($folder, $uid);
    if ($action === 'documents') {
        require_once __DIR__.'/case-work-documents.php';
        apiJson(['ok'=>true,'job_id'=>cwQueueDocuments((int)($body['turn_id']??0),$folder,$user)]);
    }
    if ($action !== 'start') apiError(400, 'Unbekannte Aktion.');
    $key = (string)($body['request_key'] ?? '');
    if (!preg_match('/^[a-zA-Z0-9-]{16,64}$/', $key)) apiError(400, 'Auftragskennung fehlt.');
    foreach ($rows as $row) {
        if ($row['request_key'] === $key) apiJson(['ok'=>true, 'job_id'=>(int)$row['analysis_id'], 'turn_id'=>(int)$row['id']]);
        if ($row['status'] === 'running') apiError(409, 'Ein Arbeitsauftrag läuft bereits. Bitte Status aktualisieren.');
    }
    $instructions = trim((string)($body['instructions'] ?? '')) ?: 'Bitte abarbeiten';
    if (mb_strlen($instructions) > 12000) apiError(400, 'Arbeitsauftrag zu lang.');
    if (trim(env('OPENAI_API_KEY', '')) === '') throw new RuntimeException('OpenAI-Anbindung fehlt.');
    $history = cwHistoryContext($rows); $taskId = trim((string)($body['task_id'] ?? ''));
    $importId = (int)($body['import_id'] ?? 0); $import = null;
    if ($importId) {
        $s = db()->prepare('SELECT * FROM claimsforce_import_jobs WHERE id=:id AND requested_by=:u');
        $s->execute([':id'=>$importId, ':u'=>$user['email']]); $import = $s->fetch(PDO::FETCH_ASSOC);
        $meta = [];
        foreach (caCaseFiles($folder) as $file) if ($file['name'] === '00_Falldaten.json') { $meta=json_decode(ionosBytes($file['id']),true); break; }
        if (!$import || $import['sync_mode'] !== 'single' || caseNumberKey((string)$import['claim_number']) !== caseNumberKey((string)($meta['schaden_nr'] ?? ''))) apiError(409, 'Import passt nicht zum aktiven Fall.');
        if ($import['status'] !== 'done') apiError(409, 'Vollimport ist noch nicht erfolgreich abgeschlossen.');
        $importResult = json_decode((string)$import['result_json'], true) ?: [];
        if (!cqSingleResultValid($import, $importResult)) apiError(409, 'Import hat keinen vollständigen Einzelfallnachweis geliefert.');
        $import = ['id'=>$importId, 'status'=>'done', 'finished_at'=>$import['finished_at'], 'result'=>$importResult];
    }
    db()->beginTransaction();
    $s=db()->prepare("INSERT INTO case_analysis_jobs(folder_id,task_key,user_id,status,message,created_at,updated_at) VALUES(:f,:t,:u,'running','Fallbearbeitung angenommen.',NOW(),NOW())");
    $s->execute([':f'=>$folder, ':t'=>hash('sha256', $taskId), ':u'=>$uid]); $job=(int)db()->lastInsertId();
    $s=db()->prepare('INSERT INTO case_work_turns(folder_id,user_id,request_key,instructions,task_id,analysis_id,import_id,expert_profile,created_at) VALUES(:f,:u,:k,:i,:t,:a,:p,:profile,NOW())');
    $s->execute([':f'=>$folder, ':u'=>$uid, ':k'=>$key, ':i'=>$instructions, ':t'=>$taskId, ':a'=>$job, ':p'=>$importId ?: null, ':profile'=>$profile]);
    $turn=(int)db()->lastInsertId(); db()->commit();
    if (!is_executable('/usr/bin/php') || !is_executable('/usr/bin/setsid') || !function_exists('exec')) {
        caUpdate($job,'failed','Hintergrundverarbeitung auf diesem Server nicht verfügbar.');
        throw new RuntimeException('Hintergrundverarbeitung auf diesem Server nicht verfügbar.');
    }
    $output=[];$exit=0;
    exec('/usr/bin/setsid -f /usr/bin/php '.escapeshellarg(__DIR__.'/case-work-worker-cli.php').' '.(int)$turn.' >/dev/null 2>&1 </dev/null',$output,$exit);
    if($exit!==0){caUpdate($job,'failed','Hintergrundverarbeitung konnte nicht gestartet werden.');throw new RuntimeException('Hintergrundverarbeitung konnte nicht gestartet werden.');}
    apiJson(['ok'=>true, 'job_id'=>$job, 'turn_id'=>$turn]);
} catch (Throwable $error) {
    if (db()->inTransaction()) db()->rollBack();
    error_log('[case-work] '.$error->getMessage());
    apiError(500, $error->getMessage());
} finally {
    if (isset($lockKey)) { $s=db()->prepare('SELECT RELEASE_LOCK(:k)'); $s->execute([':k'=>$lockKey]); }
}
