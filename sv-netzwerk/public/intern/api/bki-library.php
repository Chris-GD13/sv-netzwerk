<?php
declare(strict_types=1);
require_once __DIR__.'/ionos-storage.php';
function bklRoot():string {
  $path=ionosStorageRoot().'/bki-library';
  if(!is_dir($path)&&!mkdir($path,0770,true)&&!is_dir($path))throw new RuntimeException('Preisablage nicht verfügbar.');
  return $path;
}
function bklDb():PDO {
  $db=new PDO('sqlite:'.bklRoot().'/catalog.sqlite',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
  $db->exec('PRAGMA busy_timeout=30000; CREATE TABLE IF NOT EXISTS documents(id TEXT PRIMARY KEY,name TEXT,kind TEXT,sha256 TEXT,size INTEGER,pages INTEGER); CREATE TABLE IF NOT EXISTS positions(id TEXT PRIMARY KEY,document_id TEXT,data TEXT); CREATE VIRTUAL TABLE IF NOT EXISTS source_search USING fts5(id UNINDEXED,kind UNINDEXED,text,tokenize="unicode61 remove_diacritics 2");');
  return $db;
}
function bklStatus():array {
  $db=bklDb();$docs=$db->query('SELECT * FROM documents')->fetchAll();
  foreach($docs as &$doc){$file=bklRoot().'/'.$doc['sha256'].'.pdf';$doc['on_ionos']=is_file($file)&&filesize($file)===(int)$doc['size'];}unset($doc);
  return ['backend'=>'IONOS','documents'=>$docs,'positions'=>(int)$db->query('SELECT COUNT(*) FROM positions')->fetchColumn()];
}
function bklImport(string $path):array {
  $data=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
  if(($data['schema']??null)!==1||count($data['documents']??[])>20||count($data['positions']??[])>20000)throw new RuntimeException('Ungültiger Preisbestand.');
  $db=bklDb();$db->beginTransaction();
  try {
    $docInsert=$db->prepare('INSERT OR REPLACE INTO documents VALUES(?,?,?,?,?,?)');
    $entry=$db->prepare('INSERT OR REPLACE INTO positions VALUES(?,?,?)');
    $search=$db->prepare('INSERT INTO source_search VALUES(?,?,?)');
    foreach($data['documents'] as $doc){
      if(!preg_match('/^[a-f0-9]{64}$/D',(string)($doc['sha256']??''))||($doc['id']??'')!==$doc['sha256'])throw new RuntimeException('Quellenprüfsumme fehlt.');
      $source=bklRoot().'/'.$doc['sha256'].'.pdf';
      if(!is_file($source)||filesize($source)!==(int)$doc['size']||!hash_equals($doc['sha256'],hash_file('sha256',$source)))throw new RuntimeException('Original-PDF fehlt oder Prüfsumme weicht ab: '.$doc['name']);
      $docInsert->execute([$doc['id'],$doc['name'],$doc['kind'],$doc['sha256'],$doc['size'],count($doc['pages'])]);
      $delete=$db->prepare('DELETE FROM source_search WHERE id LIKE ?');$delete->execute([$doc['id'].':%']);
      $delete=$db->prepare('DELETE FROM positions WHERE document_id=?');$delete->execute([$doc['id']]);
      foreach($doc['pages'] as $page)$search->execute([$doc['id'].':page:'.(int)$page['page'],$doc['kind'],(string)$page['text']]);
    }
    $docs=array_column($data['documents'],null,'id');
    foreach($data['positions'] as $p){
      $doc=$docs[$p['document_id']??'']??null;$page=(int)($p['source_page']??0);
      if(!$doc||$page<1||$page>count($doc['pages'])||!str_contains($doc['pages'][$page-1]['text'],(string)$p['position_code'])||!is_numeric($p['price_mid']??null)||$p['price_mid']<=0)throw new RuntimeException('Unbelegte Preisposition.');
      $entry->execute([$p['id'],$p['document_id'],json_encode($p,JSON_UNESCAPED_UNICODE)]);
      $search->execute([$p['id'],'position',$p['description'].' '.$p['scope'].' '.json_encode($p['inherited'],JSON_UNESCAPED_UNICODE)]);
    }
    $db->commit();return bklStatus();
  }catch(Throwable $e){$db->rollBack();throw $e;}
}
function bklSearch(string $query,int $limit=25,string $kind='position'):array {
  $words=preg_split('/[^\p{L}\p{N}]+/u',mb_strtolower($query));$words=array_values(array_unique(array_filter($words,fn($w)=>mb_strlen($w)>3)));
  if(!$words)return [];
  $terms=implode(' OR ',array_map(fn($w)=>'"'.str_replace('"','',$w).'"*',array_slice($words,0,45)));
  $db=bklDb();$s=$db->prepare('SELECT id,text,bm25(source_search) AS rank FROM source_search WHERE source_search MATCH ? AND kind=? ORDER BY rank LIMIT ?');$s->bindValue(1,$terms);$s->bindValue(2,$kind);$s->bindValue(3,min(100,max(1,$limit)),PDO::PARAM_INT);$s->execute();$result=[];
  $lookup=$db->prepare('SELECT data FROM positions WHERE id=?');
  foreach($s->fetchAll() as $row){if($kind!=='position'){$result[]=$row;continue;}$lookup->execute([$row['id']]);$json=$lookup->fetchColumn();if($json)$result[]=json_decode($json,true);}
  return $result;
}
