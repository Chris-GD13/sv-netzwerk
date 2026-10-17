<?php
declare(strict_types=1);
// Local test fixture only. Never deploy this router or synthetic authentication.
if(PHP_SAPI!=='cli-server')exit(1);
require_once __DIR__.'/assistant-fixture.php';
function svAssistantEnvironment():array {
    $cases=assistantTestCases();$release=assistantTestRelease();
    $private=(string)getenv('SVNET_TEST_RULE_RELEASE');
    if($private!==''){
        $bytes=file_get_contents($private);
        if(!hash_equals((string)getenv('SVNET_TEST_RULE_HASH'),hash('sha256',$bytes)))throw new RuntimeException('PRIVATE_RELEASE_CHANGED');
        $release=json_decode($bytes,true,512,JSON_THROW_ON_ERROR);
        $cases['fixture-a']['insurer']='SV';$cases['fixture-a']['line']='GF';
        $cases['fixture-a']['energy_kwh_milli']=250500;$cases['fixture-a']['has_quote']=false;
        $cases['fixture-a']['restoration_items']=[['quantity_milli'=>12500,'unit_price_cents'=>2500,'source'=>'synthetic-measurement-and-price']];
    }
    $user=assistantTestUser();$engine=new CaseWorkAssistant(assistantTestDb((string)getenv('SVNET_TEST_DB')),assistantTestLoader($cases),$release,'local-test-session');
    return ['engine'=>$engine,'user'=>$user];
}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($path==='/mobile'){
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><title>Fall-Work · 375px Mobilprüfung</title><h1>375px Test-Viewport</h1><iframe title="Fall-Work mobil" style="width:375px;height:1000px;border:1px solid #aaa" src="/intern/versicherungsfaelle/"></iframe>';return true;
}
if($path==='/intern/api/case-work.php'){require __DIR__.'/../public/intern/api/case-work.php';return true;}
if(in_array($path,['/intern/case-work.js','/intern/case-work-assistant.js'],true)){header('Content-Type: text/javascript');readfile(__DIR__.'/../public'.$path);return true;}
if($path==='/intern/versicherungsfaelle/'){
    $source=file_get_contents(__DIR__.'/../src/pages/intern/versicherungsfaelle/index.astro');
    $start=strpos($source,'  <section id="vf-analysis"');$end=strpos($source,'  </section>',$start)+strlen('  </section>');
    $section=substr($source,$start,$end-$start);
    $styles='';preg_match('/<style is:global>(.*?)<\/style>/s',$source,$match);$styles=$match[1]??'';
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="de"><meta name="viewport" content="width=device-width,initial-scale=1"><title>SV-Assistent · lokale Fall-Work-Prüfung</title><style>body{font:16px Arial;margin:16px auto;padding:0 12px;max-width:1000px}*{box-sizing:border-box}.vf-card{border:1px solid #bacbd4;border-radius:12px;padding:16px}.vf-step{cursor:pointer;background:#e9f0f3;padding:12px;display:flex;gap:12px}.vf-step strong,.vf-step small{display:block}.is-collapsed #vf-assistant{display:none}textarea{width:100%}button{cursor:pointer}'.$styles.'</style><h1>Fall-Work · lokale Prüfung</h1><p>Ausschließlich synthetische Fälle. Keine Verbindung zu Claimsforce.</p><button id="fixture-a">Testfall A öffnen</button> <button id="fixture-b">Testfall B öffnen</button> <button id="fixture-clear">Fall schließen</button>'.$section;
    echo '<script src="/intern/case-work-assistant.js"></script><script src="/intern/case-work.js"></script><script>document.querySelector(".vf-collapse-trigger").onclick=()=>{const s=document.getElementById("vf-analysis");s.classList.toggle("is-collapsed");s.querySelector("header").setAttribute("aria-expanded",String(!s.classList.contains("is-collapsed")))};document.getElementById("fixture-a").onclick=()=>window.dispatchEvent(new CustomEvent("svnet:case-opened",{detail:{folder_id:"fixture-a",meta:{schaden_nr:"TEST-A-001"}}}));document.getElementById("fixture-b").onclick=()=>window.dispatchEvent(new CustomEvent("svnet:case-opened",{detail:{folder_id:"fixture-b",meta:{schaden_nr:"TEST-B-002"}}}));document.getElementById("fixture-clear").onclick=()=>window.dispatchEvent(new CustomEvent("svnet:case-cleared"));</script></html>';return true;
}
http_response_code(404);echo 'Fixture route unavailable';return true;
