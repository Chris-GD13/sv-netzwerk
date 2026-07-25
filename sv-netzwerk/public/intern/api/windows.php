<?php
/**
 * Fenster-Datensätze API – Fensterbeschlagsprüfung BMVg Bonn
 *
 * GET    /api/windows.php            – Alle Fenster (Übersicht)
 * GET    /api/windows.php?id={id}    – Einzeldatensatz
 * POST   /api/windows.php            – Neuen Datensatz anlegen
 * PUT    /api/windows.php?id={id}    – Datensatz aktualisieren
 * GET    /api/windows.php?action=locks         – Aktive Sperren
 * GET    /api/windows.php?action=audit&id={id} – Audit-Log
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

commonHeaders();

$user   = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$id     = isset($_GET['id']) ? (int) $_GET['id'] : null;

match (true) {
    $method === 'GET'  && $action === 'locks'            => handleGetLocks(),
    $method === 'GET'  && $action === 'audit' && $id     => handleGetAudit($id),
    $method === 'GET'  && $id !== null                   => handleGetOne($id),
    $method === 'GET'                                    => handleGetList(),
    $method === 'POST'                                   => handleCreate($user),
    $method === 'PUT'  && $id !== null                   => handleUpdate($id, $user),
    default                                              => apiError(404, 'Unbekannter Endpunkt.'),
};

function handleGetList(): never
{
    try {
        $rows = db()->query(
            'SELECT w.id, w.record_id, w.inspection_number, w.window_number,
                    w.room_number, w.room_label, w.building_label, w.section_label, w.floor_label,
                    w.status, w.overall_rating, w.priority, w.accessibility_status,
                    w.assigned_to, w.assigned_name,
                    w.special_inspection_required, w.urgent_action_required,
                    w.has_defect, w.danger_immediate, w.last_edited_at, w.updated_at, w.progress_percent
             FROM windows w
             WHERE w.deleted_at IS NULL
             ORDER BY ISNULL(w.inspection_number), w.inspection_number ASC'
        )->fetchAll();
    } catch (Throwable $e) {
        apiError(503, 'Fensterliste konnte nicht geladen werden.');
    }

    apiJson(array_map('mapWindowSummary', $rows));
}

function handleGetOne(int $id): never
{
    try {
        $stmt = db()->prepare(
            'SELECT * FROM windows WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
    } catch (Throwable) {
        apiError(503, 'Datenbankfehler.');
    }

    if (!$row) {
        apiError(404, 'Datensatz nicht gefunden.');
    }

    apiJson(mapWindowRecord($row));
}

function handleCreate(array $user): never
{
    $body     = requestBody();
    $recordId = 'BMVG-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    $formData = $body['form_data'] ?? ['status' => 'nicht begonnen'];

    try {
        $stmt = db()->prepare(
            'INSERT INTO windows
             (project_id, record_id, window_number, room_number, room_label,
              building_label, section_label, floor_label, status, assigned_to, assigned_name,
              form_data, calculated_data, progress_percent, created_at, updated_at)
             VALUES
             (:pid, :rid, :wnum, :rnum, :rlabel,
              :blabel, :slabel, :flabel, :status, :at, :an,
              :fd, :cd, 0, :now, :now2)'
        );
        $stmt->execute([
            ':pid'    => DEFAULT_PROJECT_ID,
            ':rid'    => $recordId,
            ':wnum'   => (string) ($formData['window_number'] ?? ''),
            ':rnum'   => nonEmpty($formData['room_number'] ?? ''),
            ':rlabel' => nonEmpty($formData['room_label'] ?? ''),
            ':blabel' => nonEmpty($formData['building_label'] ?? ''),
            ':slabel' => nonEmpty($formData['section_label'] ?? ''),
            ':flabel' => nonEmpty($formData['floor_label'] ?? ''),
            ':status' => (string) ($formData['status'] ?? 'nicht begonnen'),
            ':at'     => $user['id'],
            ':an'     => $user['full_name'] ?: $user['email'],
            ':fd'     => json_encode($formData, JSON_UNESCAPED_UNICODE),
            ':cd'     => '{}',
            ':now'    => nowUtc(),
            ':now2'   => nowUtc(),
        ]);
        $newId = (int) db()->lastInsertId();
    } catch (Throwable $e) {
        apiError(503, 'Datensatz konnte nicht angelegt werden.');
    }

    writeAuditLog($newId, $user['id'], $user['full_name'] ?: $user['email'], 'erstellt', null, null, null, null);

    apiJson(['id' => $newId, 'record_id' => $recordId], 201);
}

function handleUpdate(int $id, array $user): never
{
    $body = requestBody();

    $formData       = $body['form_data']       ?? null;
    $calculatedData = $body['calculated_data'] ?? null;

    if ($formData === null) {
        apiError(400, 'form_data fehlt.');
    }

    $progressPercent = computeProgress($formData);
    $hasDefect       = computeHasDefect($formData);

    try {
        $stmt = db()->prepare(
            'UPDATE windows SET
               window_number              = :wnum,
               room_number                = :rnum,
               room_label                 = :rlabel,
               building_label             = :blabel,
               section_label              = :slabel,
               floor_label                = :flabel,
               status                     = :status,
               overall_rating             = :orating,
               priority                   = :priority,
               accessibility_status       = :acc,
               assigned_to                = :at,
               assigned_name              = :an,
               special_inspection_required= :sir,
               urgent_action_required     = :uar,
               has_defect                 = :hd,
               danger_immediate           = :di,
               inspection_number          = :inum,
               progress_percent           = :pp,
               form_data                  = :fd,
               calculated_data            = :cd,
               last_edited_at             = :le,
               updated_at                 = :now,
               completed_at               = :ca,
               release_reason             = :rr
             WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute([
            ':wnum'    => (string) ($formData['window_number'] ?? ''),
            ':rnum'    => nonEmpty($formData['room_number'] ?? ''),
            ':rlabel'  => nonEmpty($formData['room_label'] ?? ''),
            ':blabel'  => nonEmpty($formData['building_label'] ?? ''),
            ':slabel'  => nonEmpty($formData['section_label'] ?? ''),
            ':flabel'  => nonEmpty($formData['floor_label'] ?? ''),
            ':status'  => (string) ($formData['status'] ?? 'nicht begonnen'),
            ':orating' => nonEmpty($formData['overall_rating'] ?? ''),
            ':priority'=> nonEmpty($formData['priority'] ?? ''),
            ':acc'     => nonEmpty($formData['accessibility_status'] ?? ''),
            ':at'      => $user['id'],
            ':an'      => $user['full_name'] ?: $user['email'],
            ':sir'     => boolFlag($formData['special_inspection_required'] ?? false || $formData['scissor_special_inspection'] ?? false),
            ':uar'     => boolFlag($formData['urgent_action_required'] ?? false),
            ':hd'      => $hasDefect ? 1 : 0,
            ':di'      => boolFlag($formData['danger_immediate'] ?? false),
            ':inum'    => isset($formData['inspection_number']) ? (int) $formData['inspection_number'] : null,
            ':pp'      => $progressPercent,
            ':fd'      => json_encode($formData, JSON_UNESCAPED_UNICODE),
            ':cd'      => json_encode($calculatedData ?? [], JSON_UNESCAPED_UNICODE),
            ':le'      => nowUtc(),
            ':now'     => nowUtc(),
            ':ca'      => ($formData['status'] ?? '') === 'Pruefung abgeschlossen' ? nowUtc() : null,
            ':rr'      => nonEmpty($formData['release_reason'] ?? ''),
            ':id'      => $id,
        ]);

        if ($stmt->rowCount() === 0) {
            apiError(404, 'Datensatz nicht gefunden.');
        }
    } catch (Throwable $e) {
        apiError(503, 'Datensatz konnte nicht gespeichert werden.');
    }

    writeAuditLog($id, $user['id'], $user['full_name'] ?: $user['email'], 'gespeichert', null, null, null, null);

    apiJson(['id' => $id, 'updated' => true]);
}

function handleGetLocks(): never
{
    try {
        $rows = db()->query(
            'SELECT window_id, owner_id, owner_name, expires_at
             FROM record_locks
             WHERE expires_at > UTC_TIMESTAMP()'
        )->fetchAll();
    } catch (Throwable) {
        apiError(503, 'Sperren konnten nicht geladen werden.');
    }

    apiJson($rows);
}

function handleGetAudit(int $id): never
{
    try {
        $stmt = db()->prepare(
            'SELECT id, action_type, field_name, old_value, new_value, reason, created_at, actor_name
             FROM audit_logs
             WHERE window_id = :id
             ORDER BY created_at DESC
             LIMIT 50'
        );
        $stmt->execute([':id' => $id]);
        $rows = $stmt->fetchAll();
    } catch (Throwable) {
        apiError(503, 'Audit-Log konnte nicht geladen werden.');
    }

    apiJson($rows);
}

function mapWindowSummary(array $row): array
{
    return [
        'id'                          => $row['id'],
        'record_id'                   => $row['record_id'],
        'inspection_number'           => isset($row['inspection_number']) ? (int) $row['inspection_number'] : null,
        'window_number'               => $row['window_number'] ?? '',
        'room_number'                 => $row['room_number'],
        'room_label'                  => $row['room_label'],
        'building_label'              => $row['building_label'],
        'section_label'               => $row['section_label'],
        'floor_label'                 => $row['floor_label'],
        'status'                      => $row['status'],
        'overall_rating'              => $row['overall_rating'],
        'priority'                    => $row['priority'],
        'accessibility_status'        => $row['accessibility_status'],
        'assigned_to'                 => $row['assigned_to'],
        'assigned_name'               => $row['assigned_name'],
        'special_inspection_required' => (bool) $row['special_inspection_required'],
        'urgent_action_required'      => (bool) $row['urgent_action_required'],
        'has_defect'                  => (bool) $row['has_defect'],
        'danger_immediate'            => (bool) $row['danger_immediate'],
        'last_edited_at'              => $row['last_edited_at'],
        'updated_at'                  => $row['updated_at'],
        'progress_percent'            => (int) ($row['progress_percent'] ?? 0),
    ];
}

function mapWindowRecord(array $row): array
{
    $summary                  = mapWindowSummary($row);
    $summary['project_id']    = $row['project_id'] ?? DEFAULT_PROJECT_ID;
    $summary['form_data']     = isset($row['form_data'])     ? json_decode($row['form_data'], true)     ?? [] : [];
    $summary['calculated_data'] = isset($row['calculated_data']) ? json_decode($row['calculated_data'], true) ?? [] : [];
    $summary['completed_at']  = $row['completed_at'] ?? null;
    $summary['released_at']   = $row['released_at']  ?? null;
    $summary['release_reason'] = $row['release_reason'] ?? null;
    $summary['version']       = (int) ($row['version'] ?? 1);
    return $summary;
}

function computeProgress(array $data): int
{
    $required = [
        'inspection_number', 'window_number', 'building_label', 'section_label',
        'floor_label', 'room_number', 'wing_count', 'inspected_wing',
        'inspector_name', 'inspection_date', 'accessibility_status',
        'glass_structure', 'glazing_width_mm', 'glazing_height_mm',
        'applied_test_weight_kg', 'weight_method', 'overall_rating',
        'recommended_action', 'priority', 'status',
    ];
    $filled = 0;
    foreach ($required as $field) {
        $val = $data[$field] ?? null;
        if ($val !== null && $val !== '' && $val !== false) {
            $filled++;
        }
    }
    return (int) round($filled / count($required) * 100);
}

function computeHasDefect(array $data): bool
{
    $defectFields = [
        'hinge_fastening_loose', 'hinge_screws_missing', 'hinge_deformation',
        'hinge_corrosion', 'hinge_damage',
        'scissor_fastening_loose', 'scissor_deformation', 'scissor_corrosion',
        'scissor_damage', 'wing_scrapes', 'wing_hangs', 'unsafe_until_repair',
    ];
    foreach ($defectFields as $field) {
        if (!empty($data[$field])) {
            return true;
        }
    }
    return false;
}

function boolFlag(mixed $value): int
{
    return (bool) $value ? 1 : 0;
}

function nonEmpty(mixed $value): ?string
{
    $str = trim((string) $value);
    return $str !== '' ? $str : null;
}

function writeAuditLog(
    int $windowId,
    int $actorId,
    string $actorName,
    string $actionType,
    ?string $fieldName,
    ?string $oldValue,
    ?string $newValue,
    ?string $reason
): void {
    try {
        db()->prepare(
            'INSERT INTO audit_logs
             (window_id, actor_id, actor_name, action_type, field_name, old_value, new_value, reason, created_at)
             VALUES (:wid, :aid, :an, :at, :fn, :ov, :nv, :r, :now)'
        )->execute([
            ':wid' => $windowId,
            ':aid' => $actorId,
            ':an'  => $actorName,
            ':at'  => $actionType,
            ':fn'  => $fieldName,
            ':ov'  => $oldValue,
            ':nv'  => $newValue,
            ':r'   => $reason,
            ':now' => nowUtc(),
        ]);
    } catch (Throwable) {
        // Audit-Log-Fehler sind nicht kritisch
    }
}
