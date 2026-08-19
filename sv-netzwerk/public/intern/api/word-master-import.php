<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
commonHeaders();
$user = requireAuth();
if (!in_array($user['role'], ['administrator','projektleiter','pruefer','sachverstaendiger'], true)) apiError(403, 'Keine Berechtigung.');

$projectId = max(1, (int)($_GET['project_id'] ?? DEFAULT_PROJECT_ID));
$action = (string)($_GET['action'] ?? 'preview');

function wmReportsDirs(): array {
    $dirs = [];
    $configured = trim(env('REPORTS_DIR', ''));
    if ($configured !== '') $dirs[] = rtrim($configured, '/');
    $photos = rtrim(photosDir(), '/');
    $dirs[] = dirname($photos) . '/reports';
    $dirs[] = $photos . '/reports';
    $dirs[] = __DIR__ . '/../reports';
    $dirs[] = __DIR__ . '/../../reports';
    return array_values(array_unique($dirs));
}
function wmResolveArchiveFile(array $row): string {
    $names = array_values(array_unique(array_filter([
        basename((string)($row['storage_name'] ?? '')),
        basename((string)($row['file_name'] ?? '')),
    ])));
    foreach (wmReportsDirs() as $dir) {
        foreach ($names as $name) {
            $candidate = rtrim($dir, '/') . '/' . $name;
            if (is_file($candidate) && is_readable($candidate)) return $candidate;
        }
    }
    error_log('[word-master-import] archive file missing; id=' . ($row['id'] ?? '') . '; storage=' . ($row['storage_name'] ?? '') . '; file=' . ($row['file_name'] ?? '') . '; dirs=' . implode(',', wmReportsDirs()));
    apiError(404, 'Archivierte Gutachtendatei nicht gefunden. Bitte das Gutachten in der Gutachtenablage erneut auswählen bzw. erzeugen.');
}
function wmNorm(mixed $v): string {
    $s = mb_strtolower(trim((string)$v), 'UTF-8');
    $s = strtr($s, ['ä'=>'ae','ö'=>'oe','ü'=>'ue','ß'=>'ss']);
    return preg_replace('/[^a-z0-9]+/u', '', $s) ?? '';
}
function wmLabelMap(): array {
    return [
        'laufendepruefnummer'=>'inspection_number','fensternummer'=>'window_number','vorhandeneobjektkennzeichnung'=>'object_label',
        'gebaeude'=>'building_label','gebaeudeteil'=>'section_label','etage'=>'floor_label','raumbezeichnung'=>'room_label','raumnummer'=>'room_number',
        'fensterpositionimraum'=>'position_in_room','himmelsrichtung'=>'orientation','anzahlderfensterfluegel'=>'wing_count','gepruefterfluegel'=>'inspected_wing',
        'pruefer'=>'inspector_name','pruefdatum'=>'inspection_date','uhrzeitbeginn'=>'time_started','uhrzeitabschluss'=>'time_finished',
        'zugaenglichkeit'=>'accessibility_status','bemerkungzurzugaenglichkeit'=>'access_note','hersteller'=>'manufacturer','fenstersystemoderprofilserie'=>'window_system',
        'baujahr'=>'construction_year','rahmenmaterial'=>'frame_material','oeffnungsart'=>'opening_type','fluegelbreitemm'=>'wing_width_mm','fluegelhoehemm'=>'wing_height_mm',
        'sichtbarebesonderheiten'=>'visible_special_features','typenschilderoderkennzeichnungen'=>'labels_and_markings','verglasungsart'=>'glazing_type',
        'anzahlglasscheiben'=>'glass_panes','glasaufbau'=>'glass_structure','glasstaerkegesamtmm'=>'glass_thickness_mm','scheibenzwischenraeumemm'=>'glass_cavity_mm',
        'sicherheitsglas'=>'safety_glass','kennzeichnungderverglasung'=>'glazing_label','breitederverglasungmm'=>'glazing_width_mm','hoehederverglasungmm'=>'glazing_height_mm',
        'rechnerischesglasgewichtkg'=>'glass_weight_kg','geschaetztesrahmengewichtkg'=>'estimated_frame_weight_kg','rechnerischesgesamtfluegelgewichtkg'=>'total_wing_weight_kg',
        'gewichtausbestandsunterlagenkg'=>'weight_from_inventory_kg','gewichtausherstellerunterlagenkg'=>'weight_from_manufacturer_kg','angesetztespruefgewichtkg'=>'applied_test_weight_kg',
        'methodedergewichtsermittlung'=>'weight_method','unsicherheitsodersicherheitszuschlagkg'=>'safety_margin_kg','bemerkungenzurgewichtsermittlung'=>'weight_notes',
        'beschlagsystem'=>'hinge_system','typoderkennzeichnung'=>'hinge_type_marking','zulaessigesfluegelgewichtlautherstellerkg'=>'hinge_max_weight_kg',
        'eignungfuerangesetztesfluegelgewicht'=>'hinge_suitability','sicherheitsreservekg'=>'hinge_reserve_kg','gesamtbewertung'=>'overall_rating','empfohlenemassnahme'=>'recommended_action',
        'prioritaet'=>'priority','status'=>'status','sachverstaendigenhinweis'=>'expert_note'
    ];
}
function wmLatestArchive(int $projectId): array {
    $requestedId = max(0, (int)($_GET['archive_id'] ?? 0));
    if ($requestedId > 0) {
        $stmt = db()->prepare('SELECT id,file_name,storage_name,created_at FROM report_archive WHERE project_id=:pid AND id=:id LIMIT 1');
        $stmt->execute([':pid'=>$projectId, ':id'=>$requestedId]);
    } else {
        $stmt = db()->prepare('SELECT id,file_name,storage_name,created_at FROM report_archive WHERE project_id=:pid ORDER BY created_at DESC,id DESC LIMIT 1');
        $stmt->execute([':pid'=>$projectId]);
    }
    $row = $stmt->fetch();
    if (!$row) apiError(404, 'Kein archiviertes Gutachten vorhanden.');
    $row['path'] = wmResolveArchiveFile($row);
    return $row;
}
function wmParseReport(string $file): array {
    $html = file_get_contents($file);
    if ($html === false || trim($html) === '') apiError(422, 'Gutachten ist leer oder nicht lesbar.');
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    if (!$dom->loadHTML($html, LIBXML_NOWARNING|LIBXML_NOERROR|LIBXML_NONET)) apiError(422, 'Gutachten konnte nicht als Word-/HTML-Dokument gelesen werden.');
    $xp = new DOMXPath($dom);
    $sections = $xp->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' window-report ')]");
    $map = wmLabelMap();
    $out = [];
    foreach ($sections ?: [] as $section) {
        $data = [];
        $h2 = $xp->query('.//h2[1]', $section)?->item(0);
        $heading = trim((string)($h2?->textContent ?? ''));
        if (preg_match('/Fenster\s+([^\s]+)/iu', $heading, $m)) $data['window_number'] = trim($m[1]);
        foreach ($xp->query('.//tr', $section) ?: [] as $tr) {
            $th = $xp->query('./th[1]', $tr)?->item(0); $td = $xp->query('./td[1]', $tr)?->item(0);
            if (!$th || !$td) continue;
            $label = wmNorm($th->textContent); $value = trim(preg_replace('/\s+/u',' ',(string)$td->textContent) ?? '');
            if ($value === '' || !isset($map[$label])) continue;
            $field = $map[$label];
            if (in_array($field, ['inspection_number','wing_count','construction_year','glass_panes'], true)) $data[$field] = (int)preg_replace('/[^0-9-]/','',$value);
            elseif (str_ends_with($field, '_kg') || str_ends_with($field, '_mm')) $data[$field] = (float)str_replace(',', '.', preg_replace('/[^0-9,.-]/','',$value));
            elseif ($field === 'safety_glass') $data[$field] = preg_match('/^(ja|true|1)$/iu',$value) === 1;
            else $data[$field] = $value;
        }
        if (!empty($data['window_number'])) $out[] = $data;
    }
    if (!$out) apiError(422, 'Im archivierten Gutachten wurden keine Fensterpositionen erkannt.');
    return $out;
}
function wmExisting(int $projectId): array {
    $stmt = db()->prepare('SELECT id,window_number,floor_label,room_number,form_data FROM windows WHERE project_id=:pid AND deleted_at IS NULL ORDER BY id');
    $stmt->execute([':pid'=>$projectId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
function wmKey(array $d, bool $wing=true): string {
    $parts=[wmNorm($d['floor_label']??''),wmNorm($d['room_number']??$d['room_label']??''),wmNorm($d['window_number']??'')];
    if ($wing) $parts[] = wmNorm($d['inspected_wing']??'');
    return implode('|',$parts);
}
function wmCompare(array $parsed, array $existing): array {
    $exact=[];$base=[];
    foreach ($existing as $row) {
        $fd=json_decode((string)($row['form_data']??'{}'),true)?:[];
        $d=array_merge($row,$fd);
        $exact[wmKey($d,true)][]=$row; $base[wmKey($d,false)][]=$row;
    }
    $items=[];$counts=['recognized'=>count($parsed),'exact'=>0,'fallback'=>0,'new'=>0,'conflicts'=>0];
    foreach ($parsed as $d) {
        $ek=wmKey($d,true); $bk=wmKey($d,false); $match=null;$kind='new';
        if ($ek!== '|||' && count($exact[$ek]??[])===1) { $match=$exact[$ek][0];$kind='exact';$counts['exact']++; }
        elseif (count($base[$bk]??[])===1) { $match=$base[$bk][0];$kind='fallback';$counts['fallback']++; }
        elseif (count($exact[$ek]??[])>1 || count($base[$bk]??[])>1) { $kind='conflict';$counts['conflicts']++; }
        else $counts['new']++;
        $items[]=['status'=>$kind,'window_id'=>$match['id']??null,'window_number'=>$d['window_number']??'','floor'=>$d['floor_label']??'','room'=>$d['room_number']??$d['room_label']??'','wing'=>$d['inspected_wing']??'','fields'=>count($d),'data'=>$d];
    }
    return ['counts'=>$counts,'items'=>$items];
}

$archive = wmLatestArchive($projectId);
$parsed = wmParseReport((string)$archive['path']);
$existing = wmExisting($projectId);
$comparison = wmCompare($parsed,$existing);

if ($_SERVER['REQUEST_METHOD']==='GET' || $action==='preview') {
    apiJson(['source'=>['id'=>(int)$archive['id'],'file_name'=>$archive['file_name'],'created_at'=>$archive['created_at']], 'counts'=>$comparison['counts'], 'items'=>array_slice($comparison['items'],0,600)]);
}
if ($_SERVER['REQUEST_METHOD']!=='POST' || $action!=='apply') apiError(405, 'Methode nicht erlaubt.');

$pdo=db();$pdo->beginTransaction();$updated=0;$created=0;$conflicts=0;
try {
    foreach ($comparison['items'] as $item) {
        $d=$item['data'];
        if ($item['status']==='conflict') { $conflicts++; continue; }
        if ($item['window_id']) {
            $stmt=$pdo->prepare('SELECT form_data FROM windows WHERE id=:id FOR UPDATE');$stmt->execute([':id'=>$item['window_id']]);
            $old=json_decode((string)($stmt->fetchColumn()?:'{}'),true)?:[];
            foreach ($d as $k=>$v) if ($v!=='' && $v!==null) $old[$k]=$v;
            $upd=$pdo->prepare('UPDATE windows SET window_number=:wn,floor_label=:fl,room_number=:rn,room_label=:rl,building_label=:bl,section_label=:sl,status=:st,overall_rating=:orating,priority=:pr,accessibility_status=:acc,assigned_name=:an,form_data=:fd,last_edited_at=:now,updated_at=:now2 WHERE id=:id');
            $upd->execute([':wn'=>$old['window_number']??'',':fl'=>$old['floor_label']??null,':rn'=>$old['room_number']??null,':rl'=>$old['room_label']??null,':bl'=>$old['building_label']??null,':sl'=>$old['section_label']??null,':st'=>$old['status']??'nicht begonnen',':orating'=>$old['overall_rating']??null,':pr'=>$old['priority']??null,':acc'=>$old['accessibility_status']??null,':an'=>$old['inspector_name']??null,':fd'=>json_encode($old,JSON_UNESCAPED_UNICODE),':now'=>nowUtc(),':now2'=>nowUtc(),':id'=>$item['window_id']]);
            $updated++;
        } else {
            $rid='WORD-'.strtoupper(substr(bin2hex(random_bytes(5)),0,10));
            $ins=$pdo->prepare('INSERT INTO windows (project_id,record_id,window_number,room_number,room_label,building_label,section_label,floor_label,status,assigned_to,assigned_name,form_data,calculated_data,progress_percent,created_at,updated_at) VALUES (:pid,:rid,:wn,:rn,:rl,:bl,:sl,:fl,:st,:uid,:an,:fd,:cd,0,:now,:now2)');
            $ins->execute([':pid'=>$projectId,':rid'=>$rid,':wn'=>$d['window_number']??'',':rn'=>$d['room_number']??null,':rl'=>$d['room_label']??null,':bl'=>$d['building_label']??null,':sl'=>$d['section_label']??null,':fl'=>$d['floor_label']??null,':st'=>$d['status']??'nicht begonnen',':uid'=>$user['id'],':an'=>$d['inspector_name']??($user['full_name']?:$user['email']),':fd'=>json_encode($d,JSON_UNESCAPED_UNICODE),':cd'=>'{}',':now'=>nowUtc(),':now2'=>nowUtc()]);
            $created++;
        }
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[word-master-import] '.$e->getMessage());
    apiError(503, 'Word-Master konnte nicht übernommen werden.');
}
apiJson(['ok'=>true,'source'=>$archive['file_name'],'updated'=>$updated,'created'=>$created,'conflicts'=>$conflicts,'recognized'=>count($parsed)]);
