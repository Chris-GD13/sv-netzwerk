<?php
declare(strict_types=1);

/** Visual separators never change identity; suffixes and leading zeros do. */
function caseNumberKey(string $number): string {
    return mb_strtoupper(preg_replace('/[\s\p{Z}\x{00AD}\x{2010}-\x{2015}\x{2212}-]+/u', '', trim($number)) ?? '', 'UTF-8');
}
function caseIdentityResolve(string $id): string {
    if ($id === '' || !ionosStorageEnabled()) return $id;
    $seen=[];
    for ($i=0;$i<12;$i++) {
        if (isset($seen[$id])) throw new RuntimeException('Zyklische Fallweiterleitung.');
        $seen[$id]=true;
        $item=ionosItem($id);
        $next=(string)($item['appProperties']['svCaseMergedInto']??'');
        if ($next==='') return $id;
        $id=$next;
    }
    throw new RuntimeException('Fallweiterleitung ist zu lang.');
}
function caseIdentityLock(array $user, string $key): string {
    $name='case:'.substr(hash('sha256',gdUserKey($user).'|'.$key),0,55);
    $s=db()->prepare('SELECT GET_LOCK(:name,20)');$s->execute([':name'=>$name]);
    if ((int)$s->fetchColumn()!==1) throw new RuntimeException('Dieser Fall wird gerade bearbeitet. Bitte erneut versuchen.');
    return $name;
}
function caseIdentityUnlock(string $name): void {
    $s=db()->prepare('SELECT RELEASE_LOCK(:name)');$s->execute([':name'=>$name]);
}
function caseIdentityFind(array $user, string $number): array {
    $key=caseNumberKey($number);if ($key==='') return [];
    $root=gdUserCasesRoot($user);$matches=[];
    if(ionosStorageEnabled()) {
        $stmt=ionosStorageDb()->prepare('SELECT c.id,c.name,f.id AS meta_id FROM items c LEFT JOIN items f ON f.parent=c.id AND f.name=? AND f.verified=1 WHERE c.parent=? AND c.mime=? AND c.verified=1');
        $stmt->execute([CASE_META_NAME,(string)$root['id'],'application/vnd.google-apps.folder']);
        foreach($stmt->fetchAll() as $row){$meta=!empty($row['meta_id'])?json_decode(ionosBytes((string)$row['meta_id']),true,512,JSON_THROW_ON_ERROR):[];
            if(caseNumberKey((string)($meta['schaden_nr']??$row['name']))===$key)$matches[(string)$row['id']]=$meta;}
        return $matches;
    }
    foreach(gdListChildren((string)$root['id'],'application/vnd.google-apps.folder',1000) as $folder) {
        $meta=gdCaseMeta((string)$folder['id']);
        if(caseNumberKey((string)($meta['schaden_nr']??$folder['name']))===$key)$matches[(string)$folder['id']]=$meta;
    }
    foreach(searchCaseFolderIndex($user,$number,1000) as $row) {
        if(caseNumberKey((string)($row['meta']['schaden_nr']??''))!==$key)continue;
        $id=caseIdentityResolve((string)$row['id']);$folder=gdFileMeta($id);
        if(in_array((string)$root['id'],$folder['parents']??[],true))$matches[$id]=gdCaseMeta($id);
    }
    return $matches;
}
function caseIdentityCombine(array $target, array $source): array {
    $out=$target;
    foreach($source as $key=>$value) {
        if(!array_key_exists($key,$out)||$out[$key]===''||$out[$key]===null||$out[$key]===[])$out[$key]=$value;
        elseif($key==='telefonkontakte'&&is_array($value))$out[$key]=array_values(array_unique(array_merge((array)$out[$key],$value),SORT_REGULAR));
    }
    return $out;
}
function caseIdentitySave(array $user, array $body): array {
    $data=is_array($body['case']??null)?$body['case']:[];
    $folderId=caseIdentityResolve(trim((string)($body['folder_id']??'')));
    $lock=caseIdentityLock($user,caseNumberKey((string)($data['schaden_nr']??'')));
    try {
        if($folderId!=='')gdAssertCaseAccess($folderId,$user);
        $matches=caseIdentityFind($user,(string)($data['schaden_nr']??''));
        if(count($matches)>1)throw new RuntimeException('Mehrere Schreibweisen dieses Falls vorhanden. Bitte zuerst zusammenführen.');
        $reused=false;
        if($matches) {
            $existingId=(string)array_key_first($matches);
            if($folderId!==''&&$folderId!==$existingId)throw new RuntimeException('Diese Schadenummer gehört bereits zu einem anderen Fall.');
            if($folderId===''){
                foreach(['vn_objekt','versicherungsschein_nr']as$field)if(!empty($matches[$existingId][$field])&&!empty($data[$field])&&caseSearchNormalize((string)$matches[$existingId][$field])!==caseSearchNormalize((string)$data[$field]))throw new RuntimeException('Diese Schadenummer ist bereits einem abweichenden VN oder Vertrag zugeordnet.');
                $folderId=$existingId;$data=caseIdentityCombine($matches[$existingId],$data);$reused=true;
            }
        }
        if($folderId==='') {
            $root=gdUserCasesRoot($user);$folder=gdCreateFolder((string)$root['id'],gdCaseName($data));$folderId=(string)$folder['id'];
            $data['created_at']??=gmdate('c');
        } else {
            $old=gdCaseMeta($folderId);
            if(caseNumberKey((string)($old['schaden_nr']??''))===caseNumberKey((string)($data['schaden_nr']??'')))$data['schaden_nr']=$old['schaden_nr'];
            $data=array_replace($old,$data);
        }
        $data['updated_at']=gmdate('c');$data['updated_by']=$user['full_name']??$user['email'];
        gdUploadJson($folderId,CASE_META_NAME,$data);registerCaseFolderOwner($folderId,$user,$data);
        return ['ok'=>true,'folder_id'=>$folderId,'name'=>gdCaseName($data),'meta'=>$data,'reused'=>$reused];
    } finally {caseIdentityUnlock($lock);}
}
function caseIdentityInventory(string $id): array {
    $out=[];$pending=[$id];$seen=[];
    while($pending){$parent=array_pop($pending);if(isset($seen[$parent]))throw new RuntimeException('Zyklischer Aktenbestand.');$seen[$parent]=true;
        foreach(gdListChildren($parent,null,1000)as$item){$out[]=$item;if(($item['mimeType']??'')==='application/vnd.google-apps.folder')$pending[]=(string)$item['id'];}}
    return $out;
}
function caseIdentityMerge(array $user, array $body): array {
    if(!ionosStorageEnabled())throw new RuntimeException('Zusammenführen ist nur im aktiven IONOS-Bestand möglich.');
    $source=trim((string)($body['source_id']??''));$target=caseIdentityResolve(trim((string)($body['target_id']??'')));
    gdAssertCaseAccess($target,$user);
    if(caseIdentityResolve($source)===$target)return ['ok'=>true,'folder_id'=>$target,'already_merged'=>true];
    gdAssertCaseAccess($source,$user);
    $a=gdCaseMeta($target);$b=gdCaseMeta($source);$key=caseNumberKey((string)($a['schaden_nr']??''));
    if($key===''||$key!==caseNumberKey((string)($b['schaden_nr']??'')))throw new RuntimeException('Die Schadenummern gehören nicht zum selben Fall.');
    foreach(['vn_objekt','versicherungsschein_nr']as$field)if(!empty($a[$field])&&!empty($b[$field])&&caseSearchNormalize((string)$a[$field])!==caseSearchNormalize((string)$b[$field]))throw new RuntimeException('Abweichende Identität: '.$field.'.');
    $lock=caseIdentityLock($user,$key);
    try {
        if(caseIdentityResolve($source)===$target)return ['ok'=>true,'folder_id'=>$target,'meta'=>gdCaseMeta($target),'already_merged'=>true];
        gdAssertCaseAccess($source,$user);gdAssertCaseAccess($target,$user);
        $a=gdCaseMeta($target);$b=gdCaseMeta($source);$sourceFolder=ionosItem($source);
        $sourceItems=caseIdentityInventory($source);$targetItems=caseIdentityInventory($target);$merged=caseIdentityCombine($a,$b);$conflicts=[];
        foreach($b as$field=>$value)if(isset($a[$field])&&$a[$field]!==''&&$value!==''&&$a[$field]!==$value)$conflicts[$field]=['target'=>$a[$field],'source'=>$value];
        $audit=['at'=>gmdate('c'),'by'=>$user['email'],'source_folder'=>$sourceFolder,'target_id'=>$target,'target_before'=>$a,'source_before'=>$b,'source_items'=>$sourceItems,'target_items'=>$targetItems,'conflicts'=>$conflicts];
        $auditFile=gdUploadJson($target,'00_Zusammenfuehrung_'.$source.'.json',$audit);
        $merged['merged_case_ids']=array_values(array_unique(array_merge((array)($a['merged_case_ids']??[]),[$source])));
        gdUploadJson($target,CASE_META_NAME,$merged);registerCaseFolderOwner($target,$user,$merged);
        try {
            ionosWrite(['parents'=>[$target],'name'=>'Zusammengeführt · '.($b['schaden_nr']??$source),'appProperties'=>array_replace((array)($sourceFolder['appProperties']??[]),['svCaseMergedInto'=>$target])],null,$source);
        }catch(Throwable$e){gdUploadJson($target,CASE_META_NAME,$a);registerCaseFolderOwner($target,$user,$a);throw $e;}
        foreach($sourceItems as$item){$after=ionosItem((string)$item['id']);foreach(['parents','md5Checksum','size']as$field)if(($after[$field]??null)!==($item[$field]??null))throw new RuntimeException('Bestandsprüfung fehlgeschlagen: '.$item['id']);}
        return ['ok'=>true,'folder_id'=>$target,'meta'=>$merged,'preserved_items'=>count($sourceItems),'audit_id'=>$auditFile['id'],'conflicts'=>$conflicts];
    }finally{caseIdentityUnlock($lock);}
}
