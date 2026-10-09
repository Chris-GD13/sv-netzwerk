<?php
declare(strict_types=1);

function drStandalone(string $folder): bool { return preg_match('/^review_[a-f0-9]{32}$/D', $folder) === 1; }
function drWorkspacePath(string $folder): string {
    global $user;
    if (!drStandalone($folder) || (int)($user['id']??0)<=0) apiError(403,'Ungültiger Prüfvorgang.');
    $path=ionosStorageRoot().'/document-reviews/'.(int)$user['id'].'/'.$folder;
    if (!is_dir($path)) apiError(403,'Dieser Prüfvorgang gehört nicht zu Ihrem Profil.');
    return $path;
}
function drRequireContext(string $folder,array $user): void {
    if (drStandalone($folder)) drWorkspacePath($folder); else requireCaseFolderAccess($folder,$user);
}
function drWorkspaceCreate(array $user): string {
    $id='review_'.bin2hex(random_bytes(16));
    $path=ionosStorageRoot().'/document-reviews/'.(int)$user['id'].'/'.$id;
    if ((int)$user['id']<=0 || !mkdir($path,0700,true)) throw new RuntimeException('Freie Prüfung konnte nicht angelegt werden.');
    return $id;
}
function drWorkspaceWrite(string $folder,string $name,string $mime,string $bytes,?string $id=null): array {
    $path=drWorkspacePath($folder); $id??='doc_'.bin2hex(random_bytes(16));
    if (!preg_match('/^doc_[a-f0-9]{32}$/D',$id)) throw new RuntimeException('Ungültiger Beleg.');
    $meta=['id'=>$id,'name'=>$name,'mimeType'=>$mime,'size'=>strlen($bytes),'modifiedTime'=>gmdate('c')];
    if (file_put_contents($path.'/'.$id.'.bin',$bytes,LOCK_EX)===false || file_put_contents($path.'/'.$id.'.json',json_encode($meta,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),LOCK_EX)===false) throw new RuntimeException('Beleg konnte nicht gespeichert werden.');
    return $meta;
}
function drFiles(string $folder): array {
    if (!drStandalone($folder)) return krList($folder);
    $files=[];
    foreach (glob(drWorkspacePath($folder).'/doc_*.json')?:[] as $file) $files[]=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
    return $files;
}
function drSelected(string $folder,string $id): array {
    if (!drStandalone($folder)) return krSelected($folder,$id);
    $path=drWorkspacePath($folder);
    if (!preg_match('/^doc_[a-f0-9]{32}$/D',$id) || !is_file($path.'/'.$id.'.json')) throw new RuntimeException('Beleg gehört nicht zu dieser Prüfung.');
    $meta=json_decode(file_get_contents($path.'/'.$id.'.json'),true,512,JSON_THROW_ON_ERROR);
    return ['name'=>$meta['name'],'mime'=>$meta['mimeType'],'bytes'=>file_get_contents($path.'/'.$id.'.bin')];
}
function drSourceMeta(string $id,array $source): array {
    return ['id'=>$id,'name'=>$source['name'],'mime'=>$source['mime'],'sha256'=>hash('sha256',$source['bytes'])];
}
function drRecordSources(string $folder,array $record): array {
    $sources=[];
    foreach (($record['sources']??[['id'=>$record['file_id'],'sha256'=>$record['sha256']]]) as $meta) {
        $source=drSelected($folder,$meta['id']);
        if (!hash_equals($meta['sha256'],hash('sha256',$source['bytes']))) throw new RuntimeException('Ein Originalbeleg wurde verändert. Bitte neu vorbereiten.');
        $sources[]=$source;
    }
    return $sources;
}

function drUpload(string $folder): void {
    $uploads=$_FILES['files']??null;
    if (!$uploads || !is_array($uploads['name']) || count($uploads['name'])>12) throw new RuntimeException('Bitte 1 bis 12 Belege auswählen (insgesamt höchstens 50 MB).');
    $ready=[];$total=0;
    foreach ($uploads['name'] as $i=>$name) {
        if ($uploads['error'][$i]!==UPLOAD_ERR_OK || !is_uploaded_file($uploads['tmp_name'][$i])) throw new RuntimeException('Beleg nicht vollständig hochgeladen: '.$name);
        $bytes=file_get_contents($uploads['tmp_name'][$i]);$total+=strlen($bytes);
        if (strlen($bytes)>30*1024*1024 || $total>50*1024*1024) throw new RuntimeException('Maximal 30 MB je Beleg und insgesamt 50 MB.');
        $name=basename(str_replace('\\','/',$name));$mime=mime_content_type($uploads['tmp_name'][$i]);
        if (!preg_match('/\.(pdf|docx|png|jpe?g|webp)$/i',$name) || !in_array($mime,['application/pdf','application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/zip','image/png','image/jpeg','image/webp'],true)) throw new RuntimeException('Nicht unterstützter Beleg: '.$name);
        if ($mime==='application/zip' && !str_ends_with(strtolower($name),'.docx')) throw new RuntimeException('Nur DOCX-Dokumente sind als ZIP-Struktur zulässig.');
        if ($mime==='application/zip') $mime='application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        $ready[]=['name'=>$name,'mime'=>$mime,'bytes'=>$bytes];
    }
    $files=[];$parent=$folder;
    if (!drStandalone($folder)) {
        foreach (drChildren($folder) as $child) if ($child['mimeType']==='application/vnd.google-apps.folder' && str_starts_with($child['name'],'03_')) {$parent=$child['id'];break;}
        if ($parent===$folder) $parent=ionosWrite(['name'=>'03_Rechnungen_KVA','mimeType'=>'application/vnd.google-apps.folder','parents'=>[$folder]])['id'];
    }
    foreach ($ready as $file) $files[]=drStandalone($folder)?drWorkspaceWrite($folder,$file['name'],$file['mime'],$file['bytes']):ionosWrite(['name'=>$file['name'],'mimeType'=>$file['mime'],'parents'=>[$parent]],$file['bytes']);
    apiJson(['ok'=>true,'files'=>$files]);
}
