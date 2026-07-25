<?php
/**
 * Flügel-API – Fensterbeschlagsprüfung BMVg Bonn
 *
 * GET    /api/sashes.php?window_id={id}  – Alle Flügel eines Fensters
 * GET    /api/sashes.php?id={id}         – Einzelner Flügel (inkl. form_data)
 * POST   /api/sashes.php                 – Neuen Flügel anlegen
 * PUT    /api/sashes.php?id={id}         – Flügel aktualisieren (Inspektion)
 * DELETE /api/sashes.php?id={id}         – Flügel löschen (nur Admin)
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

commonHeaders();

$user     = requireAuth();
$method   = $_SERVER['REQUEST_METHOD'];
$id       = isset($_GET['id'])        ? (int) $_GET['id']        : null;
$windowId = isset($_GET['window_id']) ? (int) $_GET['window_id'] : null;

match (true) {
    $method === 'GET'    && $id !== null       => handleGetOne($id),
    $method === 'GET'    && $windowId !== null  => handleGetList($windowId),
    $method === 'POST'                          => handleCreate($user),
    $method === 'PUT'    && $id !== null        => handleUpdate($id, $user),
    $method === 'DELETE' && $id !== null        => handleDelete($id, $user),
    default                                     => apiError(404, 'Unbekannter Endpunkt.'),
};

// ─── GET ────────────────────────────────────────────────────────────────────

function handleGetList(int $windowId): never
{
    try {
        $stmt = db()->prepare(
            'SELECT ws.*, p.count AS photo_count
             FROM window_sashes ws
             LEFT JOIN (SELECT sash_id, COUNT(*) AS count FROM photos WHERE sash_id IS NOT NULL AND deleted_at IS NULL GROUP BY sash_id) p ON p.sash_id = ws.id
             WHERE ws.window_id = :wid AND ws.deleted_at IS NULL
             ORDER BY ws.sash_number ASC'
        );
        $stmt->execute([':wid' => $windowId]);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        apiError(503, 'Flügelliste konnte nicht geladen werden: ' . $e->getMessage());
    }
    apiJson(array_map('mapSashSummary', $rows));
}

function handleGetOne(int $id): never
{
    try {
        $stmt = db()->prepare(
            'SELECT ws.*,
                    w.window_number, w.record_id, w.room_id,
                    ro.name AS room_name, ro.room_number AS room_no,
                    fl.name AS floor_name, fl.id AS floor_id,
                    b.name AS building_name, b.id AS building_id
             FROM window_sashes ws
             JOIN windows w ON w.id = ws.window_id
             LEFT JOIN rooms ro ON ro.id = w.room_id
             LEFT JOIN floors fl ON fl.id = ro.floor_id
             LEFT JOIN buildings b ON b.id = fl.building_id
             WHERE ws.id = :id AND ws.deleted_at IS NULL'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        apiError(503, 'Flügel konnte nicht geladen werden: ' . $e->getMessage());
    }
    if (!$row) apiError(404, 'Flügel nicht gefunden.');
    apiJson(mapSashRecord($row));
}

// ─── POST ───────────────────────────────────────────────────────────────────

function handleCreate(array $user): never
{
    $body     = requestBody();
    $windowId = (int) ($body['window_id'] ?? 0);
    if ($windowId === 0) apiError(400, 'window_id fehlt.');

    // Nächste Flügelnummer ermitteln
    try {
        $stmt = db()->prepare('SELECT COALESCE(MAX(sash_number),0) AS mx FROM window_sashes WHERE window_id=:wid AND deleted_at IS NULL');
        $stmt->execute([':wid' => $windowId]);
        $maxNum = (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        apiError(503, 'Flügel konnte nicht angelegt werden: ' . $e->getMessage());
    }

    $sashNumber = $maxNum + 1;
    $label      = $body['sash_label'] ?? "Flügel $sashNumber";
    $formData   = $body['form_data'] ?? ['status' => 'nicht begonnen'];

    try {
        $stmt = db()->prepare(
            'INSERT INTO window_sashes
             (window_id, sash_number, sash_label, opening_type, position, status,
              form_data, progress_percent, inspector_id, inspector_name, created_at, updated_at)
             VALUES
             (:wid, :snum, :label, :otype, :pos, :status,
              :fd, 0, :iid, :iname, :now, :now2)'
        );
        $stmt->execute([
            ':wid'    => $windowId,
            ':snum'   => $sashNumber,
            ':label'  => (string) $label,
            ':otype'  => nonEmpty($body['opening_type'] ?? ''),
            ':pos'    => nonEmpty($body['position'] ?? ''),
            ':status' => (string) ($formData['status'] ?? 'nicht begonnen'),
            ':fd'     => json_encode($formData, JSON_UNESCAPED_UNICODE),
            ':iid'    => $user['id'],
            ':iname'  => $user['full_name'] ?: $user['email'],
            ':now'    => nowUtc(),
            ':now2'   => nowUtc(),
        ]);
        $newId = (int) db()->lastInsertId();
    } catch (Throwable $e) {
        apiError(503, 'Flügel konnte nicht angelegt werden: ' . $e->getMessage());
    }

    updateWindowStatus($windowId);
    apiJson(['id' => $newId, 'sash_number' => $sashNumber], 201);
}

// ─── PUT ────────────────────────────────────────────────────────────────────

function handleUpdate(int $id, array $user): never
{
    $body     = requestBody();
    $formData = $body['form_data'] ?? null;
    if ($formData === null) apiError(400, 'form_data fehlt.');

    $status   = (string) ($formData['status'] ?? 'nicht begonnen');
    $progress = computeSashProgress($formData);
    $hasDefect = computeHasDefect($formData);
    $urgent   = !empty($formData['urgent_action_required']);
    $rating   = nonEmpty($formData['overall_rating'] ?? '');
    $completedAt = in_array($status, ['abgeschlossen', 'freigegeben'], true) ? nowUtc() : null;

    try {
        $stmt = db()->prepare(
            'UPDATE window_sashes SET
               sash_label       = :label,
               opening_type     = :otype,
               position         = :pos,
               status           = :status,
               form_data        = :fd,
               progress_percent = :pp,
               has_defect       = :hd,
               urgent_action    = :ua,
               overall_rating   = :orating,
               inspector_id     = :iid,
               inspector_name   = :iname,
               inspected_at     = :iat,
               completed_at     = :cat,
               updated_at       = :now
             WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute([
            ':label'  => (string) ($formData['sash_label'] ?? "Flügel $id"),
            ':otype'  => nonEmpty($formData['opening_type'] ?? ''),
            ':pos'    => nonEmpty($formData['position'] ?? ''),
            ':status' => $status,
            ':fd'     => json_encode($formData, JSON_UNESCAPED_UNICODE),
            ':pp'     => $progress,
            ':hd'     => $hasDefect ? 1 : 0,
            ':ua'     => $urgent ? 1 : 0,
            ':orating'=> $rating,
            ':iid'    => $user['id'],
            ':iname'  => $user['full_name'] ?: $user['email'],
            ':iat'    => nowUtc(),
            ':cat'    => $completedAt,
            ':now'    => nowUtc(),
            ':id'     => $id,
        ]);

        if ($stmt->rowCount() === 0) {
            apiError(404, 'Flügel nicht gefunden.');
        }
    } catch (Throwable $e) {
        apiError(503, 'Flügel konnte nicht gespeichert werden: ' . $e->getMessage());
    }

    // Parent-Fenster-Status aktualisieren
    try {
        $stmt2 = db()->prepare('SELECT window_id FROM window_sashes WHERE id=:id');
        $stmt2->execute([':id'=>$id]);
        $windowId = (int) ($stmt2->fetchColumn() ?: 0);
        if ($windowId > 0) updateWindowStatus($windowId);
    } catch (Throwable) { /* nicht kritisch */ }

    apiJson(['id' => $id, 'updated' => true]);
}

