<?php
declare(strict_types=1);
require_once __DIR__.'/../public/intern/api/private-order-classification.php';
function check(bool $value, string $message): void { if (!$value) throw new RuntimeException($message); }
foreach (['Gutachtervertrag.pdf'=>'Auftrag','Angebot Begutachtung.msg'=>'E-Mails','IMG_9596.jpg'=>'Fotos','Fotos Januar.zip'=>'Fotos','Rechnung 1.pdf'=>'Rechnungen','KVA Stellungnahme.pdf'=>'Gutachten','23936_KV_Göbbels.pdf'=>'Angebot','Wertermittlung.pdf'=>'Gutachten','Schnitt vom Haus.pdf'=>'Sonstige Unterlagen','stat. Berechnung.pdf'=>'Sonstige Unterlagen'] as $name=>$folder) check(poCategory(['name'=>$name])===$folder, $name);
function poDriveId(): string { return 'test'; }
function poRequest(string $url): array { preg_match('~/items/([^?]+)~', $url, $match); $id=urldecode($match[1]);return match($id){'order'=>['id'=>'order','name'=>'Zurke','folder'=>[], 'parentReference'=>['id'=>'year']],'year'=>['id'=>'year','name'=>'2025','parentReference'=>['id'=>'root']],'root'=>['id'=>'root','name'=>'Private'],'outside'=>['id'=>'outside','name'=>'Other'],default=>throw new RuntimeException('Unexpected request')}; }
function apiError(int $status,string $message): never { throw new RuntimeException($message,$status); }
check(poValidateScope('order','root')['id']==='order','Must return the requested order, not root');
try { poValidateScope('outside','root'); throw new RuntimeException('Scope violation accepted'); } catch (RuntimeException $e) { check($e->getCode()===403,'Outside order must be denied'); }
echo "private_orders_test: ok\n";
