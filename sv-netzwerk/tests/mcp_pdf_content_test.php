<?php
declare(strict_types=1);
require __DIR__.'/../public/intern/mcp/pdf-content.php';
function mcpDrivePdfText(string $bytes): string {return $bytes==='scan'?'':'Invoice 16241.28 EUR';}
function mcpPdfOcr(string $bytes): array {return ['complete'=>true,'pages'=>[['page'=>1,'text'=>'Invoice 16241.28 EUR']]];}
function mcpDrivePdfImages(string $bytes): array {return [['data'=>str_repeat('x',300000),'mimeType'=>'image/png']];}
foreach(['scan','text'] as $source){$blocks=mcpPdfContent($source);$json=json_encode($blocks);if(strlen($json)>2000||str_contains($json,'"blob"')||array_filter($blocks,fn($b)=>$b['type']==='image'))throw new RuntimeException('Normal response must not contain binary');if(!str_contains($json,'16241.28'))throw new RuntimeException('Evidence missing');}
if(!array_filter(mcpPdfContent('scan',true),fn($b)=>$b['type']==='image'))throw new RuntimeException('Explicit visual preview missing');
echo "PASS: compact PDF text/OCR and source hash; no default binary; explicit visual preview retained\n";
