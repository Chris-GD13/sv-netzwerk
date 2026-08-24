<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
commonHeaders();
$user=requireAuth();

function cgDraftSchema():void{
  db()->exec("CREATE TABLE IF NOT EXISTS chatgpt_case_drafts(
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    folder_id VARCHAR(190) NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    title VARCHAR(500) NOT NULL,
    task_text MEDIUMTEXT NULL,
    content LONGTEXT NOT NULL,
    source VARCHAR(40) NOT NULL DEFAULT 'portal_copy',
    created_at DATETIME NOT NULL,
    INDEX idx_chatgpt_draft_folder(folder_id),
    INDEX idx_chatgpt_draft_user(user_id),
    INDEX idx_chatgpt_draft_created(created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

try{
  cgDraftSchema();
  $action=(string)($_GET['action']??'list');
  if($action==='save'){
    if($_SERVER['REQUEST_METHOD']!=='POST')apiError(405,'POST erforderlich.');
    $in=requestBody();$folder=trim((string)($in['folder_id']??''));requireCaseFolderAccess($folder,$user);
    $title=mb_substr(trim((string)($in['title']??'ChatGPT-Work-Entwurf')),0,500);
    $task=mb_substr(trim((string)($in['task']??'')),0,20000);
    $content=mb_substr(trim((string)($in['content']??'')),0,100000);
    if($content==='')apiError(400,'Bitte zuerst das Ergebnis aus ChatGPT Work einfügen.');
    $stmt=db()->prepare("INSERT INTO chatgpt_case_drafts(folder_id,user_id,title,task_text,content,source,created_at) VALUES(:folder,:user,:title,:task,:content,'portal_copy',UTC_TIMESTAMP())");
    $stmt->execute([':folder'=>$folder,':user'=>(int)$user['id'],':title'=>$title?:'ChatGPT-Work-Entwurf',':task'=>$task!==''?$task:null,':content'=>$content]);
    apiJson(['ok'=>true,'draft_id'=>(int)db()->lastInsertId(),'status'=>'draft']);
  }
  if($action==='delete'){
    if($_SERVER['REQUEST_METHOD']!=='POST')apiError(405,'POST erforderlich.');
    $in=requestBody();$folder=trim((string)($in['folder_id']??''));requireCaseFolderAccess($folder,$user);
    $draftId=(int)($in['draft_id']??0);if($draftId<=0)apiError(400,'Ungültiger Entwurf.');
    $stmt=db()->prepare('DELETE FROM chatgpt_case_drafts WHERE id=:id AND folder_id=:folder AND user_id=:user LIMIT 1');
    $stmt->execute([':id'=>$draftId,':folder'=>$folder,':user'=>(int)$user['id']]);
    if($stmt->rowCount()!==1)apiError(404,'Entwurf nicht gefunden.');
    apiJson(['ok'=>true,'deleted_id'=>$draftId]);
  }
  if($action==='list'){
    $folder=trim((string)($_GET['folder_id']??''));requireCaseFolderAccess($folder,$user);
    $stmt=db()->prepare('SELECT id,title,task_text,content,source,created_at FROM chatgpt_case_drafts WHERE folder_id=:folder AND user_id=:user ORDER BY created_at DESC LIMIT 20');
    $stmt->execute([':folder'=>$folder,':user'=>(int)$user['id']]);
    apiJson(['ok'=>true,'items'=>$stmt->fetchAll()?:[]]);
  }
  apiError(404,'Unbekannte Aktion.');
}catch(Throwable $e){error_log('[chatgpt-case-drafts] '.$e->getMessage());apiError(500,'ChatGPT-Entwurf konnte nicht verarbeitet werden.');}
