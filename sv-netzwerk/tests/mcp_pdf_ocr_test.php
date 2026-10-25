<?php
require_once __DIR__.'/../public/intern/mcp/pdf-ocr.php';
function check(bool $ok,string $label): void { if (!$ok) throw new RuntimeException($label); echo "PASS $label\n"; }
check(mcpPdfOcr('')['complete']===false,'empty input rejected');
check(mcpPdfOcr('not a PDF')['error']!==null,'invalid input rejected');
if (!empty($argv[1])) {
    $start=microtime(true);
    $bytes=file_get_contents($argv[1]); $r=mcpPdfOcr($bytes);
    check($r['complete'],'all original pages extracted');
    check(count($r['pages'])===$r['page_count'],'page coverage');
    check($r['sha256']===hash('sha256',$bytes),'original fingerprint');
    $text=implode("\n",array_column($r['pages'],'text'));
    check(str_contains($text,'AN2628174'),'reference offer identifier readable');
    check(str_contains($text,'7.367,89'),'reference gross amount readable');
    echo json_encode(['seconds'=>round(microtime(true)-$start,2),'result'=>$r],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT);
}
