<?php
declare(strict_types=1);

/** Canonical, existing orange SV / sv-netzwerk.eu artwork. No remote logo lookup. */
function svnetBrandLogo(): array {
    $path=__DIR__.'/../assets/private-business/sv-netzwerk-eu-logo.jpg';
    $bytes=@file_get_contents($path);$size=$bytes===false?false:@getimagesizefromstring($bytes);
    if($bytes===false||!$size||($size['mime']??'')!=='image/jpeg'||($size['channels']??0)!==3)throw new RuntimeException('Das SV-Netzwerk-Logo fehlt oder ist beschädigt. Ausgabe wurde nicht erstellt.');
    return ['bytes'=>$bytes,'width'=>$size[0],'height'=>$size[1],'name'=>'SV-Netzwerk-Logo.jpg','content_id'=>'sv-netzwerk-logo'];
}

/** Preserve recipients and original attachments; add an offline-capable inline logo once. */
function svnetBrandMailMessage(array $message): array {
    $logo=svnetBrandLogo();$body=$message['body']??[];$content=(string)($body['content']??'');
    if(strcasecmp((string)($body['contentType']??'Text'),'HTML')!==0)$content='<p>'.nl2br(htmlspecialchars($content,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')).'</p>';
    if(!str_contains($content,'cid:'.$logo['content_id']))$content='<div style="font-family:Arial,sans-serif;color:#17324a"><p><img src="cid:'.$logo['content_id'].'" width="300" alt="SV-Netzwerk · sv-netzwerk.eu" style="display:block;width:300px;max-width:100%;height:auto"></p>'.$content.'</div>';
    $message['body']=['contentType'=>'HTML','content'=>$content];
    $attachments=$message['attachments']??[];
    $attachments=array_values(array_filter($attachments,fn($a)=>($a['contentId']??'')!==$logo['content_id']));
    $attachments[]=['@odata.type'=>'#microsoft.graph.fileAttachment','name'=>$logo['name'],'contentType'=>'image/jpeg','contentBytes'=>base64_encode($logo['bytes']),'contentId'=>$logo['content_id'],'isInline'=>true];
    $message['attachments']=$attachments;return $message;
}

/** Add one actual image object and expose it to all pages of our own PDF writers. */
function svnetBrandPdfObjects(array &$objects): void {
    $logo=svnetBrandLogo();$id=max(array_keys($objects))+1;$bytes=$logo['bytes'];
    foreach($objects as &$object)if(str_contains($object,'/Type /Page '))$object=str_replace('/Resources << ','/Resources << /XObject << /SVNetLogo '.$id.' 0 R >> ',$object);
    unset($object);
    $objects[$id]='<< /Type /XObject /Subtype /Image /Width '.$logo['width'].' /Height '.$logo['height'].' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($bytes).">>\nstream\n".$bytes."\nendstream";
}

function svnetBrandPdfLogo(): string {
    $logo=svnetBrandLogo();$height=230*$logo['height']/$logo['width'];
    return sprintf('q 230 0 0 %.4F 45 782 cm /SVNetLogo Do Q',$height);
}

function svnetBrandPdfSerialize(array $objects): string {
    svnetBrandPdfObjects($objects);ksort($objects);$pdf="%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";$offsets=[0];
    foreach($objects as $id=>$object){$offsets[$id]=strlen($pdf);$pdf.="$id 0 obj\n$object\nendobj\n";}
    $xref=strlen($pdf);$size=max(array_keys($objects))+1;$pdf.="xref\n0 $size\n0000000000 65535 f \n";
    for($id=1;$id<$size;$id++)$pdf.=sprintf('%010d 00000 n ',$offsets[$id])."\n";
    return $pdf."trailer\n<< /Size $size /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
}
