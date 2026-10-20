<?php
declare(strict_types=1);
// Development-only, synthetic authentication. Never include in a deployment.
if(PHP_SAPI!=='cli-server')exit(1);
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($path==='/mobile'){
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><title>375px Mobilprüfung</title><button onclick="document.querySelector(\'iframe\').contentDocument.getElementById(\'fixture-check\').click()">Mobile Prüfung ausführen</button><iframe title="Mobile Fallakte" style="display:block;width:375px;height:1100px;border:0" src="/intern/versicherungsfaelle/"></iframe>';return true;
}
if($path==='/intern/versicherungsfaelle/'){
    $source=file_get_contents(__DIR__.'/../src/pages/intern/versicherungsfaelle/index.astro');
    $a=strpos($source,'<main class="vf-app">');$b=strpos($source,'</main>',$a)+7;
    $markup=preg_replace('~<script\b[^>]*>.*?</script>~s','',substr($source,$a,$b-$a));
    preg_match_all('~<style(?:\s[^>]*)?>(.*?)</style>~s',$source,$styles);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Synthetische Schadenakte – vereinfachte Oberfläche</title><style>body{font:16px Arial;margin:0;color:#17324a}#fixture-checks{white-space:pre-wrap;overflow-wrap:anywhere}#fixture-requests{overflow-wrap:anywhere}*{box-sizing:border-box}:root{--vfi:#17324a;--vfm:#536a7d;--vfl:#d6dce1;--vfo:#e69732}.is-collapsed>:not(:first-child){display:none}'.implode("\n",$styles[1]).'</style><link rel="stylesheet" href="/intern/document-review.css"><link rel="stylesheet" href="/intern/case-action-groups.css"><link rel="stylesheet" href="/intern/case-workspace.css"></head><body>';
    echo '<div style="padding:12px;background:#fff3d9"><strong>Nur synthetische Testdaten · keine Verbindung zu Claimsforce</strong><p><button id="fixture-a">Testfall A öffnen</button> <button id="fixture-b">Testfall B öffnen</button> <button id="fixture-clear">Fall schließen</button></p><output id="fixture-requests">Keine Aufrufe.</output></div>'.$markup;
    preg_match_all('~<script\b[^>]*>(.*?)</script>~s',$source,$scripts);
    foreach($scripts[1] as $script)if(str_contains($script,"document.querySelectorAll('.vf-app section.vf-card:not(.vf-processing)')"))echo '<script>'.$script.'</script>';
    echo '<script src="/workspace-fixture.js"></script><script src="/intern/case-document-browser.js"></script><script src="/intern/case-work-assistant.js"></script><script src="/intern/case-work.js"></script><script src="/intern/document-review.js"></script><script src="/intern/case-action-groups.js"></script><script src="/intern/case-workspace.js"></script><script src="/intern/case-output-actions.js"></script><script src="/workspace-checks.js"></script></body></html>';return true;
}
if($path==='/intern/assets/private-business/sv-netzwerk-eu-logo.jpg'){header('Content-Type: image/jpeg');readfile(__DIR__.'/../public'.$path);return true;}
if($path==='/workspace-fixture.js'){
    header('Content-Type: text/javascript');readfile(__DIR__.'/fixtures/workspace.client.js');return true;
}
if($path==='/workspace-checks.js'){
    header('Content-Type: text/javascript');readfile(__DIR__.'/fixtures/workspace-checks.client.js');return true;
}
if($path==='/intern/api/case-file-browser.php'){header('Content-Type: application/json');echo json_encode(['ok'=>true,'folder'=>['name'=>'Synthetische Testakte'],'items'=>[['id'=>'synthetic-file','name'=>(($_GET['folder_id']??'')==='fixture-a'?'Testbeleg-A.pdf':'Testbeleg-B.pdf'),'size'=>100,'mimeType'=>'application/pdf']]]);return true;}
if($path==='/intern/api/document-review.php'){
    header('Content-Type: application/json');
    if(($_GET['action']??'')==='workspace'){echo json_encode(['ok'=>true,'folder_id'=>'review_'.str_repeat('a',32)]);return true;}
    if(($_GET['action']??'')==='files')echo json_encode(['ok'=>true,'files'=>[],'sender'=>'test@example.invalid','signature'=>'Testprofil']);
    else {http_response_code(409);echo json_encode(['ok'=>false,'error'=>'Nur synthetische Ansicht; kein Versand oder produktiver Belegzugriff.']);}
    return true;
}
if(str_starts_with($path,'/intern/')&&!str_contains($path,'..')&&preg_match('~^/intern/[a-z0-9-]+\.(js|css|mjs)$~',$path)){
    $file=__DIR__.'/../public'.$path;
    if(is_file($file)){header('Content-Type: '.(str_ends_with($path,'.css')?'text/css':'text/javascript'));readfile($file);return true;}
}
require __DIR__.'/assistant-local-router.php';
