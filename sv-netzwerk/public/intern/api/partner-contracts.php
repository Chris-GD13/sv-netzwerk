<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
require_once __DIR__.'/partner-contract-document.php';
commonHeaders();$user=requireAuth();header('Cache-Control: private, no-store');
$owner=(int)$user['id'];$action=(string)($_GET['action']??'status');
$_SESSION['partner_csrf']??=bin2hex(random_bytes(32));
try {
    if($action==='status')apiJson(['ok'=>true,'defaults'=>pcFields(),'version'=>PC_VERSION,'csrf'=>$_SESSION['partner_csrf']]);
    $db=db();pcSchema($db);
    if($action==='list'){$q=$db->prepare('SELECT id,revision,payload_json,updated_at,archived FROM partner_contracts WHERE owner_id=? AND archived=? ORDER BY updated_at DESC');$q->execute([$owner,($_GET['archived']??'')==='1'?1:0]);$items=[];foreach($q->fetchAll() as $row){$data=json_decode($row['payload_json'],true);unset($row['payload_json']);$row['name']=$data['partner_name']?:'Unbenannter Entwurf';$items[]=$row;}apiJson(['ok'=>true,'items'=>$items]);}
    if(in_array($action,['get','preview','pdf','template'],true)){
        $r=$action==='template'?['data'=>pcFields(),'revision'=>0]:pcGet($db,(string)($_GET['id']??''),$owner);
        if($action==='get')apiJson(['ok'=>true,'record'=>$r]);
        if($action==='preview'){header('Content-Type: text/html; charset=utf-8');header('X-Frame-Options: SAMEORIGIN');echo pcHtml($r['data'],(int)$r['revision']);exit;}
        $pdf=pcPdf($r['data'],(int)$r['revision']);header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="SV-Netzwerk-Partnervereinbarung-R'.(int)$r['revision'].'.pdf"');header('Content-Length: '.strlen($pdf));echo $pdf;exit;
    }
    if($_SERVER['REQUEST_METHOD']!=='POST')apiError(405,'POST erforderlich.');
    if(!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'application/json'))apiError(415,'JSON erforderlich.');
    if(!hash_equals($_SESSION['partner_csrf'],(string)($_SERVER['HTTP_X_PARTNER_CSRF']??'')))apiError(403,'Sitzungsprüfung fehlgeschlagen. Bitte Seite neu öffnen.');
    if(isset($_SERVER['HTTP_ORIGIN'])&&parse_url($_SERVER['HTTP_ORIGIN'],PHP_URL_HOST)!==($_SERVER['HTTP_HOST']??''))apiError(403,'Ungültiger Ursprung.');
    $in=requestBody();
    if($action==='save')apiJson(['ok'=>true,'record'=>pcSave($db,(string)($in['id']??''),$owner,(int)($in['revision']??0),(array)($in['data']??[]))]);
    if($action==='archive'||$action==='restore'){$r=pcGet($db,(string)($in['id']??''),$owner);$q=$db->prepare('UPDATE partner_contracts SET archived=?,revision=revision+1,updated_at=? WHERE id=? AND owner_id=? AND revision=?');$q->execute([$action==='archive'?1:0,gmdate('Y-m-d H:i:s'),$r['id'],$owner,(int)($in['revision']??0)]);if($q->rowCount()!==1)throw new RuntimeException('Zwischenzeitliche Änderung. Bitte neu öffnen.',409);apiJson(['ok'=>true]);}
    apiError(400,'Unbekannte Aktion.');
}catch(InvalidArgumentException $e){apiError(422,$e->getMessage());}
catch(RuntimeException $e){$code=(int)$e->getCode();if(in_array($code,[404,409],true))apiError($code,$e->getMessage());error_log('partner-contracts: '.$e->getMessage());apiError(500,'Der Vertrag konnte nicht verarbeitet werden. Bitte erneut versuchen.');}
catch(Throwable $e){error_log('partner-contracts: '.$e->getMessage());apiError(500,'Partnerablage derzeit nicht verfügbar. Ihre Eingaben bleiben im Formular.');}
