<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
require_once __DIR__.'/profile-routing.php';
require_once __DIR__.'/private-order-classification.php';
require_once __DIR__.'/private-business-storage.php';
require_once __DIR__.'/private-business-route.php';
require_once __DIR__.'/private-business-mail.php';
require_once __DIR__.'/private-business-access.php';
commonHeaders();$user=requireAuth();if(!pbCanCreate($user))apiError(403,'Die Erstellung von Angeboten und Aufträgen ist für dieses Benutzerkonto nicht freigegeben.');$profile=pbPrivateProfile($user);
$action=(string)($_GET['action']??'status');$isPost=$_SERVER['REQUEST_METHOD']==='POST';
if($isPost){if(!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'application/json'))apiError(415,'JSON erforderlich.');if(isset($_SERVER['HTTP_ORIGIN'])&&parse_url($_SERVER['HTTP_ORIGIN'],PHP_URL_HOST)!==($_SERVER['HTTP_HOST']??''))apiError(403,'Ungültiger Ursprung.');}
try{
    $db=db();pbSchema($db);
    if($action==='status')apiJson(['ok'=>true,'profile'=>$profile,'defaults'=>pbDefaults($profile),'sender'=>pbMailSender($user),'legal_version'=>'2026-10-17']);
    if($action==='list'){$q=$db->prepare('SELECT id,number,kind,status,payload_json,updated_at FROM private_business_records WHERE profile=? ORDER BY updated_at DESC');$q->execute([$profile]);$rows=$q->fetchAll();foreach($rows as&$r){$r['customer']=pbCustomer(json_decode($r['payload_json'],true));unset($r['payload_json']);}apiJson(['ok'=>true,'items'=>$rows]);}
    if($action==='get'){$id=(string)($_GET['id']??'');if($id===''&&!empty($_GET['folder_id'])){$root=pbRoot($profile);poValidateTopOrder((string)$_GET['folder_id'],(string)$root['id']);$q=$db->prepare('SELECT id FROM private_business_records WHERE folder_id=? AND profile=? ORDER BY created_at DESC,kind DESC LIMIT 1');$q->execute([$_GET['folder_id'],$profile]);$id=(string)($q->fetchColumn()?:'');}apiJson(['ok'=>true,'record'=>$id===''?null:pbPublicRecord($db,pbRecord($db,$id,$profile))]);}
    if($action==='file'){
        $r=pbRecord($db,(string)($_GET['id']??''),$profile);$revision=(int)($_GET['revision']??$r['revision']);$kind=(string)($_GET['kind']??'packet');
        $f=pbCachedPdf($db,$r['id'],$revision,$kind);
        if(!$f){$q=$db->prepare('SELECT * FROM private_business_documents WHERE record_id=? AND revision=? AND kind=?');$q->execute([$r['id'],$revision,$kind]);$f=$q->fetch();if(!$f)throw new RuntimeException('PDF ist noch nicht gespeichert.',404);$root=pbRoot($profile);poValidateScope($f['item_id'],(string)$root['id']);$v=poRequest('https://graph.microsoft.com/v1.0/drives/'.rawurlencode(poDriveId()).'/items/'.rawurlencode($f['item_id']).'/content',true);$f['pdf_bytes']=$v['body'];if(!hash_equals($f['sha256'],hash('sha256',$f['pdf_bytes'])))throw new RuntimeException('Das PDF stimmt nicht mit dem gespeicherten Stand überein.');}
        header('Content-Type: application/pdf');header('Content-Length: '.strlen($f['pdf_bytes']));header('Content-Disposition: '.(isset($_GET['download'])?'attachment':'inline').'; filename="'.basename($f['filename']).'"');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');echo$f['pdf_bytes'];exit;
    }
    if(!$isPost)apiError(405,'POST erforderlich.');$in=requestBody();
    if($action==='route')apiJson(['ok'=>true,'route'=>pbRoute((array)($in['data']??[]))]);
    if($action==='save'){$r=pbSave($db,$profile,$in);if(empty($r['folder_id'])&&!empty($in['folder_id'])){$root=pbRoot($profile);$f=poValidateTopOrder((string)$in['folder_id'],(string)$root['id']);$q=$db->prepare('UPDATE private_business_records SET folder_id=? WHERE id=? AND folder_id IS NULL');$q->execute([$f['id'],$r['id']]);$r=pbRecord($db,$r['id'],$profile);}pbCachePdfDocuments($db,$r);if(empty($in['sync_cloud']))apiJson(['ok'=>true,'record'=>pbPublicRecord($db,$r)]);try{$r=pbArchive($db,$r);}catch(Throwable$e){apiJson(['ok'=>true,'record'=>pbPublicRecord($db,$r),'archive_error'=>$e->getMessage()]);}apiJson(['ok'=>true,'record'=>pbPublicRecord($db,$r)]);}
    if($action==='archive'){$r=pbRecord($db,(string)($in['id']??''),$profile);apiJson(['ok'=>true,'record'=>pbPublicRecord($db,pbArchive($db,$r))]);}
    if($action==='convert'){$r=pbConvert($db,$profile,(string)($in['id']??''),(bool)($in['confirmed']??false));pbCachePdfDocuments($db,$r);if(empty($in['sync_cloud']))apiJson(['ok'=>true,'record'=>pbPublicRecord($db,$r)]);try{$r=pbArchive($db,$r);}catch(Throwable$e){apiJson(['ok'=>true,'record'=>pbPublicRecord($db,$r),'archive_error'=>$e->getMessage()]);}apiJson(['ok'=>true,'record'=>pbPublicRecord($db,$r)]);}
    if($action==='send'){$r=pbRecord($db,(string)($in['id']??''),$profile);$root=pbRoot($profile);poValidateTopOrder((string)$r['folder_id'],(string)$root['id']);apiJson(['ok'=>true,'dispatch'=>pbSend($db,$r,$user,$in)]);}
    if($action==='dispatch-status'){$r=pbRecord($db,(string)($in['id']??''),$profile);$q=$db->prepare('SELECT * FROM private_business_dispatches WHERE id=? AND record_id=? AND profile=?');$q->execute([$in['dispatch_key']??'',$r['id'],$profile]);$d=$q->fetch();if(!$d)throw new RuntimeException('Versandvorgang nicht gefunden.',404);apiJson(['ok'=>true,'dispatch'=>pbDispatchVerify($db,$r,$d)]);}
    apiError(404,'Unbekannte Aktion.');
}catch(Throwable$e){$code=(int)$e->getCode();apiError(in_array($code,[400,403,404,409,429,503],true)?$code:422,$e->getMessage());}
