<?php
declare(strict_types=1);
// Synthetic integration fixture only. Never deploy.
if(PHP_SAPI!=='cli-server')exit(1);
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(in_array($path,['/intern/claims-notes-state.mjs','/intern/claims-notes-ui.mjs'],true)){
 header('Content-Type: text/javascript');readfile(__DIR__.'/../public'.$path);return true;
}
if($path==='/integration-fixture.js'){
 header('Content-Type: text/javascript');
 $s=file_get_contents(__DIR__.'/../src/pages/intern/versicherungsfaelle/index.astro');
 $a=strpos($s,'function invalidateOpenedCase()');$b=strpos($s,"window.addEventListener('svnet:notes-saved'",$a);$keep=substr($s,$a,$b-$a);
 $a=strpos($s,'function show(){');$b=strpos($s,'function fill(',$a);$show=substr($s,$a,$b-$a);
 echo "import '/intern/claims-notes-ui.mjs';\nimport {mergeNotes} from '/intern/claims-notes-state.mjs';\n";
 echo "const realFetch=window.fetch.bind(window);let active=null,caseGeneration=0,files=[];const $=id=>document.getElementById(id),e=x=>String(x??'');function fill(){}function updateMailFields(){};".$keep.$show;
 readfile(__DIR__.'/fixtures/assistant-integration.client.js');return true;
}
if($path==='/intern/versicherungsfaelle/'){
 ob_start();require __DIR__.'/assistant-local-router.php';$html=ob_get_clean();
 $source=file_get_contents(__DIR__.'/../src/pages/intern/versicherungsfaelle/index.astro');$a=strpos($source,'  <section id="vf-claims-notes"');$b=strpos($source,'  </section>',$a)+strlen('  </section>');$notes=substr($source,$a,$b-$a);
 $ids=['vf-form','vf-file-list','vf-excel-links','vf-start','vf-excel-originals','vf-cal-check','vf-cal-create','vf-mail-send','vf-cal-address','vf-cal-state'];
 $dummy='';foreach($ids as $id)$dummy.='<div hidden id="'.$id.'"></div>';
 $fixture='<p id="vf-active"></p><button id="fixture-delay">Notizantwort verzögern</button><button id="fixture-release">Antwort freigeben</button><p id="fixture-counts" role="status">Notizabrufe 0 · Speicherungen 0</p>'.$notes.$dummy.'<script type="module" src="/integration-fixture.js"></script>';
 echo str_replace('</html>',$fixture.'</html>',$html);return true;
}
require __DIR__.'/assistant-local-router.php';
