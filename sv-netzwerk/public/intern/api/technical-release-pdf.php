<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
commonHeaders();
$user=requireAuth();
if(!in_array((string)($user['role']??''),['administrator','projektleiter','pruefer','sachverstaendiger'],true))apiError(403,'Keine Berechtigung.');
if($_SERVER['REQUEST_METHOD']!=='POST')apiError(405,'POST erforderlich.');
require_once __DIR__.'/technical-release-pdf-core.php';
try{
    $data=requestBody();
    $pdf=trBuildTechnicalReleasePdf($data);
    $caseNo=trim((string)($data['meta']['case_no']??'Technische-Freigabe'));
    $safe=preg_replace('/[^A-Za-z0-9._-]+/','-',$caseNo)?:'Technische-Freigabe';
    $filename=$safe.'_Technische-Freigabe_'.date('Y-m-d').'.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Content-Length: '.strlen($pdf));
    echo$pdf;
}catch(Throwable$e){apiError(400,$e->getMessage());}