// ─── DELETE ─────────────────────────────────────────────────────────────────

function handleDelete(int $id, array $user): never
{
    if ($user['role'] !== 'administrator') {
        apiError(403, 'Nur Administratoren können Flügel löschen.');
    }
    try {
        $stmt = db()->prepare('UPDATE window_sashes SET deleted_at=:now WHERE id=:id');
        $stmt->execute([':now' => nowUtc(), ':id' => $id]);
    } catch (Throwable $e) {
        apiError(503, 'Flügel konnte nicht gelöscht werden: ' . $e->getMessage());
    }
    apiJson(['ok' => true]);
}

// ─── Hilfsfunktionen ─────────────────────────────────────────────────────────

function mapSashSummary(array $row): array
{
    return [
        'id'              => (int) $row['id'],
        'window_id'       => (int) $row['window_id'],
        'sash_number'     => (int) $row['sash_number'],
        'sash_label'      => $row['sash_label'] ?? '',
        'opening_type'    => $row['opening_type'] ?? null,
        'position'        => $row['position'] ?? null,
        'status'          => $row['status'],
        'progress_percent'=> (int) $row['progress_percent'],
        'has_defect'      => (bool) $row['has_defect'],
        'urgent_action'   => (bool) $row['urgent_action'],
        'overall_rating'  => $row['overall_rating'] ?? null,
        'inspector_name'  => $row['inspector_name'] ?? null,
        'inspected_at'    => $row['inspected_at'] ?? null,
        'completed_at'    => $row['completed_at'] ?? null,
        'updated_at'      => $row['updated_at'],
        'photo_count'     => (int) ($row['photo_count'] ?? 0),
    ];
}

