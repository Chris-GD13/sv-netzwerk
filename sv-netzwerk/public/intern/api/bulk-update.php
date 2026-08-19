<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
commonHeaders();
$user = requireAuth();
if (!in_array($user['role'], ['administrator','projektleiter','pruefer','sachverstaendiger'], true)) {
    apiError(403, 'Keine Berechtigung für Massenänderungen.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') apiError(405, 'Methode nicht erlaubt.');

$body = requestBody();
$projectId = max(1, (int)($body['project_id'] ?? DEFAULT_PROJECT_ID));
$field = trim((string)($body['field'] ?? ''));
$mode = (string)($body['mode'] ?? 'empty_only');
$type = (string)($body['field_type'] ?? 'text');
$value = $body['value'] ?? null;
if (!preg_match('/^[a-z][a-z0-9_]{1,80}$/', $field)) apiError(400, 'Ungültiges Feld.');
if (!in_array($mode, ['empty_only','overwrite'], true)) apiError(400, 'Ungültiger Änderungsmodus.');

if ($type === 'checkbox') $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
elseif ($type === 'number') $value = ($value === '' || $value === null) ? null : (float)$value;
elseif ($type === 'json') {
    if (!is_array($value)) apiError(400, 'Ungültiger JSON-Wert.');
} elseif ($value !== null) $value = trim((string)$value);

if ($field === 'inspection_periods') {
    if (!is_array($value) || count($value) < 1) apiError(400, 'Mindestens ein Prüfzeitraum ist erforderlich.');
    $normalized = [];
    foreach ($value as $row) {
        if (!is_array($row)) continue;
        $date = trim((string)($row['date'] ?? ''));
        $start = trim((string)($row['start'] ?? ''));
        $end = trim((string)($row['end'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
        if ($start !== '' && !preg_match('/^\d{2}:\d{2}$/', $start)) continue;
        if ($end !== '' && !preg_match('/^\d{2}:\d{2}$/', $end)) continue;
        $normalized[] = ['date'=>$date,'start'=>$start,'end'=>$end];
    }
    if (!$normalized) apiError(400, 'Keine gültigen Prüfzeiträume übergeben.');
    usort($normalized, fn($a,$b)=>strcmp($a['date'].' '.$a['start'], $b['date'].' '.$b['start']));
    $value = $normalized;
}

try {
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id, form_data FROM windows WHERE project_id=:pid AND deleted_at IS NULL ORDER BY id');
    $stmt->execute([':pid'=>$projectId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $pdo->beginTransaction();
    $changed = 0; $skipped = 0;
    foreach ($rows as $row) {
        $data = json_decode((string)($row['form_data'] ?? '{}'), true) ?: [];
        $old = $data[$field] ?? null;
        $oldEmpty = $old === null || $old === '' || $old === false || (is_array($old) && count($old) === 0);
        if ($mode === 'empty_only' && !$oldEmpty) { $skipped++; continue; }
        if ($old === $value) { $skipped++; continue; }

        $data[$field] = $value;
        if ($field === 'inspection_periods' && is_array($value) && count($value) > 0) {
            $first = $value[0];
            $last = $value[count($value)-1];
            $data['inspection_date'] = $first['date'];
            $data['time_started'] = $first['start'];
            $data['time_finished'] = $last['end'];
            $labels = [];
            foreach ($value as $period) {
                $dateLabel = date('d.m.Y', strtotime($period['date']));
                $timeLabel = trim(($period['start'] ?: '') . (($period['start'] || $period['end']) ? '–' : '') . ($period['end'] ?: ''));
                $labels[] = trim($dateLabel . ($timeLabel !== '' ? ' ' . $timeLabel . ' Uhr' : ''));
            }
            $data['inspection_periods_text'] = implode('; ', $labels);
        }

        $sets = ['form_data=:fd','updated_at=:now','last_edited_at=:now2'];
        $params = [':fd'=>json_encode($data, JSON_UNESCAPED_UNICODE), ':now'=>nowUtc(), ':now2'=>nowUtc(), ':id'=>(int)$row['id']];
        $mirror = [
            'window_number'=>'window_number','room_number'=>'room_number','room_label'=>'room_label',
            'building_label'=>'building_label','section_label'=>'section_label','floor_label'=>'floor_label',
            'status'=>'status','overall_rating'=>'overall_rating','priority'=>'priority','accessibility_status'=>'accessibility_status'
        ];
        if (isset($mirror[$field])) { $sets[] = $mirror[$field] . '=:mirror'; $params[':mirror'] = ($value === '' ? null : $value); }
        if ($field === 'inspection_number') { $sets[]='inspection_number=:inum'; $params[':inum']=$value === null ? null : (int)$value; }
        if ($field === 'inspector_name') { $sets[]='assigned_name=:an'; $params[':an']=(string)$value; }
        $upd = $pdo->prepare('UPDATE windows SET '.implode(', ', $sets).' WHERE id=:id AND deleted_at IS NULL');
        $upd->execute($params);
        $log = $pdo->prepare('INSERT INTO audit_logs (window_id,actor_id,actor_name,action_type,field_name,old_value,new_value,reason,created_at) VALUES (:wid,:aid,:actor,:act,:field,:old,:new,:reason,:now)');
        $log->execute([
            ':wid'=>(int)$row['id'], ':aid'=>(int)$user['id'], ':actor'=>$user['full_name'] ?: $user['email'],
            ':act'=>'massenänderung', ':field'=>$field, ':old'=>is_scalar($old)?(string)$old:json_encode($old,JSON_UNESCAPED_UNICODE),
            ':new'=>is_scalar($value)?(string)$value:json_encode($value,JSON_UNESCAPED_UNICODE), ':reason'=>'Massenänderung im Prüfportal', ':now'=>nowUtc()
        ]);
        $changed++;
    }
    $pdo->commit();
    apiJson(['ok'=>true,'field'=>$field,'changed'=>$changed,'skipped'=>$skipped,'total'=>count($rows)]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    error_log('[bulk-update] '.$e->getMessage());
    apiError(503, 'Massenänderung konnte nicht ausgeführt werden.');
}
