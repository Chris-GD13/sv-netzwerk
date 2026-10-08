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
  $db->exec('PRAGMA busy_timeout=30000; CREATE TABLE IF NOT EXISTS catalog_meta(key TEXT PRIMARY KEY,value TEXT); CREATE TABLE IF NOT EXISTS documents(id TEXT PRIMARY KEY,name TEXT,kind TEXT,sha256 TEXT,size INTEGER,pages INTEGER); CREATE TABLE IF NOT EXISTS positions(id TEXT PRIMARY KEY,document_id TEXT,data TEXT); CREATE VIRTUAL TABLE IF NOT EXISTS source_search USING fts5(id UNINDEXED,kind UNINDEXED,text,tokenize="unicode61 remove_diacritics 2");');
  return $db;
}
function bklStatus():array {
  $db=bklDb();$docs=$db->query('SELECT * FROM documents')->fetchAll();
  foreach($docs as &$doc){$file=bklRoot().'/'.$doc['sha256'].'.pdf';$doc['on_ionos']=is_file($file)&&filesize($file)===(int)$doc['size'];}unset($doc);
  return ['backend'=>'IONOS','documents'=>$docs,'positions'=>(int)$db->query('SELECT COUNT(*) FROM positions')->fetchColumn(),'catalog_sha256'=>$db->query("SELECT value FROM catalog_meta WHERE key='sha256'")->fetchColumn()?:null];
}
function bklBindPrices(array $position,string $original):array {
  $normalize=fn($text)=>trim((string)preg_replace('/\s+/u',' ',str_replace("\u{00a0}",' ',$text)));
  $quote=(string)($position['source_quote']??'');
  if($quote===''||!str_contains($normalize($original),$normalize($quote)))throw new RuntimeException('Preisbeleg ist nicht in der Originalseite enthalten.');
  $number=fn($value)=>(float)str_replace(',','.',str_replace('.','',$value));
  if(($position['source_vat']??null)===19){
    if(!str_contains($original,'inkl. 19% MwSt.')||!preg_match('/([\d.]+,\d{2})\s+([\d.]+,\d{2})\s+([\d.]+,\d{2})/u',$quote,$prices))throw new RuntimeException('Brutto-Preiszeile fehlt.');
    foreach(['low','mid','high'] as $i=>$level)$position['price_'.$level]=$number($prices[$i+1])/1.19;
  }elseif(($position['source_kind']??'bki')==='rpa'){
    if(!preg_match('/\b(?:Pau\.?|Std\.?|m²|m2|m|Stck\.?|Stk\.?|St\.?|lfdm\.?|lfm)\s+([\d.,]+)\s*€/u',$quote,$price))throw new RuntimeException('RPA-Preiszeile fehlt.');
    foreach(['low','mid','high'] as $level)$position['price_'.$level]=$number($price[1]);
  }else{
    if(!preg_match('/((?:(?:[\d.,]+€?|[–-])\s+){4}(?:[\d.,]+€?|[–-]))\s*\[/u',$quote,$line))throw new RuntimeException('BKI-Preiszeile fehlt.');
    preg_match_all('/[\d.,]+|[–-]/u',$line[1],$tokens);
    foreach(['low'=>1,'mid'=>2,'high'=>3] as $level=>$column)$position['price_'.$level]=in_array($tokens[0][$column],['–','-'],true)?null:$number($tokens[0][$column]);
  }
  if(!is_numeric($position['price_mid'])||$position['price_mid']<=0)throw new RuntimeException('Kein belegter Mittelwert.');
  return $position;
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
      if(!$doc||$page<1||$page>count($doc['pages'])||!is_numeric($p['price_mid']??null)||$p['price_mid']<=0)throw new RuntimeException('Unbelegte Preisposition.');
      $tokens=$p['source_code_tokens']??[$p['position_code']];foreach($tokens as $token)if(!str_contains($doc['pages'][$page-1]['text'],(string)$token))throw new RuntimeException('Positionsnummer ist nicht in der Originalseite belegt.');
      $p=bklBindPrices($p,(string)$doc['pages'][$page-1]['text']);
      $entry->execute([$p['id'],$p['document_id'],json_encode($p,JSON_UNESCAPED_UNICODE)]);
      $search->execute([$p['id'],'position',$p['description'].' '.$p['scope'].' '.json_encode($p['inherited'],JSON_UNESCAPED_UNICODE)]);
    }
    $revision=$db->prepare('INSERT OR REPLACE INTO catalog_meta VALUES(?,?)');$revision->execute(['sha256',hash_file('sha256',$path)]);
    $db->commit();return bklStatus();
  }catch(Throwable $e){$db->rollBack();throw $e;}
}
function bklSearch(string $query,int $limit=25,string $kind='position',bool $allTerms=false):array {
  $words=preg_split('/[^\p{L}\p{N}]+/u',mb_strtolower($query));$words=array_values(array_unique(array_filter($words,fn($w)=>mb_strlen($w)>($allTerms?1:3))));
  if(!$words)return [];
  $terms=implode($allTerms?' AND ':' OR ',array_map(fn($w)=>'"'.str_replace('"','',$w).'"*',array_slice($words,0,45)));
  $db=bklDb();$s=$db->prepare('SELECT id,text,bm25(source_search) AS rank FROM source_search WHERE source_search MATCH ? AND kind=? ORDER BY rank LIMIT ?');$s->bindValue(1,$terms);$s->bindValue(2,$kind);$s->bindValue(3,min(100,max(1,$limit)),PDO::PARAM_INT);$s->execute();$result=[];
  $lookup=$db->prepare('SELECT data FROM positions WHERE id=?');
  foreach($s->fetchAll() as $row){if($kind!=='position'){$result[]=$row;continue;}$lookup->execute([$row['id']]);$json=$lookup->fetchColumn();if($json)$result[]=json_decode($json,true);}
  return $result;
}
