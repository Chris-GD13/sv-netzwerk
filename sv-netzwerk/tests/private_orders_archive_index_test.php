<?php
declare(strict_types=1);
require_once __DIR__.'/../public/intern/api/private-order-storage.php';
function archiveCheck(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
$leaf=fn($id,$name,$children=[])=>['id'=>$id,'name'=>$name,'folder'=>true,'children'=>$children];
$tree=[
 $leaf('archive','Gutachtenfälle',[
  $leaf('old','2020 und älter',[$leaf('one','Kunde A',[$leaf('photos','Fotos')])]),
  $leaf('year','2024',[$leaf('two','Kunde B')]),
  $leaf('three','Kunde C'),
 ]),
 $leaf('four','Projekt Liftstar'),
 $leaf('insurance','Versicherungsaufträge',[$leaf('five','Fremder Versicherungsfall')]),
 $leaf('standard','Auftrag'),
];
$ids=array_column(poOrders($tree),'id');sort($ids);
archiveCheck($ids===['four','one','three','two'],'Archive index must expose cases once without mixing their files or insurance folders');
archiveCheck(poIsOrderContainer(['name'=>'Gutachtenfälle','folder'=>[]]),'Raw Graph folder facet must be accepted');
archiveCheck(poIsOrderContainer($leaf('x','2020 und älter')),'Older years must be containers');
archiveCheck(!poIsOrderContainer($leaf('x','Kunde 2020'))&&!poIsOrderContainer($leaf('x','Sonstige Unterlagen')),'Customer and document folders must not be traversed as containers');
echo "private_orders_archive_index_test: ok (nested legacy years, separate customer files, bounded containers, no insurance import)\n";
