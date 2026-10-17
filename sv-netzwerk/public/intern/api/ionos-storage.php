<?php
declare(strict_types=1);
require_once __DIR__.'/ionos-storage-recovery.php';

/** ID-preserving local document store. Remote services remain disabled until verified cutover. */
function ionosStorageEnabled(): bool {
    $backend = strtolower(trim((string)getenv('PORTAL_STORAGE_BACKEND')));
    if ($backend === 'google') return false;
    if ($backend === 'ionos') return true;
    // The web process may not inherit the deployment environment. Once the
    // verified local index exists, use it automatically so IONOS file IDs are
    // never sent to the Google Drive API as a fallback.
    return is_file(rtrim(getenv('IONOS_STORAGE_ROOT') ?: '/srv/svnet-storage', '/') . '/index.sqlite');
}
function ionosStorageRoot(): string { return rtrim(getenv('IONOS_STORAGE_ROOT') ?: '/srv/svnet-storage', '/'); }
function ionosStorageDb(): PDO {
    static $db=null;
    if ($db) return $db;
    $path=ionosStorageRoot().'/index.sqlite';
    if (!is_file($path)) throw new RuntimeException('Der geprüfte IONOS-Dateiindex fehlt.');
    $db=new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA busy_timeout=30000');
    return $db;
}
function ionosItem(string $id): array {
    $s=ionosStorageDb()->prepare('SELECT * FROM items WHERE id=? AND verified=1');$s->execute([$id]);$r=$s->fetch();
    if (!$r) throw new RuntimeException('Datei nicht im geprüften IONOS-Bestand: '.$id);
    $m=json_decode($r['meta'],true,512,JSON_THROW_ON_ERROR);
    $m['id']=$r['id'];$m['name']=$r['name'];$m['mimeType']=$r['mime'];$m['parents']=$r['parent']!==''?[$r['parent']]:[];
    unset($m['webContentLink'],$m['webViewLink']);
    return $m;
}
function ionosBytes(string $id): string { return ionosReadBytesConsistent($id); }
function ionosWrite(array $meta, ?string $bytes=null, ?string $id=null, ?array $precondition=null): array {
    $db=ionosStorageDb();$db->exec('BEGIN IMMEDIATE');$intentId=null;
    try {
        foreach($precondition['contextRecords']??[] as $contextRecord){
            $contextId=$contextRecord['id']??'';ionosIntentPath($contextId);
            if(ionosRowFingerprint(ionosRow($contextId))!==($contextRecord['rowFingerprint']??null))throw new RuntimeException('SOURCE_CONTEXT_VERSION_CONFLICT');
            $contextKind=$contextRecord['kind']??'content';
            if(!in_array($contextKind,['content','metadata'],true)||($contextKind==='content'&&(!is_file(ionosStorageRoot().'/objects/'.$contextId)||hash_file('sha256',ionosStorageRoot().'/objects/'.$contextId)!==($contextRecord['sha256']??null))))throw new RuntimeException('SOURCE_CONTEXT_VERSION_CONFLICT');
        }
        if($id!==null)ionosRecoverIntentLocked($id);
        $requestedId=$id;$id??='ionos_'.bin2hex(random_bytes(16));
        $oldRow=ionosRow($id);$path=ionosStorageRoot().'/objects/'.$id;
        if($requestedId!==null&&$oldRow===null&&empty($precondition['allowCreate']))throw new RuntimeException('Datei nicht im geprüften IONOS-Bestand: '.$id);
        $old=$oldRow!==null?ionosItem($id):[];
        $oldSha=is_file($path)?hash_file('sha256',$path):null;
        if($precondition!==null){
            if(($precondition['id']??null)!==$id||($precondition['rowFingerprint']??null)!==ionosRowFingerprint($oldRow)||($precondition['sha256']??null)!==$oldSha)throw new RuntimeException('SOURCE_VERSION_CONFLICT');
        }
        if($oldSha!==null&&((isset($old['sha256Checksum'])&&!hash_equals($old['sha256Checksum'],$oldSha))||(isset($old['md5Checksum'])&&!hash_equals($old['md5Checksum'],md5_file($path)))))throw new RuntimeException('Datei und Index passen nicht zusammen.');
        $m=array_replace($old,$meta,['id'=>$id]);$m['name']=$m['name']??'Datei';$m['mimeType']=$m['mimeType']??'application/octet-stream';
        $parent=(string)($m['parents'][0]??'root');
        if($parent===$id)throw new RuntimeException('Ordner kann nicht sich selbst enthalten.');
        $p=ionosItem($parent);if($p['mimeType']!=='application/vnd.google-apps.folder')throw new RuntimeException('Ungültiger Zielordner.');
        for($ancestor=$parent,$j=0;$ancestor!==''&&$j<100;$j++){$a=ionosItem($ancestor);if($ancestor===$id)throw new RuntimeException('Zyklische Ordnerzuordnung.');$ancestor=(string)($a['parents'][0]??'');}
        $m['modifiedTime']=gmdate('Y-m-d\TH:i:s\Z');$m['createdTime']??=$m['modifiedTime'];$m['trashed']??=false;
        if($bytes!==null){
            $backup=null;
            if(is_file($path)){
                $versions=ionosStorageRoot().'/versions';if(!is_dir($versions)&&!mkdir($versions,0770,true))throw new RuntimeException('Versionsordner fehlt.');
                $backup=$id.'-'.bin2hex(random_bytes(8));if(!copy($path,$versions.'/'.$backup)||hash_file('sha256',$versions.'/'.$backup)!==$oldSha)throw new RuntimeException('Vorversion konnte nicht gesichert werden.');
            }
            $tmp=tempnam(ionosStorageRoot().'/objects','write-');if($tmp===false)throw new RuntimeException('Datei konnte nicht geschrieben werden.');
            $f=fopen($tmp,'wb');if(!$f)throw new RuntimeException('Datei konnte nicht geöffnet werden.');
            try{if(fwrite($f,$bytes)!==strlen($bytes)||!fflush($f)||(function_exists('fsync')&&!fsync($f)))throw new RuntimeException('Datei konnte nicht vollständig gesichert werden.');}finally{fclose($f);}
            chmod($tmp,0660);
            if(function_exists('posix_geteuid')&&posix_geteuid()===0){$owner=stat(ionosStorageRoot().'/objects');if(!$owner||!chown($tmp,$owner['uid'])||!chgrp($tmp,$owner['gid']))throw new RuntimeException('Dienstbesitzer konnte nicht erhalten werden.');}
            $m['size']=(string)strlen($bytes);$m['md5Checksum']=md5($bytes);$m['sha256Checksum']=hash('sha256',$bytes);$operation=bin2hex(random_bytes(16));$m['_svnet_write_operation']=$operation;
            $intent=['id'=>$id,'operation'=>$operation,'oldRowFingerprint'=>ionosRowFingerprint($oldRow),'oldSha256'=>$oldSha,'newSha256'=>$m['sha256Checksum'],'backup'=>$backup];
            ionosDurableJson(ionosIntentPath($id),$intent);$intentId=$id;
            if(!rename($tmp,$path))throw new RuntimeException('Datei konnte nicht übernommen werden.');
        }elseif($m['mimeType']!=='application/vnd.google-apps.folder'&&!is_file($path))throw new RuntimeException('Dateiinhalt fehlt.');
        $s=$db->prepare('INSERT OR REPLACE INTO items VALUES(?,?,?,?,?,?,1)');$s->execute([$id,$parent,$m['name'],$m['mimeType'],json_encode($m,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$m['mimeType']==='application/vnd.google-apps.folder'?'':$path]);
        $db->exec('COMMIT');
        if($intentId!==null)ionosRecoverPending($intentId);
        return $m;
    }catch(Throwable $e){
        try{$db->exec('ROLLBACK');}catch(Throwable $alreadyCommitted){}
        if($intentId!==null)ionosRecoverPending($intentId);
        throw $e;
    }
}

function ionosList(array $query): array {
    $q=(string)($query['q']??'');$clauses=preg_split('/\s+and\s+(?=(?:[^\x27]*\x27[^\x27]*\x27)*[^\x27]*$)/',$q)?:[];
    $s=ionosStorageDb()->query('SELECT id FROM items WHERE verified=1 ORDER BY name,id');$out=[];
    foreach($s as$r){$m=ionosItem($r['id']);$match=true;
        foreach($clauses as$c){$c=trim($c);if($c==='')continue;
            if(preg_match("/^'((?:\\\\.|[^'])*)' in parents$/",$c,$a)){$match=in_array(stripslashes($a[1]),$m['parents'],true);}
            elseif(preg_match('/^trashed\s*=\s*(true|false)$/',$c,$a)){$match=(bool)($m['trashed']??false)===($a[1]==='true');}
            elseif(preg_match("/^(name|mimeType|fullText)\s*(=|!=|contains)\s*'((?:\\\\.|[^'])*)'$/",$c,$a)){
                $v=$a[1]==='fullText'?($m['name'].' '.(($m['mimeType']??'')==='application/json'?ionosBytes($m['id']):'')):(string)($m[$a[1]]??'');$wanted=stripslashes($a[3]);
                $match=match($a[2]){'='=>$v===$wanted,'!='=>$v!==$wanted,default=>mb_stripos($v,$wanted)!==false};
            }else throw new RuntimeException('Nicht unterstützte lokale Dateiabfrage: '.$c);
            if(!$match)break;
        }
        if($match)$out[]=$m;
    }
    $offset=max(0,(int)($query['pageToken']??0));$limit=max(1,min(1000,(int)($query['pageSize']??1000)));$result=['files'=>array_slice($out,$offset,$limit)];if(count($out)>$offset+$limit)$result['nextPageToken']=(string)($offset+$limit);return$result;
}
/** Dispatch existing Drive transport contracts locally, never fall back to Google in IONOS mode. */
function ionosDriveRequest(string $method,string $url,array $headers=[],mixed $body=null): ?array {
    if(!ionosStorageEnabled())return null;
    $u=parse_url($url);if(($u['host']??'')!=='www.googleapis.com'||!preg_match('~^/(upload/)?drive/v3/files(?:/([^/]+))?(?:/(copy|export))?$~',$u['path']??'',$parts))return null;
    parse_str($u['query']??'',$q);$id=isset($parts[2])?rawurldecode($parts[2]):null;$suffix=$parts[3]??'';
    try {
        if($method==='GET'){
            if(!$id)$data=ionosList($q);
            elseif(($q['alt']??'')==='media'||$suffix==='export')return['status'=>200,'body'=>ionosBytes($id),'content_type'=>ionosItem($id)['mimeType']];
            else$data=ionosItem($id);
        }else{
            $meta=[];$bytes=null;
            if(($q['uploadType']??'')==='multipart'){
                $type=implode("\n",$headers);if(!preg_match('/boundary=([^;\s]+)/',$type,$b))throw new RuntimeException('Multipart-Grenze fehlt.');
                $segments=explode('--'.$b[1],(string)$body);if(count($segments)<4)throw new RuntimeException('Ungültiger Datei-Upload.');
                $meta=json_decode(explode("\r\n\r\n",$segments[1],2)[1]??'',true,512,JSON_THROW_ON_ERROR);
                $bytes=explode("\r\n\r\n",$segments[2],2)[1]??'';if(str_ends_with($bytes,"\r\n"))$bytes=substr($bytes,0,-2);
            }elseif(($q['uploadType']??'')==='media')$bytes=(string)$body;
            elseif($body!==null&&$body!=='')$meta=json_decode((string)$body,true,512,JSON_THROW_ON_ERROR);
            if($suffix==='copy'){$source=ionosItem((string)$id);$bytes=ionosBytes((string)$id);$meta=array_replace($source,$meta);unset($meta['id']);$id=null;}
            if(isset($q['addParents']))$meta['parents']=[(string)$q['addParents']];
            if($method==='DELETE'){$meta=['trashed'=>true];}
            if(!in_array($method,['POST','PATCH','DELETE'],true))throw new RuntimeException('Nicht unterstützte lokale Dateimethode.');
            $data=ionosWrite($meta,$bytes,$id);
        }
        return['status'=>200,'body'=>json_encode($data,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),'content_type'=>'application/json'];
    }catch(Throwable$e){error_log('[ionos-storage] '.$e->getMessage());return['status'=>503,'body'=>json_encode(['error'=>['message'=>$e->getMessage()]]),'content_type'=>'application/json'];}
}
