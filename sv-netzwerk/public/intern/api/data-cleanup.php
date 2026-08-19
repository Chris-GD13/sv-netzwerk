<?php
/**
 * Bestandspruefung und Dublettenbereinigung fuer die Fensterpruefung.
 * GET  ?action=audit&project_id=1
 * POST ?action=cleanup&project_id=1
 *
 * Fachlicher Schluessel BMVg Bonn:
 *   Zimmernummer + Fensternummer; die Etage wird zwingend aus der
 *   Tausenderstelle der Zimmernummer abgeleitet (0xxx=EG, 1xxx=1. OG,
 *   2xxx=2. OG, 3xxx=3. OG). Dadurch werden alte Fehlzuordnungen nicht
 *   als vermeintlich unterschiedliche Fenster behandelt.
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';
commonHeaders();
$user = requireAuth();
$action = (string)($_GET['action'] ?? 'audit');
$projectId = max(1, (int)($_GET['project_id'] ?? DEFAULT_PROJECT_ID));

function normKey(?string $v): string {
    $v = mb_strtolower(trim((string)$v), 'UTF-8');
    $v = strtr($v, ['ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss']);
    return preg_replace('/[^a-z0-9]+/u', '', $v) ?? '';
}
function jsonArray(?string $json): array {
    $v = json_decode((string)$json, true);
    return is_array($v) ? $v : [];
}
function floorFromRoom(string $room): string {
    $digits = preg_replace('/\D+/', '', trim($room)) ?? '';
    if ($digits === '') return '';
    return match ($digits[0] ?? '') {
        '0' => 'EG',
        '1' => '1. OG',
        '2' => '2. OG',
        '3' => '3. OG',
        default => '',
    };
}
function canonicalFloor(array $w): string {
    $room = trim((string)($w['room_number'] ?? ''));
    $derived = floorFromRoom($room);
    return $derived !== '' ? $derived : trim((string)($w['floor_label'] ?? ''));
}
function mergeArraysPreferFilled(array $base, array $incoming, array &$conflicts, string $prefix=''): array {
    foreach ($incoming as $k=>$v) {
        $path = $prefix === '' ? (string)$k : $prefix.'.'.$k;
        if (is_array($v)) {
            $current = isset($base[$k]) && is_array($base[$k]) ? $base[$k] : [];
            $base[$k] = mergeArraysPreferFilled($current, $v, $conflicts, $path);
            continue;
        }
        $cur = $base[$k] ?? null;
        $curEmpty = $cur === null || $cur === '' || $cur === 'nicht separat angegeben';
        $inEmpty = $v === null || $v === '' || $v === 'nicht separat angegeben';
        if ($curEmpty && !$inEmpty) $base[$k] = $v;
        elseif (!$curEmpty && !$inEmpty && (string)$cur !== (string)$v) {
            $conflicts[$path] = array_values(array_unique(array_map('strval', array_filter([$cur,$v], static fn($x)=>$x!==null && $x!==''))));
        }
    }
    return $base;
}
function loadWindows(PDO $pdo, int $projectId): array {
    $s = $pdo->prepare('SELECT w.* FROM windows w WHERE w.project_id=:pid AND w.deleted_at IS NULL ORDER BY w.id');
    $s->execute([':pid'=>$projectId]); return $s->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
function windowKey(array $w): string {
    // Zimmernummer + Fensternummer sind der stabile fachliche Schluessel.
    // Die Etage wird nur aus der Zimmernummer abgeleitet, damit z. B. Raum 3160
    // niemals getrennt als 1. OG und 3. OG gefuehrt werden kann.
    return normKey(canonicalFloor($w)) . '|' . normKey((string)($w['room_number']??$w['room_label']??'')) . '|' . normKey((string)($w['window_number']??''));
}
function buildingIdForWindow(PDO $pdo, int $windowId): int {
    $s=$pdo->prepare('SELECT f.building_id FROM windows w LEFT JOIN rooms r ON r.id=w.room_id LEFT JOIN floors f ON f.id=r.floor_id WHERE w.id=:id LIMIT 1');
    $s->execute([':id'=>$windowId]);
    return (int)($s->fetchColumn() ?: 0);
}
function ensureFloor(PDO $pdo, int $buildingId, string $name): int {
    $s=$pdo->prepare('SELECT id FROM floors WHERE building_id=:bid AND name=:name LIMIT 1');
    $s->execute([':bid'=>$buildingId,':name'=>$name]); $id=$s->fetchColumn(); if($id)return(int)$id;
    $level=match($name){'EG'=>0,'1. OG'=>1,'2. OG'=>2,'3. OG'=>3,default=>0};
    $sort=($level+1)*10;
    $i=$pdo->prepare('INSERT INTO floors(building_id,name,level,sort_order,created_at,updated_at) VALUES(:bid,:name,:level,:sort,:now,:now)');
    $i->execute([':bid'=>$buildingId,':name'=>$name,':level'=>$level,':sort'=>$sort,':now'=>nowUtc()]);
    return(int)$pdo->lastInsertId();
}
function ensureRoom(PDO $pdo, int $buildingId, string $floorName, string $roomNumber): int {
    $floorId=ensureFloor($pdo,$buildingId,$floorName);
    $s=$pdo->prepare('SELECT id FROM rooms WHERE floor_id=:fid AND room_number=:rn LIMIT 1');
    $s->execute([':fid'=>$floorId,':rn'=>$roomNumber]); $id=$s->fetchColumn(); if($id)return(int)$id;
    $i=$pdo->prepare('INSERT INTO rooms(floor_id,room_number,name,sort_order,created_at,updated_at) VALUES(:fid,:rn,:name,0,:now,:now)');
    $i->execute([':fid'=>$floorId,':rn'=>$roomNumber,':name'=>'Raum '.$roomNumber,':now'=>nowUtc()]);
    return(int)$pdo->lastInsertId();
}
function auditState(PDO $pdo, int $projectId): array {
    $windows = loadWindows($pdo,$projectId);
    $wg=[]; $floorMismatches=0;
    foreach($windows as $w){
        $wg[windowKey($w)][]=$w;
        $derived=floorFromRoom((string)($w['room_number']??''));
        if($derived!=='' && normKey($derived)!==normKey((string)($w['floor_label']??'')))$floorMismatches++;
    }
    $duplicateWindowRows=0; $windowGroups=0;
    foreach($wg as $g){ if(count($g)>1){$windowGroups++;$duplicateWindowRows+=count($g)-1;} }

    $ids=array_map(static fn($w)=>(int)$w['id'],$windows);
    $sashes=[];$photos=[];
    if($ids){
        $in=implode(',',array_fill(0,count($ids),'?'));
        $st=$pdo->prepare("SELECT * FROM window_sashes WHERE deleted_at IS NULL AND window_id IN ($in) ORDER BY id");$st->execute($ids);$sashes=$st->fetchAll(PDO::FETCH_ASSOC)?:[];
        $pt=$pdo->prepare("SELECT * FROM photos WHERE deleted_at IS NULL AND window_id IN ($in) ORDER BY id");$pt->execute($ids);$photos=$pt->fetchAll(PDO::FETCH_ASSOC)?:[];
    }
    $sg=[];foreach($sashes as $s){$type=normKey((string)($s['opening_type']??'')); if($type==='')$type='nr'.(int)($s['sash_number']??0); $sg[(int)$s['window_id'].'|'.$type][]=$s;}
    $duplicateSashRows=0;$sashGroups=0;foreach($sg as $g){if(count($g)>1){$sashGroups++;$duplicateSashRows+=count($g)-1;}}

    $nameGroups=[];foreach($photos as $p){$nameGroups[normKey((string)$p['file_name'])][]=$p;}
    $photoCandidates=0;$photoCandidateGroups=0;foreach($nameGroups as $g){if(count($g)>1){$photoCandidateGroups++;$photoCandidates+=count($g)-1;}}

    return [
        'unique_windows'=>count($wg),'active_window_rows'=>count($windows),'duplicate_window_rows'=>$duplicateWindowRows,'duplicate_window_groups'=>$windowGroups,
        'floor_mismatches'=>$floorMismatches,
        'active_sashes'=>count($sashes),'duplicate_sash_rows'=>$duplicateSashRows,'duplicate_sash_groups'=>$sashGroups,
        'active_photos'=>count($photos),'duplicate_photo_name_candidates'=>$photoCandidates,'duplicate_photo_name_groups'=>$photoCandidateGroups,
        'expected_window_reference'=>'ca. 425','expected_sash_reference'=>'ca. 450',
    ];
}

try { $pdo=db(); } catch(Throwable $e){ apiError(503,'Datenbank nicht erreichbar.'); }
if($action==='audit' && $_SERVER['REQUEST_METHOD']==='GET') apiJson(['ok'=>true,'audit'=>auditState($pdo,$projectId)]);
if($action!=='cleanup' || $_SERVER['REQUEST_METHOD']!=='POST') apiError(405,'Methode/Aktion nicht erlaubt.');
if(!in_array((string)($user['role']??''),['administrator','projektleiter'],true)) apiError(403,'Nur Administrator/Projektleiter darf bereinigen.');

$before=auditState($pdo,$projectId); $stats=['windows_merged'=>0,'floors_repaired'=>0,'sashes_merged'=>0,'photos_merged'=>0,'photo_conflicts'=>0,'field_conflicts'=>0];
try{
    $pdo->beginTransaction();

    // 1) Etagen-/Raumhierarchie zuerst korrigieren. Dadurch landen 3xxx-Raeume
    // garantiert im 3. OG, auch wenn ein alter Import sie z. B. als 1. OG angelegt hat.
    $windows=loadWindows($pdo,$projectId);
    foreach($windows as $w){
        $room=trim((string)($w['room_number']??'')); $floor=floorFromRoom($room);
        if($room===''||$floor==='')continue;
        $buildingId=buildingIdForWindow($pdo,(int)$w['id']); if($buildingId<=0)continue;
        $correctRoomId=ensureRoom($pdo,$buildingId,$floor,$room);
        if((int)($w['room_id']??0)!==$correctRoomId || normKey((string)($w['floor_label']??''))!==normKey($floor)){
            $fd=jsonArray($w['form_data']??'');
            $fd['floor_label']=$floor; $fd['room_number']=$room; $fd['room_label']='Raum '.$room;
            $pdo->prepare('UPDATE windows SET room_id=:rid,floor_label=:floor,room_number=:rn,room_label=:rl,form_data=:fd,updated_at=:now WHERE id=:id')->execute([
                ':rid'=>$correctRoomId,':floor'=>$floor,':rn'=>$room,':rl'=>'Raum '.$room,
                ':fd'=>json_encode($fd,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),':now'=>nowUtc(),':id'=>(int)$w['id']]);
            $stats['floors_repaired']++;
        }
    }

    // 2) Fenster nach fachlichem Schluessel zusammenfuehren.
    $windows=loadWindows($pdo,$projectId);$wg=[];foreach($windows as $w){$wg[windowKey($w)][]=$w;}
    foreach($wg as $group){
        if(count($group)<2)continue;
        usort($group,static fn($a,$b)=>strlen((string)$b['form_data'])<=>strlen((string)$a['form_data']));
        $keep=array_shift($group);$keepId=(int)$keep['id'];$merged=jsonArray($keep['form_data']);$conf=[];
        foreach($group as $dup){
            $dupId=(int)$dup['id'];$merged=mergeArraysPreferFilled($merged,jsonArray($dup['form_data']),$conf);
            $pdo->prepare('UPDATE photos SET window_id=:keep WHERE window_id=:dup AND deleted_at IS NULL')->execute([':keep'=>$keepId,':dup'=>$dupId]);
            // Sashes werden unten kontrolliert konsolidiert; vorerst zum Keeper verschieben.
            $pdo->prepare('UPDATE window_sashes SET window_id=:keep WHERE window_id=:dup AND deleted_at IS NULL')->execute([':keep'=>$keepId,':dup'=>$dupId]);
            $pdo->prepare('UPDATE windows SET deleted_at=:now WHERE id=:id')->execute([':now'=>nowUtc(),':id'=>$dupId]);
            $stats['windows_merged']++;
        }
        if($conf){$merged['cleanup_conflicts']=$conf;$stats['field_conflicts']+=count($conf);}
        $pdo->prepare('UPDATE windows SET form_data=:fd, updated_at=:now WHERE id=:id')->execute([':fd'=>json_encode($merged,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),':now'=>nowUtc(),':id'=>$keepId]);
    }

    // 3) Fluegel-Dubletten konsolidieren.
    $windows=loadWindows($pdo,$projectId);$ids=array_map(static fn($w)=>(int)$w['id'],$windows);
    if($ids){
        $in=implode(',',array_fill(0,count($ids),'?'));$st=$pdo->prepare("SELECT * FROM window_sashes WHERE deleted_at IS NULL AND window_id IN ($in) ORDER BY id");$st->execute($ids);$sashes=$st->fetchAll(PDO::FETCH_ASSOC)?:[];
        $sg=[];foreach($sashes as $s){$type=normKey((string)($s['opening_type']??''));if($type==='')$type='nr'.(int)($s['sash_number']??0);$sg[(int)$s['window_id'].'|'.$type][]=$s;}
        foreach($sg as $group){
            if(count($group)<2)continue;usort($group,static fn($a,$b)=>strlen((string)$b['form_data'])<=>strlen((string)$a['form_data']));$keep=array_shift($group);$keepId=(int)$keep['id'];$merged=jsonArray($keep['form_data']);$conf=[];
            foreach($group as $dup){$dupId=(int)$dup['id'];$merged=mergeArraysPreferFilled($merged,jsonArray($dup['form_data']),$conf);$pdo->prepare('UPDATE photos SET sash_id=:keep WHERE sash_id=:dup AND deleted_at IS NULL')->execute([':keep'=>$keepId,':dup'=>$dupId]);$pdo->prepare('UPDATE window_sashes SET deleted_at=:now WHERE id=:id')->execute([':now'=>nowUtc(),':id'=>$dupId]);$stats['sashes_merged']++;}
            if($conf){$merged['cleanup_conflicts']=$conf;$stats['field_conflicts']+=count($conf);} $pdo->prepare('UPDATE window_sashes SET form_data=:fd, updated_at=:now WHERE id=:id')->execute([':fd'=>json_encode($merged,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),':now'=>nowUtc(),':id'=>$keepId]);
        }

        // 4) Echte Fotodubletten: gleicher Dateiname UND identischer Datei-Hash.
        // Exakte Binaerdubletten werden unabhaengig von ihrer bisherigen (moeglicherweise
        // falschen) Fensterzuordnung nur einmal behalten. Die Schlagzahlfolge kann danach
        // die verbleibende Originaldatei korrekt neu zuordnen.
        $pt=$pdo->prepare("SELECT * FROM photos WHERE deleted_at IS NULL AND window_id IN ($in) ORDER BY id");$pt->execute($ids);$photos=$pt->fetchAll(PDO::FETCH_ASSOC)?:[];
        $nameGroups=[];foreach($photos as $p){$nameGroups[normKey((string)$p['file_name'])][]=$p;}
        foreach($nameGroups as $group){
            if(count($group)<2)continue; $hashGroups=[];
            foreach($group as $p){$path=photosDir().'/'.ltrim((string)$p['storage_path'],'/');$hash=is_file($path)?sha1_file($path):false;$hk=$hash?:('missing|'.(int)$p['id']);$hashGroups[$hk][]=$p;}
            foreach($hashGroups as $hash=>$hg){
                if($hash===''||str_starts_with((string)$hash,'missing|')||count($hg)<2)continue;
                usort($hg,static fn($a,$b)=>(int)$a['id']<=>(int)$b['id']);
                $keep=array_shift($hg);
                foreach($hg as $dup){
                    $pdo->prepare('UPDATE photos SET deleted_at=:now WHERE id=:id')->execute([':now'=>nowUtc(),':id'=>(int)$dup['id']]);
                    $stats['photos_merged']++;
                }
            }
        }
    }
    $pdo->commit();
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();apiError(503,'Bereinigung fehlgeschlagen: '.$e->getMessage());}
$after=auditState($pdo,$projectId);
apiJson(['ok'=>true,'before'=>$before,'cleanup'=>$stats,'after'=>$after]);
