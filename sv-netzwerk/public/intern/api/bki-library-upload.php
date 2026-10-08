<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';require_once __DIR__.'/bki-library.php';
commonHeaders();$user=requireAuth();
if(!in_array($user['role']??'',['administrator','projektleiter','pruefer','sachverstaendiger'],true))apiError(403,'Keine Berechtigung.');
$action=$_GET['action']??'status';
try {
  if($action==='status')apiJson(bklStatus());
  if($action==='pdf'){
    $id=(string)($_GET['id']??'');
    if($id===''&&in_array($_GET['kind']??'',['lifetime','rpa'],true)){$s=bklDb()->prepare('SELECT id FROM documents WHERE kind=? LIMIT 1');$s->execute([$_GET['kind']]);$id=(string)$s->fetchColumn();}
    if(!preg_match('/^[a-f0-9]{64}$/D',$id))apiError(404,'Quelle nicht gefunden.');
    $path=bklRoot().'/'.$id.'.pdf';if(!is_file($path))apiError(404,'Quelle nicht gefunden.');
    header('Content-Type: application/pdf');header('Content-Disposition: inline; filename="Preisquelle.pdf"');readfile($path);exit;
  }
  if($_SERVER['REQUEST_METHOD']!=='POST')apiError(405,'POST erforderlich.');
  if(($user['role']??'')!=='administrator')apiError(403,'Preisbestände werden vom Administrator eingespielt.');
  $origin=$_SERVER['HTTP_ORIGIN']??'';if($origin!==''&&parse_url($origin,PHP_URL_HOST)!==($_SERVER['HTTP_HOST']??''))apiError(403,'Fremder Ursprung.');
  $owner=hash('sha256',(string)($user['id']??$user['email']));
  if($action==='begin'){
    $data=requestBody();$size=(int)($data['size']??0);$sha=(string)($data['sha256']??'');$kind=$data['kind']??'pdf';
    if($size<1||$size>200000000||!preg_match('/^[a-f0-9]{64}$/D',$sha)||!in_array($kind,['pdf','index'],true))apiError(422,'Ungültige Datei.');
    $id=bin2hex(random_bytes(16));file_put_contents(bklRoot().'/'.$id.'.upload.json',json_encode(['owner'=>$owner,'size'=>$size,'sha256'=>$sha,'kind'=>$kind,'offset'=>0,'created'=>time()]));apiJson(['id'=>$id]);
  }
  $id=(string)($_GET['id']??'');if(!preg_match('/^[a-f0-9]{32}$/D',$id))apiError(422,'Ungültiger Upload.');
  $metaPath=bklRoot().'/'.$id.'.upload.json';$lock=fopen($metaPath,'r+');if(!$lock)apiError(404,'Upload nicht gefunden.');flock($lock,LOCK_EX);$meta=json_decode(stream_get_contents($lock),true);
  if(!is_array($meta)||!hash_equals($owner,$meta['owner'])||time()-$meta['created']>86400)apiError(403,'Upload nicht freigegeben.');
  $part=bklRoot().'/'.$id.'.part';
  if($action==='chunk'){
    $bytes=file_get_contents('php://input',false,null,0,2097153);$offset=(int)($_GET['offset']??-1);
    if(strlen($bytes)>2097152||$offset!==$meta['offset']||$offset+strlen($bytes)>$meta['size'])apiError(409,'Upload-Reihenfolge oder Größe ungültig.');
    if(file_put_contents($part,$bytes,FILE_APPEND)!==strlen($bytes))throw new RuntimeException('Upload konnte nicht gespeichert werden.');
    $meta['offset']+=strlen($bytes);rewind($lock);ftruncate($lock,0);fwrite($lock,json_encode($meta));flock($lock,LOCK_UN);fclose($lock);apiJson(['offset'=>$meta['offset']]);
  }
  if($action==='finish'){
    if(!is_file($part)||filesize($part)!==$meta['size']||!hash_equals($meta['sha256'],hash_file('sha256',$part)))apiError(422,'Dateiprüfsumme stimmt nicht überein.');
    if($meta['kind']==='index')$result=bklImport($part);
    else { $handle=fopen($part,'rb');$magic=fread($handle,5);fclose($handle);if($magic!=='%PDF-')apiError(422,'Keine PDF-Datei.');$target=bklRoot().'/'.$meta['sha256'].'.pdf';if(!rename($part,$target))throw new RuntimeException('Original konnte nicht abgelegt werden.');chmod($target,0660);$result=['sha256'=>$meta['sha256'],'size'=>$meta['size'],'verified'=>true,'backend'=>'IONOS']; }
    flock($lock,LOCK_UN);fclose($lock);@unlink($part);@unlink($metaPath);apiJson($result);
  }
  apiError(400,'Unbekannte Aktion.');
}catch(Throwable $e){apiError(500,$e->getMessage());}
