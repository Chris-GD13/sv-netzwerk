<?php
declare(strict_types=1);
function gfJobsTable():void{
    $pdo=db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS gf_ai_jobs(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,folder_id VARCHAR(190) NOT NULL,status VARCHAR(30) NOT NULL DEFAULT 'queued',progress INT NOT NULL DEFAULT 0,message VARCHAR(500) NULL,payload_json MEDIUMTEXT NOT NULL,result_json MEDIUMTEXT NULL,error_text MEDIUMTEXT NULL,created_by VARCHAR(190) NULL,created_at DATETIME NOT NULL,started_at DATETIME NULL,finished_at DATETIME NULL,INDEX idx_folder(folder_id),INDEX idx_status(status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $columns=[];
    foreach($pdo->query('SHOW COLUMNS FROM gf_ai_jobs')->fetchAll(PDO::FETCH_ASSOC) as $column){$columns[(string)($column['Field']??'')]=true;}
    $required=[
        'folder_id'=>'VARCHAR(190) NOT NULL',
        'status'=>"VARCHAR(30) NOT NULL DEFAULT 'queued'",
        'progress'=>'INT NOT NULL DEFAULT 0',
        'message'=>'VARCHAR(500) NULL',
        'payload_json'=>'MEDIUMTEXT NULL',
        'result_json'=>'MEDIUMTEXT NULL',
        'error_text'=>'MEDIUMTEXT NULL',
        'created_by'=>'VARCHAR(190) NULL',
        'created_at'=>'DATETIME NULL',
        'started_at'=>'DATETIME NULL',
        'heartbeat_at'=>'DATETIME NULL',
        'attempt_count'=>'INT NOT NULL DEFAULT 0',
        'finished_at'=>'DATETIME NULL',
    ];
    foreach($required as $name=>$definition){if(!isset($columns[$name])){$pdo->exec("ALTER TABLE gf_ai_jobs ADD COLUMN `{$name}` {$definition}");}}
}
