<?php
declare(strict_types=1);
function poIndexCache(PDO $db,string $profile,string $path,callable $fetch,bool $refresh=false): array {
    $db->exec('CREATE TABLE IF NOT EXISTS private_order_index_cache (cache_key VARCHAR(64) PRIMARY KEY, payload_json MEDIUMTEXT NOT NULL, refreshed_at INTEGER NOT NULL)');
    $key=hash('sha256',$profile.'|'.$path);$q=$db->prepare('SELECT * FROM private_order_index_cache WHERE cache_key=?');$q->execute([$key]);$saved=$q->fetch();
    if(!$refresh&&$saved&&time()-(int)$saved['refreshed_at']<300){$data=json_decode($saved['payload_json'],true,512,JSON_THROW_ON_ERROR);$data['cache_state']='cached';return$data;}
    try{$data=$fetch();$json=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);$suffix=$db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?' ON CONFLICT(cache_key) DO UPDATE SET payload_json=excluded.payload_json,refreshed_at=excluded.refreshed_at':' ON DUPLICATE KEY UPDATE payload_json=VALUES(payload_json),refreshed_at=VALUES(refreshed_at)';$q=$db->prepare('INSERT INTO private_order_index_cache(cache_key,payload_json,refreshed_at) VALUES(?,?,?)'.$suffix);$q->execute([$key,$json,time()]);$data['cache_state']='fresh';return$data;}
    catch(Throwable $e){if(!$saved||time()-(int)$saved['refreshed_at']>86400)throw$e;$data=json_decode($saved['payload_json'],true,512,JSON_THROW_ON_ERROR);$data['cache_state']='stale';$data['warning']='Microsoft ist momentan ausgelastet. Der zuletzt bestätigte Auftragsbestand wird angezeigt.';return$data;}
}
function poInvalidateIndex(PDO $db,string $profile,string $path): void {
    $db->exec('CREATE TABLE IF NOT EXISTS private_order_index_cache (cache_key VARCHAR(64) PRIMARY KEY, payload_json MEDIUMTEXT NOT NULL, refreshed_at INTEGER NOT NULL)');
    $q=$db->prepare('DELETE FROM private_order_index_cache WHERE cache_key=?');$q->execute([hash('sha256',$profile.'|'.$path)]);
}
