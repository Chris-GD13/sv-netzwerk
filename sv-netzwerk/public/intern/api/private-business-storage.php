<?php
declare(strict_types=1);
require_once __DIR__.'/private-order-storage.php';
require_once __DIR__.'/private-order-inquiries.php';
require_once __DIR__.'/private-business-pdf.php';
require_once __DIR__.'/private-business-pdf-cache.php';
require_once __DIR__.'/private-order-index-cache.php';

function pbRoot(string $profile): array {return poItemByPath(match($profile){'christian'=>poConfig('MS_SHAREPOINT_CHRISTIAN_PRIVATE_PATH','VS Schäden/Christian/Privatgutachten_NL Süd'),'marc'=>poConfig('MS_SHAREPOINT_MARC_PRIVATE_PATH','VS Schäden/Marc/Privatgutachten'),default=>throw new RuntimeException('Kein freigegebener Privatgutachten-Bestand.',403)});}
function pbFolderLabel(string $name): string {return trim(preg_replace('/[\x00-\x1F]/u',' ',str_replace(['\\','/',':','*','?','"','<','>','|'],' ',$name))??'');}
function pbFolder(string $parent,string $name): array {
    foreach(poChildren($parent)as$f)if($f['folder']&&$f['name']===$name)return$f;
    try{return poRequestJson('POST','https://graph.microsoft.com/v1.0/drives/'.rawurlencode(poDriveId()).'/items/'.rawurlencode($parent).'/children',['name'=>$name,'folder'=>new stdClass(),'@microsoft.graph.conflictBehavior'=>'fail']);}catch(Throwable$e){foreach(poChildren($parent)as$f)if($f['folder']&&$f['name']===$name)return$f;throw$e;}
}
function pbPut(string $folder,string $name,string $bytes,string $mime): array {
    $base='https://graph.microsoft.com/v1.0/drives/'.rawurlencode(poDriveId()).'/items/';
    foreach(poChildren($folder)as$f)if(!$f['folder']&&$f['name']===$name){$saved=poRequest($base.rawurlencode($f['id']).'/content',true);if(!hash_equals(hash('sha256',$bytes),hash('sha256',$saved['body'])))throw new RuntimeException('Eine gleichnamige Datei enthält einen anderen Stand. Es wurde nichts überschrieben.');return$f;}
    $file=poRequestUpload($base.rawurlencode($folder).':/'.rawurlencode($name).':/content?%40microsoft.graph.conflictBehavior=fail',$name,$bytes,$mime);
    $saved=poRequest($base.rawurlencode((string)$file['id']).'/content',true);if(!hash_equals(hash('sha256',$bytes),hash('sha256',$saved['body'])))throw new RuntimeException('Die gespeicherte Datei konnte nicht vollständig bestätigt werden.');return$file;
}
function pbArchive(PDO $db,array $r): array {
    $documents=pbCachePdfDocuments($db,$r);
    $root=pbRoot($r['profile']);$id=$r['id'];$locked=false;
    if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'){$q=$db->prepare('SELECT GET_LOCK(?,20)');$q->execute(['private-business:'.$id]);if((int)$q->fetchColumn()!==1)throw new RuntimeException('Dieser Stand wird gerade gespeichert. Bitte erneut versuchen.');$locked=true;}
    try{
        $current=pbRecord($db,$id,$r['profile']);$folder=$current['folder_id'];
        if(!$folder){$year=pbFolder((string)$root['id'],(new DateTimeImmutable('now',new DateTimeZone('Europe/Berlin')))->format('Y'));$label=pbFolderLabel(pbCustomer($r['data']))?:'Noch ohne Kundennamen';$created=pbFolder($year['id'],'Anfrage_'.$r['number'].'_'.mb_substr($label,0,65));$folder=$created['id'];$q=$db->prepare('UPDATE private_business_records SET folder_id=? WHERE id=?');$q->execute([$folder,$id]);}
        poValidateTopOrder((string)$folder,(string)$root['id']);
        if($r['kind']==='order'){$source=poValidateScope((string)$folder,(string)$root['id']);if(poIsInquiryName((string)$source['name'])){$label=pbFolderLabel(pbCustomer($r['data']))?:'Auftrag';poRequestJson('PATCH','https://graph.microsoft.com/v1.0/drives/'.rawurlencode(poDriveId()).'/items/'.rawurlencode((string)$folder),['name'=>$r['number'].'_'.mb_substr($label,0,75),'@microsoft.graph.conflictBehavior'=>'fail']);}}
        $destinations=[];foreach(poStandardFolders()as$name)$destinations[$name]=pbFolder((string)$folder,$name)['id'];
        $labels=['main'=>$r['kind']==='order'?'Auftrag':'Angebot','contract'=>'Auftragsformular','withdrawal'=>'Widerrufsbelehrung','packet'=>'Gesamtpaket'];
        foreach($documents as$kind=>$bytes){$q=$db->prepare('SELECT item_id FROM private_business_documents WHERE record_id=? AND revision=? AND kind=?');$q->execute([$id,$r['revision'],$kind]);if($q->fetchColumn())continue;$name=$r['number'].'_'.$labels[$kind].'_R'.sprintf('%03d',(int)$r['revision']).'.pdf';$target=$destinations[$kind==='main'&&$r['kind']==='offer'?'Angebot':'Auftrag'];$file=pbPut($target,$name,$bytes,'application/pdf');$q=$db->prepare('INSERT INTO private_business_documents(record_id,revision,kind,item_id,filename,sha256) VALUES(?,?,?,?,?,?)');$q->execute([$id,$r['revision'],$kind,$file['id'],$name,hash('sha256',$bytes)]);}
        pbPut($destinations['Auftrag'],$r['number'].'_Formulardaten_R'.sprintf('%03d',(int)$r['revision']).'.json',json_encode(['number'=>$r['number'],'kind'=>$r['kind'],'revision'=>$r['revision'],'data'=>$r['data'],'totals'=>pbTotals($r['data']),'legal_version'=>'2026-10-17'],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR),'application/json');
        poInvalidateIndex($db,$r['profile'],match($r['profile']){'christian'=>poConfig('MS_SHAREPOINT_CHRISTIAN_PRIVATE_PATH','VS Schäden/Christian/Privatgutachten_NL Süd'),'marc'=>poConfig('MS_SHAREPOINT_MARC_PRIVATE_PATH','VS Schäden/Marc/Privatgutachten')});
        pbEvent($db,$id,'archived',['revision'=>$r['revision'],'folder_id'=>$folder]);return pbRecord($db,$id,$r['profile']);
    }finally{if($locked){$q=$db->prepare('SELECT RELEASE_LOCK(?)');$q->execute(['private-business:'.$id]);}}
}
function pbPublicRecord(PDO $db,array $r): array {$q=$db->prepare('SELECT revision,kind,filename FROM private_business_documents WHERE record_id=? ORDER BY revision DESC');$q->execute([$r['id']]);unset($r['payload_json']);$r['files']=$q->fetchAll();$r['totals']=pbTotals($r['data']);$r['missing']=pbMissing($r['data']);$r['archive_complete']=count(array_filter($r['files'],fn($x)=>(int)$x['revision']===(int)$r['revision']))===4;pbPdfCacheSchema($db);$q=$db->prepare('SELECT revision,kind,filename,template_version FROM private_business_pdf_cache WHERE record_id=? AND revision=?');$q->execute([$r['id'],$r['revision']]);$cached=$q->fetchAll();$r['pdf_ready']=count($cached)===4;$r['pdf_template_current']=$r['pdf_ready']&&count(array_filter($cached,fn($f)=>$f['template_version']===PB_PDF_TEMPLATE))===4;foreach($cached as$f){$exists=false;foreach($r['files']as$old)if($old['kind']===$f['kind']&&(int)$old['revision']===(int)$f['revision'])$exists=true;if(!$exists)$r['files'][]=$f;}$q=$db->prepare('SELECT * FROM private_business_dispatches WHERE record_id=? ORDER BY created_at DESC LIMIT 1');$q->execute([$r['id']]);if($dispatch=$q->fetch()){$detail=json_decode($dispatch['detail_json'],true);if($dispatch['state']!=='sent'||!empty($detail['archive_pending']))$r['pending_dispatch']=['id'=>$dispatch['id'],'state'=>$dispatch['state'],'detail'=>$detail];}$q=$db->prepare("SELECT id,number FROM private_business_records WHERE parent_id=? AND kind='order'");$q->execute([$r['id']]);$r['child_order']=$q->fetch()?:null;return$r;}
