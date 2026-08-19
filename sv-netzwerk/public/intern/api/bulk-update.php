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
elseif ($value !== null) $value = trim((string)$value);

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
        $oldEmpty = $old === null || $old === '' || $old === false;
        if ($mode === 'empty_only' && !$oldEmpty) { $skipped++; continue; }
        if ($old === $value) { $skipped++; continue; }
        $data[$field] = $value;
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
