<?php
declare(strict_types=1);

require_once dirname(__DIR__).'/api/ionos-storage.php';

const MCP_STORAGE_FOLDER = 'application/vnd.google-apps.folder';
const MCP_STORAGE_LIMIT = 15728640;

function mcpStorageTools(): array {
    $root=['type'=>'string','description'=>'library for your private working files, or an own case folder ID returned by search/fetch. Never a filesystem path.','default'=>'library'];
    $common=['root'=>$root,'id'=>['type'=>'string'],'parent_id'=>['type'=>'string']];
    return [
        ['name'=>'ionos_files_read','title'=>'Eigene IONOS-Dateien lesen','description'=>'List, inspect or download files in your private IONOS working library or own case. Use returned IDs. Reading returns extracted document text where possible. Google Drive is not used.','inputSchema'=>['type'=>'object','properties'=>['root'=>$root,'id'=>['type'=>'string'],'action'=>['type'=>'string','enum'=>['list','metadata','read']],'offset'=>['type'=>'integer','minimum'=>0]],'required'=>['action'],'additionalProperties'=>false],'securitySchemes'=>[['type'=>'oauth2','scopes'=>['cases:read','files:read']]],'annotations'=>['readOnlyHint'=>true,'openWorldHint'=>false]],
        ['name'=>'ionos_files_write','title'=>'Eigene IONOS-Ordner und Dateien verwalten','description'=>'Create folders, upload text or base64 file bytes, rename or move files in the authenticated user\'s private library or own case. Start with mkdir root=library name=LOGO for a general folder. Upload accepts exactly one of text or content_base64 (max 15 MiB); never invent bytes. Same name and identical bytes are reused; conflicting contents are never overwritten. Rename/move require expected_version from a fresh read. Does not delete originals, change permissions, write Claimsforce, attach reports, request QS or send.','inputSchema'=>['type'=>'object','properties'=>$common+['action'=>['type'=>'string','enum'=>['mkdir','upload','rename','move']],'name'=>['type'=>'string','maxLength'=>240],'text'=>['type'=>'string','maxLength'=>15728640],'content_base64'=>['type'=>'string','maxLength'=>20971520],'mime_type'=>['type'=>'string'],'expected_version'=>['type'=>'string']],'required'=>['action'],'additionalProperties'=>false],'securitySchemes'=>[['type'=>'oauth2','scopes'=>['cases:read','files:write']]],'annotations'=>['readOnlyHint'=>false,'openWorldHint'=>false,'destructiveHint'=>false,'idempotentHint'=>false]]
    ];
}

