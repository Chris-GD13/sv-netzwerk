<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
commonHeaders();
$user=requireAuth();
if(!in_array((string)($user['role']??''),['administrator','projektleiter','pruefer','sachverstaendiger'],true)) apiError(403,'Keine Berechtigung.');

function bdSchema():void{
  db()->exec("CREATE TABLE IF NOT EXISTS bki_calculation_drafts(
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    draft_key VARCHAR(255) NOT NULL,
    folder_id VARCHAR(190) NULL,
    case_no VARCHAR(190) NULL,
    damage_type VARCHAR(190) NULL,
    object_name VARCHAR(500) NULL,
    title VARCHAR(500) NULL,
    state_json LONGTEXT NOT NULL,
    updated_by VARCHAR(190) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_bki_draft_user_key(updated_by,draft_key),
    INDEX idx_bki_draft_folder(folder_id),
    INDEX idx_bki_draft_updated(updated_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function bdUser():string{return trim((string)($GLOBALS['user']['email']??$GLOBALS['user']['full_name']??'portal-user'));}
function bdRow(array $r):array{return[
  'id'=>(int)$r['id'],'draft_key'=>(string)$r['draft_key'],'folder_id'=>(string)($r['folder_id']??''),'case_no'=>(string)($r['case_no']??''),'damage_type'=>(string)($r['damage_type']??''),'object_name'=>(string)($r['object_name']??''),'title'=>(string)($r['title']??''),'updated_at'=>(string)$r['updated_at']
];}

bdSchema();
$action=(string)($_GET['action']??'list');
try{
  if($action==='save'){
    if($_SERVER['REQUEST_METHOD']!=='POST')apiError(405,'POST erforderlich.');
    $in=requestBody();$key=trim((string)($in['draft_key']??''));if($key==='')throw new RuntimeException('Entwurfskennung fehlt.');
    $state=is_array($in['state']??null)?$in['state']:[];$case=is_array($in['case_meta']??null)?$in['case_meta']:[];$folder=trim((string)($in['folder_id']??''));if($folder!=='')requireCaseFolderAccess($folder,$user);$who=bdUser();
    $title=trim((string)($in['title']??''));if($title==='')$title=trim(((string)($case['schaden_nr']??'')).' '.((string)($case['schadenart']??'')))?:'BKI-Kalkulationsentwurf';
    $sql='INSERT INTO bki_calculation_drafts(draft_key,folder_id,case_no,damage_type,object_name,title,state_json,updated_by,created_at,updated_at) VALUES(:k,:f,:c,:d,:o,:t,:s,:u,NOW(),NOW()) ON DUPLICATE KEY UPDATE folder_id=VALUES(folder_id),case_no=VALUES(case_no),damage_type=VALUES(damage_type),object_name=VALUES(object_name),title=VALUES(title),state_json=VALUES(state_json),updated_at=NOW()';
    $s=db()->prepare($sql);$s->execute([':k'=>$key,':f'=>$folder!==''?$folder:null,':c'=>trim((string)($case['schaden_nr']??''))?:null,':d'=>trim((string)($case['schadenart']??''))?:null,':o'=>trim((string)($case['vn_objekt']??''))?:null,':t'=>$title,':s'=>json_encode($state,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),':u'=>$who]);
    apiJson(['ok'=>true,'draft_key'=>$key,'updated_at'=>nowUtc()]);
  }
  if($action==='get'){
    $key=trim((string)($_GET['draft_key']??''));if($key==='')apiError(400,'Entwurfskennung fehlt.');$s=db()->prepare('SELECT * FROM bki_calculation_drafts WHERE updated_by=:u AND draft_key=:k LIMIT 1');$s->execute([':u'=>bdUser(),':k'=>$key]);$r=$s->fetch();if(!$r)apiError(404,'Entwurf nicht gefunden.');$out=bdRow($r);$out['state']=json_decode((string)$r['state_json'],true)?:[];apiJson(['ok'=>true,'item'=>$out]);
  }
  if($action==='list'){
    $folder=trim((string)($_GET['folder_id']??''));if($folder!=='')requireCaseFolderAccess($folder,$user);if($folder!=='' ){$s=db()->prepare('SELECT * FROM bki_calculation_drafts WHERE updated_by=:u AND folder_id=:f ORDER BY updated_at DESC LIMIT 30');$s->execute([':u'=>bdUser(),':f'=>$folder]);}else{$s=db()->prepare('SELECT * FROM bki_calculation_drafts WHERE updated_by=:u AND (folder_id IS NULL OR folder_id="") ORDER BY updated_at DESC LIMIT 30');$s->execute([':u'=>bdUser()]);}$items=[];foreach($s->fetchAll()as$r)$items[]=bdRow($r);apiJson(['ok'=>true,'items'=>$items]);
  }
  if($action==='delete'){
    if($_SERVER['REQUEST_METHOD']!=='POST')apiError(405,'POST erforderlich.');$in=requestBody();$key=trim((string)($in['draft_key']??''));if($key==='')apiError(400,'Entwurfskennung fehlt.');$s=db()->prepare('DELETE FROM bki_calculation_drafts WHERE updated_by=:u AND draft_key=:k');$s->execute([':u'=>bdUser(),':k'=>$key]);apiJson(['ok'=>true]);
  }
  apiError(404,'Unbekannte Aktion.');
}catch(Throwable $e){error_log('[bki-drafts] '.$e->getMessage());apiError(500,$e->getMessage());}