function mapSashRecord(array $row): array
{
    $summary = mapSashSummary($row);
    $summary['form_data']     = isset($row['form_data']) ? json_decode($row['form_data'], true) ?? [] : [];
    $summary['window_number'] = $row['window_number'] ?? '';
    $summary['record_id']     = $row['record_id'] ?? '';
    $summary['room_id']       = isset($row['room_id']) ? (int)$row['room_id'] : null;
    $summary['room_name']     = $row['room_name'] ?? null;
    $summary['room_number']   = $row['room_no'] ?? null;
    $summary['floor_name']    = $row['floor_name'] ?? null;
    $summary['floor_id']      = isset($row['floor_id']) ? (int)$row['floor_id'] : null;
    $summary['building_name'] = $row['building_name'] ?? null;
    $summary['building_id']   = isset($row['building_id']) ? (int)$row['building_id'] : null;
    return $summary;
}

function computeSashProgress(array $data): int
{
    $required = [
        'sash_label', 'opening_type', 'inspection_date', 'inspector_name',
        'glass_structure', 'glazing_width_mm', 'glazing_height_mm',
        'hinge_condition', 'fitting_condition', 'overall_rating', 'status',
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
        'fitting_defect',
    ];
    foreach ($defectFields as $field) {
        if (!empty($data[$field])) {
            return true;
        }
    }
    return false;
}

function updateWindowStatus(int $windowId): void
{
    try {
        $stmt = db()->prepare(
            'SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status IN (\'abgeschlossen\',\'freigegeben\') THEN 1 ELSE 0 END) AS done,
                    SUM(CASE WHEN status = \'in Bearbeitung\' THEN 1 ELSE 0 END) AS wip
             FROM window_sashes
             WHERE window_id = :wid AND deleted_at IS NULL'
        );
        $stmt->execute([':wid' => $windowId]);
        $stat = $stmt->fetch();
        if (!$stat) return;

        $total = (int) $stat['total'];
        $done  = (int) $stat['done'];
        $wip   = (int) $stat['wip'];

        if ($total === 0) {
            $newStatus = 'nicht begonnen';
        } elseif ($done === $total) {
            $newStatus = 'Pruefung abgeschlossen';
        } elseif ($done > 0 || $wip > 0) {
            $newStatus = 'in Bearbeitung';
        } else {
            $newStatus = 'nicht begonnen';
        }

        db()->prepare('UPDATE windows SET status=:s, updated_at=:now WHERE id=:id')
            ->execute([':s' => $newStatus, ':now' => nowUtc(), ':id' => $windowId]);
    } catch (Throwable) { /* nicht kritisch */ }
}

function nonEmpty(mixed $value): ?string
{
    $str = trim((string) $value);
    return $str !== '' ? $str : null;
}
