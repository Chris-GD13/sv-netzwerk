<?php
declare(strict_types=1);

require_once __DIR__.'/config.php';
require_once __DIR__.'/profile-routing.php';
commonHeaders();
$portalUser=requireAuth();
if(!in_array((string)($portalUser['role']??''),['administrator','projektleiter','pruefer','sachverstaendiger'],true))apiError(403,'Keine Berechtigung.');

try{$profile=svnetSelectedProfile($portalUser,(string)($_SESSION['svnet_selected_expert']??''));}
catch(InvalidArgumentException $error){apiError(409,$error->getMessage());}
$caseUser=svnetIsBackofficeUser($portalUser)?svnetExpertIdentity($profile,$portalUser):$portalUser;
$actor=(string)($portalUser['email']??'');

db()->exec("CREATE TABLE IF NOT EXISTS portal_work_items(
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  profile VARCHAR(30) NOT NULL,
  case_folder_id VARCHAR(190) NULL,
  case_no VARCHAR(190) NULL,
  title VARCHAR(300) NOT NULL,
  due_at DATETIME NOT NULL,
  priority VARCHAR(20) NOT NULL DEFAULT 'normal',
  note TEXT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'open',
  created_by VARCHAR(255) NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  completed_at DATETIME NULL,
  INDEX idx_work_profile_status_due(profile,status,due_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
db()->exec("CREATE TABLE IF NOT EXISTS portal_field_notes(
  profile VARCHAR(30) NOT NULL,
  case_folder_id VARCHAR(190) NOT NULL,
  case_no VARCHAR(190) NULL,
  note MEDIUMTEXT NULL,
  updated_by VARCHAR(255) NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY(profile,case_folder_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

function wcText(mixed $value,int $limit):string{return mb_substr(trim((string)$value),0,$limit);}
function wcDate(mixed $value):string{
  $raw=wcText($value,40);
  $date=DateTimeImmutable::createFromFormat('Y-m-d\TH:i',$raw,new DateTimeZone('Europe/Berlin'));
  if(!$date)$date=DateTimeImmutable::createFromFormat('Y-m-d H:i:s',$raw,new DateTimeZone('Europe/Berlin'));
  if(!$date)apiError(400,'Bitte eine gültige Fälligkeit angeben.');
  return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}
function wcItem(array $row):array{
  foreach(['id']as$key)$row[$key]=(int)$row[$key];
  return $row;
}

$action=(string)($_GET['action']??'list');
$body=$_SERVER['REQUEST_METHOD']==='POST'?requestBody():[];

if($action==='list'){
  $stmt=db()->prepare("SELECT id,case_folder_id,case_no,title,due_at,priority,note,status,created_at,updated_at,completed_at FROM portal_work_items WHERE profile=:p AND (status='open' OR updated_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY)) ORDER BY status='open' DESC,due_at ASC,id DESC LIMIT 250");
  $stmt->execute([':p'=>$profile]);
  $items=array_map('wcItem',$stmt->fetchAll(PDO::FETCH_ASSOC));
  $open=array_filter($items,static fn(array $item):bool=>$item['status']==='open');
  $now=time();$today=(new DateTimeImmutable('now',new DateTimeZone('Europe/Berlin')))->format('Y-m-d');
  $overdue=0;$dueToday=0;
  foreach($open as$item){$stamp=strtotime((string)$item['due_at']);if($stamp<$now)$overdue++;if((new DateTimeImmutable((string)$item['due_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Europe/Berlin'))->format('Y-m-d')===$today)$dueToday++;}
  apiJson(['ok'=>true,'profile'=>$profile,'items'=>$items,'summary'=>['open'=>count($open),'overdue'=>$overdue,'due_today'=>$dueToday,'completed_30d'=>count($items)-count($open)]]);
}

if($action==='save'){
  if($_SERVER['REQUEST_METHOD']!=='POST')apiError(405,'POST erforderlich.');
  $id=(int)($body['id']??0);$folder=wcText($body['case_folder_id']??'',190);$caseNo=wcText($body['case_no']??'',190);$title=wcText($body['title']??'',300);$note=wcText($body['note']??'',10000);$priority=wcText($body['priority']??'normal',20);
  if($title==='')apiError(400,'Bitte eine Aufgabe eintragen.');
  if(!in_array($priority,['normal','hoch','dringend'],true))apiError(400,'Ungültige Priorität.');
  if($folder!=='')requireCaseFolderAccess($folder,$caseUser);
  $due=wcDate($body['due_at']??'');
  if($id>0){
    $exists=db()->prepare("SELECT id FROM portal_work_items WHERE id=:id AND profile=:p AND status='open' LIMIT 1");$exists->execute([':id'=>$id,':p'=>$profile]);
    if(!$exists->fetchColumn())apiError(404,'Offene Wiedervorlage nicht gefunden.');
    $stmt=db()->prepare("UPDATE portal_work_items SET case_folder_id=:f,case_no=:c,title=:t,due_at=:d,priority=:r,note=:n,updated_at=UTC_TIMESTAMP() WHERE id=:id AND profile=:p AND status='open'");
    $stmt->execute([':f'=>$folder?:null,':c'=>$caseNo?:null,':t'=>$title,':d'=>$due,':r'=>$priority,':n'=>$note?:null,':id'=>$id,':p'=>$profile]);
  }else{
    $stmt=db()->prepare("INSERT INTO portal_work_items(profile,case_folder_id,case_no,title,due_at,priority,note,status,created_by,created_at,updated_at) VALUES(:p,:f,:c,:t,:d,:r,:n,'open',:u,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $stmt->execute([':p'=>$profile,':f'=>$folder?:null,':c'=>$caseNo?:null,':t'=>$title,':d'=>$due,':r'=>$priority,':n'=>$note?:null,':u'=>$actor]);$id=(int)db()->lastInsertId();
  }
  apiJson(['ok'=>true,'id'=>$id]);
}

if($action==='complete'){
  if($_SERVER['REQUEST_METHOD']!=='POST')apiError(405,'POST erforderlich.');
  $id=(int)($body['id']??0);$stmt=db()->prepare("UPDATE portal_work_items SET status='done',completed_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() WHERE id=:id AND profile=:p AND status='open'");$stmt->execute([':id'=>$id,':p'=>$profile]);
  if($stmt->rowCount()===0)apiError(404,'Offene Wiedervorlage nicht gefunden.');
  apiJson(['ok'=>true,'id'=>$id]);
}

if($action==='note'){
  $folder=wcText(($_SERVER['REQUEST_METHOD']==='POST'?$body:$_GET)['case_folder_id']??'',190);if($folder==='')apiError(400,'Bitte zuerst einen Fall öffnen.');requireCaseFolderAccess($folder,$caseUser);
  if($_SERVER['REQUEST_METHOD']==='GET'){$stmt=db()->prepare('SELECT case_no,note,updated_at FROM portal_field_notes WHERE profile=:p AND case_folder_id=:f LIMIT 1');$stmt->execute([':p'=>$profile,':f'=>$folder]);apiJson(['ok'=>true,'draft'=>$stmt->fetch(PDO::FETCH_ASSOC)?:null]);}
  $caseNo=wcText($body['case_no']??'',190);$note=wcText($body['note']??'',50000);
  $stmt=db()->prepare('INSERT INTO portal_field_notes(profile,case_folder_id,case_no,note,updated_by,updated_at) VALUES(:p,:f,:c,:n,:u,UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE case_no=VALUES(case_no),note=VALUES(note),updated_by=VALUES(updated_by),updated_at=UTC_TIMESTAMP()');
  $stmt->execute([':p'=>$profile,':f'=>$folder,':c'=>$caseNo?:null,':n'=>$note?:null,':u'=>$actor]);apiJson(['ok'=>true]);
}

apiError(404,'Unbekannte Aktion.');