function mcpStorageId(string $id): string {
    if (!preg_match('/^[A-Za-z0-9_-]{1,190}$/D', $id)) throw new RuntimeException('Ungültige Datei-ID.');
    return $id;
}
function mcpStorageName(string $name): string {
    $name=trim($name);
    if ($name==='' || strlen($name)>240 || $name[0]==='.' || preg_match('/[\\\\\/\x00-\x1f\x7f]/', $name)) throw new RuntimeException('Ungültiger Datei- oder Ordnername.');
    return $name;
}
function mcpStoragePublic(array $item): array {
    $public=array_intersect_key($item,array_flip(['id','name','mimeType','parents','size','modifiedTime','createdTime','sha256Checksum','trashed']));
    $public['version']=ionosRowFingerprint(ionosRow($item['id']));
    return $public;
}
/** A private library uses the existing indexed object store, never the webroot. */
function mcpStorageRootFor(array $user, string $root, bool $create=false): string {
    if (empty($user['id']) || !in_array($user['role']??'', ['administrator','projektleiter','pruefer','sachverstaendiger'],true)) throw new RuntimeException('Kein Dateizugriff für dieses Konto.');
    if ($root!=='library') {
        mcpStorageId($root);
        // Unlike a filesystem prefix, the existing case ACL includes profile routing.
        requireCaseFolderAccess($root,$user);
        if (!ownCase($user,$root)) throw new RuntimeException('Kein eigener Fallordner.');
        return $root;
    }
    $id='ionos_mcp_library_'.(int)$user['id'];
    $row=ionosRow($id);
    if ($row===null) {
        if (!$create) throw new RuntimeException('Persönliche Dateiablage noch nicht angelegt. Zuerst einen Ordner mit root=library anlegen.');
        try {
            ionosWrite(['name'=>'Arbeitsdateien '.(int)$user['id'],'mimeType'=>MCP_STORAGE_FOLDER,'parents'=>['root'],'_mcp_owner'=>(int)$user['id']],null,$id,
                ['id'=>$id,'rowFingerprint'=>ionosRowFingerprint(null),'sha256'=>null,'allowCreate'=>true]);
        } catch (Throwable $e) { if (ionosRow($id)===null) throw $e; }
    }
    $m=ionosItem($id);
    if (($m['_mcp_owner']??null)!==(int)$user['id'] || $m['mimeType']!==MCP_STORAGE_FOLDER || !empty($m['trashed'])) throw new RuntimeException('Persönliche Dateiablage nicht freigegeben.');
    return $id;
}
function mcpStorageWithin(string $id,string $root): array {
    mcpStorageId($id); mcpStorageId($root); $seen=[]; $first=null;
    for ($current=$id;$current!=='' && count($seen)<100;) {
        if (isset($seen[$current])) break;
        $seen[$current]=true; $m=ionosItem(mcpStorageId($current));
        if (!empty($m['trashed'])) throw new RuntimeException('Datei liegt im Papierkorb.');
        $first??=$m;
        if ($current===$root) return $first;
        $current=(string)($m['parents'][0]??'');
    }
    throw new RuntimeException('Datei gehört nicht zum freigegebenen Ablagebereich.');
}
function mcpStorageChildren(string $parent,int $offset=0): array {
    mcpStorageId($parent);
    // Direct indexed query avoids walking other users' contents.
    $s=ionosStorageDb()->prepare('SELECT id FROM items WHERE parent=? AND verified=1 ORDER BY name,id');
    $s->execute([$parent]); $all=[];
    foreach($s as $r) { $m=ionosItem($r['id']); if(empty($m['trashed'])) $all[]=$m; }
    return ['items'=>array_map('mcpStoragePublic',array_slice($all,$offset,100)), 'next_offset'=>count($all)>$offset+100?$offset+100:null];
}
function mcpStorageRead(array $user,array $args): array {
    $root=mcpStorageRootFor($user,(string)($args['root']??'library'));
    $id=(string)($args['id']??$root); $item=mcpStorageWithin($id,$root);
    $action=(string)($args['action']??'list');
    if($action==='metadata') return ['root_id'=>$root,'item'=>mcpStoragePublic($item)];
    if($action==='list') {
        if($item['mimeType']!==MCP_STORAGE_FOLDER) throw new RuntimeException('Ziel ist kein Ordner.');
        return ['root_id'=>$root,'folder'=>mcpStoragePublic($item),...mcpStorageChildren($id,max(0,(int)($args['offset']??0)))];
    }
    if($action!=='read') throw new RuntimeException('Unbekannte Leseaktion.');
    if((int)($item['size']??0)>MCP_STORAGE_LIMIT) throw new RuntimeException('Datei überschreitet 15 MiB.');
    $bytes=ionosBytes($id);
    if(strlen($bytes)>MCP_STORAGE_LIMIT) throw new RuntimeException('Datei überschreitet 15 MiB.');
    return ['root_id'=>$root,'item'=>mcpStoragePublic($item),'sha256'=>hash('sha256',$bytes),'content_base64'=>base64_encode($bytes)];
}
function mcpStorageWrite(array $user,array $args): array {
    if(!ionosStorageEnabled()) throw new RuntimeException('IONOS-Dateispeicher nicht aktiv.');
    $action=(string)($args['action']??'');
    if(!in_array($action,['mkdir','upload','rename','move'],true)) throw new RuntimeException('Unbekannte Schreibaktion.');
    $root=mcpStorageRootFor($user,(string)($args['root']??'library'),$action==='mkdir');
    // Serialize this root across all MCP operations to make retries duplicate-safe.
    $lock=fopen(ionosStorageRoot().'/mcp-files-'.hash('sha256',$root).'.lock','c');
    if(!$lock || !flock($lock,LOCK_EX)) throw new RuntimeException('Dateiablage derzeit gesperrt.');
    try {
        $parent=(string)($args['parent_id']??$root);
        $pm=mcpStorageWithin($parent,$root);
        if($pm['mimeType']!==MCP_STORAGE_FOLDER) throw new RuntimeException('Ziel ist kein Ordner.');
        if($action==='mkdir'||$action==='upload') {
            $name=mcpStorageName((string)($args['name']??'')); $bytes=null;
            $mime=MCP_STORAGE_FOLDER;
            if($action==='upload') {
                if(isset($args['text']) && isset($args['content_base64'])) throw new RuntimeException('Nur text oder content_base64 verwenden.');
                if(!isset($args['text']) && !isset($args['content_base64'])) throw new RuntimeException('Dateiinhalt fehlt.');
                $encoded=isset($args['text'])?base64_encode((string)$args['text']):(string)$args['content_base64'];
                if(strlen($encoded)>4*intdiv(MCP_STORAGE_LIMIT+2,3)) throw new RuntimeException('Datei überschreitet 15 MiB.');
                $bytes=base64_decode($encoded,true);
                if($bytes===false || base64_encode($bytes)!==$encoded) throw new RuntimeException('Ungültiger Base64-Dateiinhalt.');
                $mime=(string)($args['mime_type']??'application/octet-stream');
                if(!preg_match('#^[a-z0-9.+-]+/[a-z0-9.+-]+$#iD',$mime) || $mime===MCP_STORAGE_FOLDER) throw new RuntimeException('Ungültiger Dateityp.');
            }
            $s=ionosStorageDb()->prepare('SELECT id FROM items WHERE parent=? AND name=? AND verified=1');$s->execute([$parent,$name]);
            foreach($s as $r) {
                $existing=ionosItem($r['id']); if(!empty($existing['trashed'])) continue;
                if($existing['mimeType']===$mime && ($bytes===null || hash_equals(hash('sha256',ionosBytes($r['id'])),hash('sha256',$bytes)))) return ['ok'=>true,'verified'=>true,'reused'=>true,'root_id'=>$root,'item'=>mcpStoragePublic($existing)];
                throw new RuntimeException('Name bereits mit anderem Inhalt belegt. Nicht überschrieben.');
            }
            $saved=ionosWrite(['name'=>$name,'mimeType'=>$mime,'parents'=>[$parent],'_mcp_owner'=>(int)$user['id']],$bytes);
            $read=ionosItem($saved['id']);
            if($read['name']!==$name||($read['parents'][0]??'')!==$parent||($bytes!==null&&!hash_equals(hash('sha256',$bytes),hash('sha256',ionosBytes($saved['id']))))) throw new RuntimeException('Speicherung nicht bestätigt. Vor Wiederholung Metadaten prüfen.');
            return ['ok'=>true,'verified'=>true,'reused'=>false,'root_id'=>$root,'item'=>mcpStoragePublic($read)];
        }
        $id=mcpStorageId((string)($args['id']??'')); $item=mcpStorageWithin($id,$root);
        if($id===$root||$item['mimeType']===MCP_STORAGE_FOLDER||strcasecmp($item['name'],'00_Falldaten.json')===0) throw new RuntimeException('Dieser Eintrag darf hier nicht geändert werden.');
        $row=ionosRow($id); $version=ionosRowFingerprint($row);
        if(!hash_equals($version,(string)($args['expected_version']??''))) throw new RuntimeException('Versionsabgleich fehlt oder ist veraltet.');
        $meta=$action==='rename'?['name'=>mcpStorageName((string)($args['name']??''))]:['parents'=>[$parent]];
        $destination=$action==='move'?$parent:(string)$item['parents'][0];
        $targetName=$meta['name']??$item['name'];
        $s=ionosStorageDb()->prepare('SELECT id FROM items WHERE parent=? AND name=? AND verified=1 AND id<>?');
        $s->execute([$destination,$targetName,$id]);
        foreach($s as $r) if(empty(ionosItem($r['id'])['trashed'])) throw new RuntimeException('Zielname bereits vorhanden.');
        $sha=hash('sha256',ionosBytes($id));
        ionosWrite($meta,null,$id,['id'=>$id,'rowFingerprint'=>ionosRowFingerprint($row),'sha256'=>$sha]);
        $read=mcpStorageWithin($id,$root);
        foreach($meta as $key=>$value) if($read[$key]!==$value) throw new RuntimeException('Änderung nicht bestätigt.');
        return ['ok'=>true,'verified'=>true,'root_id'=>$root,'item'=>mcpStoragePublic($read)];
    } finally { flock($lock,LOCK_UN);fclose($lock); }
}
