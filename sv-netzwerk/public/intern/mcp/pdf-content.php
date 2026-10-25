<?php
declare(strict_types=1);

/** Keep original PDF bytes out of normal model text responses. */
function mcpPdfContent(string $bytes, bool $includeImages=false): array {
    $blocks=[['type'=>'text','text'=>json_encode(['original_sha256'=>hash('sha256',$bytes),'original_bytes'=>strlen($bytes),'original_preserved'=>true,'visual_review_performed'=>false])]];
    $text=mcpDrivePdfText($bytes);
    if($text!=='') $blocks[]=['type'=>'text','text'=>"PDF-Textebene (keine visuelle Prüfung):\n".$text];
    else $blocks[]=['type'=>'text','text'=>"Lokale OCR der Original-PDF; complete und Seitenangaben prüfen. OCR ist keine visuelle Prüfung:\n".json_encode(mcpPdfOcr($bytes),JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE)];
    if($includeImages) {
        $blocks[]=['type'=>'text','text'=>'Zusätzliche Vorschau: höchstens die ersten sechs Seiten. Keine vollständige Sichtprüfung längerer PDFs behaupten.'];
        foreach(mcpDrivePdfImages($bytes) as $image) $blocks[]=['type'=>'image','data'=>$image['data'],'mimeType'=>$image['mimeType']];
    }
    return $blocks;
}
