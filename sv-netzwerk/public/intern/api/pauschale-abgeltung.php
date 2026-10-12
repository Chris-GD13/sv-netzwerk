<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
require_once __DIR__.'/ionos-storage.php';
require_once __DIR__.'/pauschale-abgeltung-core.php';
commonHeaders();
$user=requireAuth();
if(!in_array($user['role']??'',['administrator','projektleiter','pruefer','sachverstaendiger'],true))apiError(403,'Keine Berechtigung.');
try{
    if($_SERVER['REQUEST_METHOD']==='GET'){
        $document=paPrepareForm(trim((string)($_GET['folder_id']??'')),$user,false,true);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="'.$document['filename'].'"');
        header('Cache-Control: private, no-store');
        echo $document['bytes'];exit;
    }
    if($_SERVER['REQUEST_METHOD']!=='POST')apiError(405,'Nur GET und POST sind zulässig.');
    if(!ionosStorageEnabled())apiError(503,'Die IONOS-Fallablage ist nicht verfügbar.');
    $body=requestBody();$folder=trim((string)($body['folder_id']??''));
    if(($body['action']??'')==='retire_wrong_form')apiJson(['ok'=>true,...paPrepareForm($folder,$user,true)]);
    apiJson(['ok'=>true,'created'=>[paPrepareForm($folder,$user)],'count'=>1]);
}catch(Throwable $error){
    if($_SERVER['REQUEST_METHOD']==='GET'){
        http_response_code(409);header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html lang="de"><meta charset="utf-8"><title>SV-Netzwerk · Abgeltungsvereinbarung</title><body style="font-family:Arial;max-width:720px;margin:60px auto;line-height:1.6"><h1>SV-Netzwerk · Abgeltungsvereinbarung</h1><p>'.htmlspecialchars($error->getMessage(),ENT_QUOTES,'UTF-8').'</p><p><a href="/intern/kalkulation/">Kalkulation öffnen</a></p></body></html>';exit;
    }
    apiError(409,$error->getMessage());
}
