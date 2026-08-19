<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
commonHeaders();
$user=requireAuth();
if (!in_array($user['role'],['administrator','projektleiter','pruefer','sachverstaendiger'],true)) apiError(403,'Keine Berechtigung.');
$projectId=max(1,(int)($_GET['project_id']??DEFAULT_PROJECT_ID));
$action=(string)($_GET['action']??'preview');

function psrNorm(string $v): string { $m=[]; return preg_match('/\b(\d{1,4})\b/u',$v,$m)?(string)((int)$m[1]):''; }
function psrMarker(array $p): string {
    $text=trim((string)($p['caption']??'').' '.(string)($p['file_name']??''));
    $category=mb_strtolower((string)($p['category']??''),'UTF-8');
    if (preg_match('/(?:schlagzahl|fenster(?:nummer|[- ]?nr\.?)?|nr\.?)\s*[:#-]?\s*(\d{1,4})/iu',$text,$m)) return (string)((int)$m[1]);
    if (str_contains($category,'fensterkennzeichnung') || str_contains($category,'schlag')) return psrNorm($text);
    return '';
}

$stmt=db()->prepare('SELECT p.id,p.window_id,p.sash_id,p.category,p.caption,p.file_name,p.taken_at,p.created_at,w.window_number FROM photos p INNER JOIN windows w ON w.id=p.window_id WHERE w.project_id=:pid AND p.deleted_at IS NULL AND w.deleted_at IS NULL ORDER BY COALESCE(p.taken_at,p.created_at),p.id');
$stmt->execute([':pid'=>$projectId]);$photos=$stmt->fetchAll(PDO::FETCH_ASSOC);
$wstmt=db()->prepare('SELECT id,window_number FROM windows WHERE project_id=:pid AND deleted_at IS NULL');$wstmt->execute([':pid'=>$projectId]);
$windowMap=[];foreach($wstmt->fetchAll(PDO::FETCH_ASSOC) as $w){$n=psrNorm((string)$w['window_number']);if($n!=='')$windowMap[$n][]=(int)$w['id'];}
$current='';$groups=[];$unassigned=0;$ambiguous=0;$moves=[];
foreach($photos as $p){
    $marker=psrMarker($p);if($marker!==''){$current=$marker;if(!isset($groups[$current]))$groups[$current]=['marker'=>$current,'photos'=>0,'target_window_id'=>null,'status'=>'missing'];
        $targets=$windowMap[$current]??[];if(count($targets)===1){$groups[$current]['target_window_id']=$targets[0];$groups[$current]['status']='ok';}elseif(count($targets)>1){$groups[$current]['status']='ambiguous';$ambiguous++;}}
    if($current===''){ $unassigned++; continue; }
    $groups[$current]['photos']++;
    $target=$groups[$current]['target_window_id'];
    if($target && (int)$p['window_id']!==$target) $moves[]=['photo_id'=>(int)$p['id'],'from_window_id'=>(int)$p['window_id'],'to_window_id'=>$target,'marker'=>$current,'file_name'=>$p['file_name']];
}
$summary=['photos'=>count($photos),'markers'=>count($groups),'moves'=>count($moves),'before_first_marker'=>$unassigned,'ambiguous_markers'=>$ambiguous,'groups'=>array_values($groups),'sample_moves'=>array_slice($moves,0,100)];
if($_SERVER['REQUEST_METHOD']==='GET'||$action==='preview') apiJson($summary);
if($_SERVER['REQUEST_METHOD']!=='POST'||$action!=='apply') apiError(405,'Methode nicht erlaubt.');
if($ambiguous>0) apiError(409,'Fotozuordnung nicht ausgeführt: Schlagzahlen sind im Fensterbestand nicht eindeutig.');
$pdo=db();$pdo->beginTransaction();$changed=0;
try{
    $upd=$pdo->prepare('UPDATE photos SET window_id=:wid,sash_id=NULL WHERE id=:id AND deleted_at IS NULL');
    foreach($moves as $m){$upd->execute([':wid'=>$m['to_window_id'],':id'=>$m['photo_id']]);$changed+=$upd->rowCount();}
    $pdo->commit();
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();error_log('[photo-sequence-repair] '.$e->getMessage());apiError(503,'Fotozuordnung konnte nicht korrigiert werden.');}
apiJson(['ok'=>true,'changed'=>$changed,'markers'=>count($groups),'photos'=>count($photos),'before_first_marker'=>$unassigned]);
