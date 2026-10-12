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
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="Pauschale-Abgeltung_Originalformular.pdf"');
        echo paTemplateBytes();exit;
    }
    if($_SERVER['REQUEST_METHOD']!=='POST')apiError(405,'Nur GET und POST sind zulässig.');
    if(!ionosStorageEnabled())apiError(503,'Die IONOS-Fallablage ist nicht verfügbar.');
    $body=requestBody();$folder=trim((string)($body['folder_id']??''));
    apiJson(['ok'=>true,'created'=>[paPrepareForm($folder,$user)],'count'=>1]);
}catch(Throwable $error){apiError(409,$error->getMessage());}
