<?php
declare(strict_types=1);
require_once __DIR__.'/bki-settlement-core.php';
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
$data=json_decode(file_get_contents('php://input')?:'',true);
if(!is_array($data)){http_response_code(400);echo 'Invalid payload';exit;}
try{
    $result=\SvNetSettlement\build($data);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="'.$result['filename'].'"');
    header('Content-Length: '.strlen($result['bytes']));
    echo $result['bytes'];
}catch(RuntimeException $error){http_response_code(in_array($error->getCode(),[400,422],true)?$error->getCode():400);echo $error->getMessage();}
