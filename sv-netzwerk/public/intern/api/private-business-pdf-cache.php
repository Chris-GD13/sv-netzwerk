<?php
declare(strict_types=1);
const PB_PDF_TEMPLATE = '2026-10-17-experts-office-owner-12';

function pbPdfCacheSchema(PDO $db): void {
    $db->exec('CREATE TABLE IF NOT EXISTS private_business_pdf_cache (record_id VARCHAR(36) NOT NULL, revision INTEGER NOT NULL, kind VARCHAR(30) NOT NULL, filename VARCHAR(240) NOT NULL, sha256 VARCHAR(64) NOT NULL, template_version VARCHAR(80) NOT NULL, pdf_bytes MEDIUMBLOB NOT NULL, PRIMARY KEY(record_id,revision,kind))');
}
function pbCachedPdf(PDO $db,string $id,int $revision,string $kind): ?array {
    pbPdfCacheSchema($db);$q=$db->prepare('SELECT * FROM private_business_pdf_cache WHERE record_id=? AND revision=? AND kind=?');$q->execute([$id,$revision,$kind]);$f=$q->fetch();
    if(!$f)return null;
    if(is_resource($f['pdf_bytes']))$f['pdf_bytes']=stream_get_contents($f['pdf_bytes']);
    if(!hash_equals($f['sha256'],hash('sha256',$f['pdf_bytes'])))throw new RuntimeException('Das gespeicherte PDF konnte nicht bestätigt werden.');
    return $f;
}
function pbCachePdfDocuments(PDO $db,array $r): array {
    pbPdfCacheSchema($db);$files=[];
    foreach(['main','contract','withdrawal','packet']as$kind){$f=pbCachedPdf($db,$r['id'],(int)$r['revision'],$kind);if($f)$files[$kind]=$f['pdf_bytes'];}
    if(count($files)===4)return $files;
    $generated=pbDocuments($r);$labels=['main'=>$r['kind']==='order'?'Auftrag':'Angebot','contract'=>'Auftragsformular','withdrawal'=>'Widerrufsbelehrung','packet'=>'Gesamtpaket'];
    foreach($generated as$kind=>$bytes){
        if(isset($files[$kind]))continue;
        $filename=$r['number'].'_'.$labels[$kind].'_R'.sprintf('%03d',(int)$r['revision']).'.pdf';
        try{$q=$db->prepare('INSERT INTO private_business_pdf_cache(record_id,revision,kind,filename,sha256,template_version,pdf_bytes) VALUES(?,?,?,?,?,?,?)');$q->execute([$r['id'],$r['revision'],$kind,$filename,hash('sha256',$bytes),PB_PDF_TEMPLATE,$bytes]);}
        catch(PDOException $e){if(!pbCachedPdf($db,$r['id'],(int)$r['revision'],$kind))throw $e;}
        $files[$kind]=pbCachedPdf($db,$r['id'],(int)$r['revision'],$kind)['pdf_bytes'];
    }
    return $files;
}
