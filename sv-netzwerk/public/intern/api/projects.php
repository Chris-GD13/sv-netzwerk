<?php
/**
 * Projekte-API – SV-Netzwerk Prüfportal
 * 
 * GET    /intern/api/projects.php            → Alle aktiven Projekte
 * POST   /intern/api/projects.php            → Neues Projekt anlegen
 * PUT    /intern/api/projects.php?id={id}    → Projekt bearbeiten
 * DELETE /intern/api/projects.php?id={id}    → Projekt archivieren/löschen
 * POST   /intern/api/projects.php?action=duplicate&id={id} → Projekt duplizieren
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

commonHeaders();
$user = requireAuth();

$method = $_SERVER['REQUEST_METHOD'];
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$action = $_GET['action'] ?? '';

function requireProjectRole(array $user, array $roles): void {
    if (!in_array($user['role'] ?? '', $roles, true)) {
        apiError(403, 'Keine Berechtigung für diese Aktion.');
    }
}

if ($method === 'GET') {
    $stmt = db()->query("SELECT id, project_code, title, object_name, address, planned_window_count, is_active, created_by_user_id,
        (SELECT COUNT(*) FROM windows WHERE windows.project_id = projects.id AND windows.deleted_at IS NULL) as window_count,
        (SELECT COUNT(*) FROM buildings WHERE buildings.project_id = projects.id) as building_count
        FROM projects WHERE is_active = 1 ORDER BY id ASC");
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($projects as &$project) {
        $project['is_own'] = !empty($project['created_by_user_id']) && (int)$project['created_by_user_id'] === (int)$user['id'];
        $project['can_delete'] = canDeleteProject($user, (int)$project['id']);
        unset($project['created_by_user_id']);
    }
    apiJson(['projects' => $projects]);
} elseif ($method === 'POST' && $action === 'duplicate' && $id) {
    requireProjectRole($user, ['administrator', 'projektleiter', 'sachverstaendiger', 'pruefer']);
    
    $src = db()->prepare('SELECT * FROM projects WHERE id = :id');
    $src->execute([':id' => $id]);
    $project = $src->fetch();
    if (!$project) apiError(404, 'Projekt nicht gefunden.');
    
    $newCode = $project['project_code'] . '-kopie-' . date('Ymd');
    $newTitle = $project['title'] . ' (Kopie)';
    
    try {
        $pdo = db();
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare('INSERT INTO projects (project_code, title, object_name, address, planned_window_count, is_active, created_by_user_id) VALUES (:code, :title, :obj, :addr, :wc, 1, :uid)');
        $stmt->execute([':code' => $newCode, ':title' => $newTitle, ':obj' => $project['object_name'], ':addr' => $project['address'], ':wc' => $project['planned_window_count'], ':uid' => (int)$user['id']]);
        $newId = (int)$pdo->lastInsertId();
        
        // Copy buildings
        $buildings = $pdo->prepare('SELECT * FROM buildings WHERE project_id = :pid');
        $buildings->execute([':pid' => $id]);
        foreach ($buildings->fetchAll() as $b) {
            $stmtB = $pdo->prepare('INSERT INTO buildings (project_id, name, code, notes, sort_order) VALUES (:pid, :n, :c, :nt, :s)');
            $stmtB->execute([':pid' => $newId, ':n' => $b['name'], ':c' => $b['code'], ':nt' => $b['notes'], ':s' => $b['sort_order']]);
            $newBuildingId = (int)$pdo->lastInsertId();
            
            // Copy floors
            $floors = $pdo->prepare('SELECT * FROM floors WHERE building_id = :bid');
            $floors->execute([':bid' => $b['id']]);
            foreach ($floors->fetchAll() as $f) {
                $stmtF = $pdo->prepare('INSERT INTO floors (building_id, name, level, notes, sort_order) VALUES (:bid, :n, :l, :nt, :s)');
                $stmtF->execute([':bid' => $newBuildingId, ':n' => $f['name'], ':l' => $f['level'], ':nt' => $f['notes'], ':s' => $f['sort_order']]);
                $newFloorId = (int)$pdo->lastInsertId();
                
                // Copy rooms
                $rooms = $pdo->prepare('SELECT * FROM rooms WHERE floor_id = :fid');
                $rooms->execute([':fid' => $f['id']]);
                foreach ($rooms->fetchAll() as $r) {
                    $stmtR = $pdo->prepare('INSERT INTO rooms (floor_id, name, room_number, notes, sort_order) VALUES (:fid, :n, :rn, :nt, :s)');
                    $stmtR->execute([':fid' => $newFloorId, ':n' => $r['name'], ':rn' => $r['room_number'], ':nt' => $r['notes'], ':s' => $r['sort_order']]);
                }
            }
        }
        
        $pdo->commit();
        apiJson(['id' => $newId, 'project_code' => $newCode], 201);
    } catch (Throwable $e) {
        db()->rollBack();
        apiError(503, 'Projekt konnte nicht dupliziert werden: ' . $e->getMessage());
    }
} elseif ($method === 'POST') {
    requireProjectRole($user, ['administrator', 'projektleiter', 'sachverstaendiger', 'pruefer']);
    $body = requestBody();
    
    $title = trim((string)($body['title'] ?? ''));
    $objectName = trim((string)($body['object_name'] ?? ''));
    $address = trim((string)($body['address'] ?? ''));
    $windowCount = (int)($body['planned_window_count'] ?? 0);
    
    if ($title === '') {
        apiError(400, 'Projektname ist erforderlich.');
    }
    
    $code = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $title), '-'));
    $code = substr($code, 0, 50);
    
    $exists = db()->prepare('SELECT id FROM projects WHERE project_code = :c');
    $exists->execute([':c' => $code]);
    if ($exists->fetch()) {
        $code .= '-' . date('Ymd');
    }
    
    try {
        $stmt = db()->prepare('INSERT INTO projects (project_code, title, object_name, address, planned_window_count, is_active, created_by_user_id) VALUES (:code, :title, :obj, :addr, :wc, 1, :uid)');
        $stmt->execute([':code' => $code, ':title' => $title, ':obj' => $objectName, ':addr' => $address, ':wc' => $windowCount, ':uid' => (int)$user['id']]);
        $newId = (int)db()->lastInsertId();
        apiJson(['id' => $newId, 'project_code' => $code], 201);
    } catch (Throwable $e) {
        apiError(503, 'Projekt konnte nicht angelegt werden: ' . $e->getMessage());
    }
} elseif ($method === 'PUT' && $id) {
    requireProjectRole($user, ['administrator', 'projektleiter']);
    $body = requestBody();
    
    $title = trim((string)($body['title'] ?? ''));
    if ($title === '') apiError(400, 'Projektname ist erforderlich.');
    
    try {
        $stmt = db()->prepare('UPDATE projects SET title = :t, object_name = :o, address = :a, planned_window_count = :w WHERE id = :id');
        $stmt->execute([
            ':t' => $title,
            ':o' => trim((string)($body['object_name'] ?? '')),
            ':a' => trim((string)($body['address'] ?? '')),
            ':w' => (int)($body['planned_window_count'] ?? 0),
            ':id' => $id,
        ]);
        apiJson(['ok' => true]);
    } catch (Throwable $e) {
        apiError(503, 'Projekt konnte nicht aktualisiert werden.');
    }
} elseif ($method === 'DELETE' && $id) {
    requireProjectRole($user, ['administrator', 'projektleiter', 'sachverstaendiger', 'pruefer']);
    requireProjectDeleteAccess($user, $id);
    
    $permanent = ($action === 'permanent');
    
    try {
        if ($permanent) {
            if (($user['role'] ?? '') !== 'administrator') apiError(403, 'Endgültiges Löschen ist nur für Administratoren möglich.');
            // Cascade delete: windows (sashes, photos), rooms, floors, buildings, project
            $pdo = db();
            $pdo->exec("DELETE FROM window_sashes WHERE window_id IN (SELECT id FROM windows WHERE project_id = {$id})");
            $pdo->exec("DELETE FROM photos WHERE window_id IN (SELECT id FROM windows WHERE project_id = {$id})");
            $pdo->exec("DELETE FROM record_locks WHERE window_id IN (SELECT id FROM windows WHERE project_id = {$id})");
            $pdo->exec("DELETE FROM windows WHERE project_id = {$id}");
            $pdo->exec("DELETE FROM rooms WHERE floor_id IN (SELECT id FROM floors WHERE building_id IN (SELECT id FROM buildings WHERE project_id = {$id}))");
            $pdo->exec("DELETE FROM floors WHERE building_id IN (SELECT id FROM buildings WHERE project_id = {$id})");
            $pdo->exec("DELETE FROM buildings WHERE project_id = {$id}");
            $pdo->exec("DELETE FROM projects WHERE id = {$id}");
        } else {
            // Archive (soft delete)
            $stmt = db()->prepare('UPDATE projects SET is_active = 0 WHERE id = :id');
            $stmt->execute([':id' => $id]);
        }
        apiJson(['ok' => true]);
    } catch (Throwable $e) {
        apiError(503, 'Projekt konnte nicht gelöscht werden: ' . $e->getMessage());
    }
} elseif ($method === 'PATCH' && $id) {
    requireProjectRole($user, ['administrator', 'projektleiter']);
    $body = requestBody();
    $patchAction = trim((string)($body['action'] ?? ''));

    $stmt = db()->prepare('SELECT title FROM projects WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $project = $stmt->fetch();
    if (!$project) apiError(404, 'Projekt nicht gefunden.');

    $title = $project['title'];

    switch ($patchAction) {
        case 'archive':
            if (str_starts_with($title, '[Archiviert] ')) {
                $title = substr($title, 13);
            } else {
                $title = '[Archiviert] ' . $title;
            }
            break;
        case 'complete':
            $title = '✅ ' . $title;
            break;
        case 'reopen':
            if (str_starts_with($title, '✅ ')) {
                $title = substr($title, strlen('✅ '));
            }
            break;
        default:
            apiError(400, 'Unbekannte Aktion: ' . $patchAction);
    }

    $upd = db()->prepare('UPDATE projects SET title = :t WHERE id = :id');
    $upd->execute([':t' => $title, ':id' => $id]);
    apiJson(['ok' => true, 'title' => $title]);
} else {
    apiError(405, 'Methode nicht erlaubt.');
}
