<?php
declare(strict_types=1);
require_once __DIR__.'/../public/intern/api/private-order-index-cache.php';
function cacheCheck(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$calls=0;
$fetch=function()use(&$calls){$calls++;return ['root'=>['id'=>'c'],'items'=>[['id'=>'one']]];};
poIndexCache($db,'christian','own/path',$fetch);$cached=poIndexCache($db,'christian','own/path',$fetch);cacheCheck($calls===1&&$cached['cache_state']==='cached','Repeated page loads must not re-read OneDrive');
$db->exec('UPDATE private_order_index_cache SET refreshed_at='.(time()-400));
$throttled=fn()=>throw new RuntimeException('429',429);$stale=poIndexCache($db,'christian','own/path',$throttled);cacheCheck($stale['cache_state']==='stale'&&$stale['items'][0]['id']==='one','Throttle must retain a visibly marked confirmed index');
try{poIndexCache($db,'marc','own/path',$throttled);throw new RuntimeException('Cross-profile cache leaked');}catch(RuntimeException $e){cacheCheck($e->getCode()===429,'Cached cases must remain profile scoped');}
poInvalidateIndex($db,'christian','own/path');poIndexCache($db,'christian','own/path',$fetch);cacheCheck($calls===2,'Creation invalidates old lists');
echo "private_orders_index_cache_test: ok (reduced requests, 429 fallback, own-profile cache, invalidation)\n";
