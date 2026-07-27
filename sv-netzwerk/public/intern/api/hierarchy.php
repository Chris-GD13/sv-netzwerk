<?php
/**
 * Hierarchie-API – SV-Netzwerk Prüfportal
 *
 * GET /api/hierarchy.php                   – Alle Gebäude mit Statistik
 * GET /api/hierarchy.php?building_id={id}  – Etagen eines Gebäudes
 * GET /api/hierarchy.php?floor_id={id}     – Räume einer Etage
 * GET /api/hierarchy.php?room_id={id}      – Fenster eines Raumes mit Flügelzählung
 * GET /api/hierarchy.php?window_id={id}    – Flügel eines Fensters
 *
 * POST /api/hierarchy.php?entity=building           – Gebäude anlegen
 * POST /api/hierarchy.php?entity=floor              – Etage anlegen
 * POST /api/hierarchy.php?entity=room               – Raum anlegen
 * POST /api/hierarchy.php?entity=window             – Fenster anlegen (in Raum)
 *
 * PUT  /api/hierarchy.php?entity=building&id={id}   – Gebäude bearbeiten
 * PUT  /api/hierarchy.php?entity=floor&id={id}      – Etage bearbeiten
 * PUT  /api/hierarchy.php?entity=room&id={id}       – Raum bearbeiten
 * PUT  /api/hierarchy.php?entity=window&id={id}     – Fenster bearbeiten
 *
 * DELETE /api/hierarchy.php?entity=building&id={id} – Gebäude löschen
 * DELETE /api/hierarchy.php?entity=floor&id={id}    – Etage löschen
 * DELETE /api/hierarchy.php?entity=room&id={id}     – Raum löschen
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

commonHeaders();

$user       = requireAuth();
$method     = $_SERVER['REQUEST_METHOD'];
$entity     = $_GET['entity']      ?? '';
$projectId  = isset($_GET['project_id']) ? (int) $_GET['project_id'] : 1;
$buildingId = isset($_GET['building_id']) ? (int) $_GET['building_id'] : null;
$floorId    = isset($_GET['floor_id'])    ? (int) $_GET['floor_id']    : null;
$roomId     = isset($_GET['room_id'])     ? (int) $_GET['room_id']     : null;
$windowId   = isset($_GET['window_id'])   ? (int) $_GET['window_id']   : null;
$id         = isset($_GET['id'])          ? (int) $_GET['id']          : null;

match (true) {
    $method === 'GET'  && $windowId !== null   => handleGetSashList($windowId),
    $method === 'GET'  && $roomId !== null      => handleGetWindowsInRoom($roomId),
    $method === 'GET'  && $floorId !== null     => handleGetRoomsInFloor($floorId),
    $method === 'GET'  && $buildingId !== null  => handleGetFloorsInBuilding($buildingId),
    $method === 'GET'                           => handleGetBuildings($projectId),
    $method === 'POST' && $entity !== ''        => handleCreate($entity, $user, $projectId),
    $method === 'PUT'  && $entity !== '' && $id => handleUpdate($entity, $id, $user),
    $method === 'PATCH' && $entity !== '' && $id => handleAction($entity, $id, $user, $projectId),
    $method === 'DELETE' && $entity !== '' && $id => handleDelete($entity, $id, $user),
    default                                     => apiError(404, 'Unbekannter Endpunkt.'),
};

// ─── GET ────────────────────────────────────────────────────────────────────

function handleGetBuildings(int $projectId = 1): never
{
    try {
        $stmt = db()->prepare(
            'SELECT b.id, b.name, b.code, b.sort_order,
                    COUNT(DISTINCT w.id)  AS window_count,
                    COUNT(DISTINCT ws.id) AS sash_count,
                    SUM(CASE WHEN ws.status IN (\'abgeschlossen\',\'freigegeben\') THEN 1 ELSE 0 END) AS sash_completed,
                    SUM(CASE WHEN ws.has_defect = 1 THEN 1 ELSE 0 END) AS sash_defect
             FROM buildings b
             LEFT JOIN floors fl ON fl.building_id = b.id
             LEFT JOIN rooms ro  ON ro.floor_id    = fl.id
             LEFT JOIN windows w ON w.room_id      = ro.id AND w.deleted_at IS NULL
             LEFT JOIN window_sashes ws ON ws.window_id = w.id AND ws.deleted_at IS NULL
             WHERE b.project_id = :pid
             GROUP BY b.id
             ORDER BY b.sort_order ASC, b.name ASC'
        );
        $stmt->execute([':pid' => $projectId]);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        apiError(503, 'Gebäudeliste konnte nicht geladen werden: ' . $e->getMessage());
    }
    apiJson(array_map('mapBuilding', $rows));
}

function handleGetFloorsInBuilding(int $buildingId): never
{
    try {
        $stmt = db()->prepare(
            'SELECT fl.id, fl.name, fl.level, fl.sort_order,
                    COUNT(DISTINCT ro.id) AS room_count,
                    COUNT(DISTINCT w.id)  AS window_count,
                    COUNT(DISTINCT ws.id) AS sash_count,
                    SUM(CASE WHEN ws.status IN (\'abgeschlossen\',\'freigegeben\') THEN 1 ELSE 0 END) AS sash_completed
             FROM floors fl
             LEFT JOIN rooms ro  ON ro.floor_id    = fl.id
             LEFT JOIN windows w ON w.room_id      = ro.id AND w.deleted_at IS NULL
             LEFT JOIN window_sashes ws ON ws.window_id = w.id AND ws.deleted_at IS NULL
             WHERE fl.building_id = :bid
             GROUP BY fl.id
             ORDER BY fl.sort_order ASC, fl.level ASC'
        );
        $stmt->execute([':bid' => $buildingId]);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        apiError(503, 'Etagenliste konnte nicht geladen werden: ' . $e->getMessage());
    }
    apiJson(array_map('mapFloor', $rows));
}

function handleGetRoomsInFloor(int $floorId): never
{
    try {
        $stmt = db()->prepare(
            'SELECT ro.id, ro.name, ro.room_number, ro.sort_order,
                    COUNT(DISTINCT w.id)  AS window_count,
                    COUNT(DISTINCT ws.id) AS sash_count,
                    SUM(CASE WHEN ws.status IN (\'abgeschlossen\',\'freigegeben\') THEN 1 ELSE 0 END) AS sash_completed,
                    SUM(CASE WHEN ws.has_defect = 1 THEN 1 ELSE 0 END) AS sash_defect
             FROM rooms ro
             LEFT JOIN windows w ON w.room_id = ro.id AND w.deleted_at IS NULL
             LEFT JOIN window_sashes ws ON ws.window_id = w.id AND ws.deleted_at IS NULL
             WHERE ro.floor_id = :fid
             GROUP BY ro.id
             ORDER BY ro.sort_order ASC, ro.room_number ASC, ro.name ASC'
        );
        $stmt->execute([':fid' => $floorId]);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        apiError(503, 'Raumliste konnte nicht geladen werden: ' . $e->getMessage());
    }
    apiJson(array_map('mapRoom', $rows));
}

function handleGetWindowsInRoom(int $roomId): never
{
    try {
        $stmt = db()->prepare(
            'SELECT w.id, w.window_number, w.record_id, w.status, w.updated_at,
                    COUNT(DISTINCT ws.id) AS sash_count,
                    SUM(CASE WHEN ws.status IN (\'abgeschlossen\',\'freigegeben\') THEN 1 ELSE 0 END) AS sash_completed,
                    SUM(CASE WHEN ws.has_defect = 1 THEN 1 ELSE 0 END) AS sash_defect,
                    ro.name AS room_name, ro.room_number AS room_no,
                    fl.name AS floor_name, b.name AS building_name, b.id AS building_id, fl.id AS floor_id
             FROM windows w
             LEFT JOIN window_sashes ws ON ws.window_id = w.id AND ws.deleted_at IS NULL
             LEFT JOIN rooms ro ON ro.id = w.room_id
             LEFT JOIN floors fl ON fl.id = ro.floor_id
             LEFT JOIN buildings b ON b.id = fl.building_id
             WHERE w.room_id = :rid AND w.deleted_at IS NULL
             GROUP BY w.id
             ORDER BY w.window_number ASC'
        );
        $stmt->execute([':rid' => $roomId]);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        apiError(503, 'Fensterliste konnte nicht geladen werden: ' . $e->getMessage());
    }
    apiJson(array_map('mapWindowInRoom', $rows));
}

function handleGetSashList(int $windowId): never
{
    try {
        $stmt = db()->prepare(
            'SELECT ws.id, ws.sash_number, ws.sash_label, ws.opening_type, ws.position,
                    ws.status, ws.progress_percent, ws.has_defect, ws.urgent_action,
                    ws.overall_rating, ws.inspector_name, ws.inspected_at, ws.completed_at,
                    ws.updated_at,
                    w.window_number, w.record_id,
                    ro.name AS room_name, ro.room_number AS room_no,
                    fl.name AS floor_name, fl.id AS floor_id,
                    b.name AS building_name, b.id AS building_id
             FROM window_sashes ws
             JOIN windows w ON w.id = ws.window_id
             LEFT JOIN rooms ro ON ro.id = w.room_id
             LEFT JOIN floors fl ON fl.id = ro.floor_id
             LEFT JOIN buildings b ON b.id = fl.building_id
             WHERE ws.window_id = :wid AND ws.deleted_at IS NULL
             ORDER BY ws.sash_number ASC'
        );
        $stmt->execute([':wid' => $windowId]);
        $rows = $stmt->fetchAll();
    } catch (Throwable $e) {
        apiError(503, 'Flügelliste konnte nicht geladen werden: ' . $e->getMessage());
    }
    apiJson(array_map('mapSash', $rows));
}

// ─── POST ───────────────────────────────────────────────────────────────────

function handleCreate(string $entity, array $user, int $projectId = 1): never
{
    requireRole($user, ['administrator', 'projektleiter', 'sachverstaendiger', 'pruefer']);
    $body = requestBody();

    switch ($entity) {
        case 'building':
            $name = trim((string) ($body['name'] ?? ''));
            if ($name === '') apiError(400, 'Name ist erforderlich.');
            try {
                $soStmt = db()->prepare('SELECT COALESCE(MAX(sort_order),0) FROM buildings WHERE project_id=:pid');
                $soStmt->execute([':pid'=>$projectId]);
                $maxOrder = (int) $soStmt->fetchColumn();
                $stmt = db()->prepare('INSERT INTO buildings (project_id,name,code,notes,sort_order,created_at,updated_at) VALUES (:pid,:n,:c,:notes,:so,:now,:now2)');
                $stmt->execute([':pid'=>$projectId,':n'=>$name,':c'=>trim((string)($body['code']??'')),':notes'=>trim((string)($body['notes']??''))?:null,':so'=>$maxOrder+10,':now'=>nowUtc(),':now2'=>nowUtc()]);
                apiJson(['id'=>(int)db()->lastInsertId(),'name'=>$name], 201);
            } catch (Throwable $e) { apiError(503, 'Gebäude konnte nicht angelegt werden: '.$e->getMessage()); }

        case 'floor':
            $name = trim((string) ($body['name'] ?? ''));
            $bid  = (int) ($body['building_id'] ?? 0);
            if ($name === '' || $bid === 0) apiError(400, 'Name und Gebäude sind erforderlich.');
            try {
                $stmt = db()->prepare('INSERT INTO floors (building_id,name,level,notes,sort_order,created_at,updated_at) VALUES (:bid,:n,:lv,:notes,COALESCE((SELECT MAX(sort_order) FROM floors f2 WHERE f2.building_id=:bid2),0)+10,:now,:now2)');
                $stmt->execute([':bid'=>$bid,':bid2'=>$bid,':n'=>$name,':lv'=>(int)($body['level']??0),':notes'=>trim((string)($body['notes']??''))?:null,':now'=>nowUtc(),':now2'=>nowUtc()]);
                apiJson(['id'=>(int)db()->lastInsertId(),'name'=>$name], 201);
            } catch (Throwable $e) { apiError(503, 'Etage konnte nicht angelegt werden: '.$e->getMessage()); }

        case 'room':
            $name = trim((string) ($body['name'] ?? ''));
            $fid  = (int) ($body['floor_id'] ?? 0);
            if ($name === '' || $fid === 0) apiError(400, 'Name und Etage sind erforderlich.');
            try {
                $stmt = db()->prepare('INSERT INTO rooms (floor_id,name,room_number,notes,sort_order,created_at,updated_at) VALUES (:fid,:n,:rn,:notes,COALESCE((SELECT MAX(sort_order) FROM rooms r2 WHERE r2.floor_id=:fid2),0)+10,:now,:now2)');
                $stmt->execute([':fid'=>$fid,':fid2'=>$fid,':n'=>$name,':rn'=>trim((string)($body['room_number']??'')),':notes'=>trim((string)($body['notes']??''))?:null,':now'=>nowUtc(),':now2'=>nowUtc()]);
                apiJson(['id'=>(int)db()->lastInsertId(),'name'=>$name], 201);
            } catch (Throwable $e) { apiError(503, 'Raum konnte nicht angelegt werden: '.$e->getMessage()); }

        case 'window':
            $rid  = (int) ($body['room_id'] ?? 0);
            $wnum = trim((string) ($body['window_number'] ?? ''));
            if ($rid === 0) apiError(400, 'Raum ist erforderlich.');
            try {
                // Standortdaten aus Raumhierarchie ermitteln
                $loc = db()->prepare('SELECT ro.name AS room_name, ro.room_number, fl.name AS floor_name, b.name AS building_name FROM rooms ro JOIN floors fl ON fl.id=ro.floor_id JOIN buildings b ON b.id=fl.building_id WHERE ro.id=:rid');
                $loc->execute([':rid'=>$rid]);
                $location = $loc->fetch() ?: [];
                $recordId = 'BMVG-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
                $stmt = db()->prepare('INSERT INTO windows (project_id,room_id,record_id,window_number,room_label,room_number,building_label,floor_label,status,created_at,updated_at) VALUES (:pid,:rid,:recid,:wnum,:rlabel,:rnum,:blabel,:flabel,\'nicht begonnen\',:now,:now2)');
                $stmt->execute([
                    ':pid'=>$projectId,':rid'=>$rid,':recid'=>$recordId,':wnum'=>$wnum,
                    ':rlabel'=>$location['room_name']??null,':rnum'=>$location['room_number']??null,
                    ':blabel'=>$location['building_name']??null,':flabel'=>$location['floor_name']??null,
                    ':now'=>nowUtc(),':now2'=>nowUtc(),
                ]);
                $newId = (int) db()->lastInsertId();
                apiJson(['id'=>$newId,'record_id'=>$recordId], 201);
            } catch (Throwable $e) { apiError(503, 'Fenster konnte nicht angelegt werden: '.$e->getMessage()); }

        default:
            apiError(400, 'Unbekannte Entität.');
    }
}

// ─── PUT ────────────────────────────────────────────────────────────────────

function handleUpdate(string $entity, int $id, array $user): never
{
    requireRole($user, ['administrator', 'projektleiter', 'sachverstaendiger', 'pruefer']);
    $body = requestBody();

    switch ($entity) {
        case 'building':
            $name = trim((string) ($body['name'] ?? ''));
            if ($name === '') apiError(400, 'Name ist erforderlich.');
            try {
                db()->prepare('UPDATE buildings SET name=:n, code=:c, notes=:notes, updated_at=:now WHERE id=:id')
                    ->execute([':n'=>$name,':c'=>trim((string)($body['code']??'')),':notes'=>trim((string)($body['notes']??''))?:null,':now'=>nowUtc(),':id'=>$id]);
                apiJson(['ok'=>true]);
            } catch (Throwable $e) { apiError(503, 'Gebäude konnte nicht aktualisiert werden: '.$e->getMessage()); }

        case 'floor':
            $name = trim((string) ($body['name'] ?? ''));
            if ($name === '') apiError(400, 'Name ist erforderlich.');
            try {
                db()->prepare('UPDATE floors SET name=:n, level=:lv, notes=:notes, updated_at=:now WHERE id=:id')
                    ->execute([':n'=>$name,':lv'=>(int)($body['level']??0),':notes'=>trim((string)($body['notes']??''))?:null,':now'=>nowUtc(),':id'=>$id]);
                apiJson(['ok'=>true]);
            } catch (Throwable $e) { apiError(503, 'Etage konnte nicht aktualisiert werden: '.$e->getMessage()); }

        case 'room':
            $name = trim((string) ($body['name'] ?? ''));
            if ($name === '') apiError(400, 'Name ist erforderlich.');
            try {
                db()->prepare('UPDATE rooms SET name=:n, room_number=:rn, notes=:notes, updated_at=:now WHERE id=:id')
                    ->execute([':n'=>$name,':rn'=>trim((string)($body['room_number']??'')),':notes'=>trim((string)($body['notes']??''))?:null,':now'=>nowUtc(),':id'=>$id]);
                apiJson(['ok'=>true]);
            } catch (Throwable $e) { apiError(503, 'Raum konnte nicht aktualisiert werden: '.$e->getMessage()); }

        case 'window':
            try {
                db()->prepare('UPDATE windows SET window_number=:wnum, updated_at=:now WHERE id=:id AND deleted_at IS NULL')
                    ->execute([':wnum'=>trim((string)($body['window_number']??'')),':now'=>nowUtc(),':id'=>$id]);
                apiJson(['ok'=>true]);
            } catch (Throwable $e) { apiError(503, 'Fenster konnte nicht aktualisiert werden: '.$e->getMessage()); }

        default:
            apiError(400, 'Unbekannte Entität.');
    }
}

// ─── DELETE ─────────────────────────────────────────────────────────────────

function handleDelete(string $entity, int $id, array $user): never
{
    requireRole($user, ['administrator']);
    switch ($entity) {
        case 'building':
            try { db()->prepare('DELETE FROM buildings WHERE id=:id')->execute([':id'=>$id]); apiJson(['ok'=>true]); }
            catch (Throwable $e) { apiError(503, 'Gebäude konnte nicht gelöscht werden: '.$e->getMessage()); }
        case 'floor':
            try { db()->prepare('DELETE FROM floors WHERE id=:id')->execute([':id'=>$id]); apiJson(['ok'=>true]); }
            catch (Throwable $e) { apiError(503, 'Etage konnte nicht gelöscht werden: '.$e->getMessage()); }
        case 'room':
            try { db()->prepare('DELETE FROM rooms WHERE id=:id')->execute([':id'=>$id]); apiJson(['ok'=>true]); }
            catch (Throwable $e) { apiError(503, 'Raum konnte nicht gelöscht werden: '.$e->getMessage()); }
        case 'window':
            try { db()->prepare('UPDATE windows SET deleted_at=:now WHERE id=:id')->execute([':now'=>nowUtc(),':id'=>$id]); apiJson(['ok'=>true]); }
            catch (Throwable $e) { apiError(503, 'Fenster konnte nicht gelöscht werden: '.$e->getMessage()); }
        default:
            apiError(400, 'Unbekannte Entität.');
    }
}

// ─── PATCH (Duplizieren / Archivieren / Verschieben) ──────────────────────────

function handleAction(string $entity, int $id, array $user, int $projectId): never
{
    requireRole($user, ['administrator', 'projektleiter']);
    $body   = requestBody();
    $action = $body['action'] ?? '';

    switch ($action) {
        case 'duplicate':
            handleDuplicate($entity, $id, $projectId);
        case 'archive':
            handleArchive($entity, $id);
        case 'move':
            handleMove($entity, $id, $body);
        default:
            apiError(400, 'Unbekannte Aktion: ' . $action);
    }
}

function handleDuplicate(string $entity, int $id, int $projectId): never
{
    $pdo = db();
    try {
        $pdo->beginTransaction();
        switch ($entity) {
            case 'building':
                $src = $pdo->prepare('SELECT * FROM buildings WHERE id=:id');
                $src->execute([':id' => $id]);
                $b = $src->fetch();
                if (!$b) { $pdo->rollBack(); apiError(404, 'Gebäude nicht gefunden.'); }
                $newName = $b['name'] . ' (Kopie)';
                $ins = $pdo->prepare('INSERT INTO buildings (project_id,name,code,notes,sort_order,created_at,updated_at) VALUES (:pid,:n,:c,:notes,:so,:now,:now2)');
                $ins->execute([':pid'=>$b['project_id'],':n'=>$newName,':c'=>$b['code'],':notes'=>$b['notes'],':so'=>(int)$b['sort_order']+1,':now'=>nowUtc(),':now2'=>nowUtc()]);
                $newBuildingId = (int)$pdo->lastInsertId();
                // Copy floors → rooms
                $floors = $pdo->prepare('SELECT * FROM floors WHERE building_id=:bid');
                $floors->execute([':bid'=>$id]);
                foreach ($floors->fetchAll() as $fl) {
                    $fIns = $pdo->prepare('INSERT INTO floors (building_id,name,level,notes,sort_order,created_at,updated_at) VALUES (:bid,:n,:lv,:notes,:so,:now,:now2)');
                    $fIns->execute([':bid'=>$newBuildingId,':n'=>$fl['name'],':lv'=>$fl['level'],':notes'=>$fl['notes'],':so'=>$fl['sort_order'],':now'=>nowUtc(),':now2'=>nowUtc()]);
                    $newFloorId = (int)$pdo->lastInsertId();
                    $rooms = $pdo->prepare('SELECT * FROM rooms WHERE floor_id=:fid');
                    $rooms->execute([':fid'=>$fl['id']]);
                    foreach ($rooms->fetchAll() as $ro) {
                        $rIns = $pdo->prepare('INSERT INTO rooms (floor_id,name,room_number,notes,sort_order,created_at,updated_at) VALUES (:fid,:n,:rn,:notes,:so,:now,:now2)');
                        $rIns->execute([':fid'=>$newFloorId,':n'=>$ro['name'],':rn'=>$ro['room_number'],':notes'=>$ro['notes'],':so'=>$ro['sort_order'],':now'=>nowUtc(),':now2'=>nowUtc()]);
                    }
                }
                $pdo->commit();
                apiJson(['id'=>$newBuildingId,'name'=>$newName], 201);

            case 'floor':
                $src = $pdo->prepare('SELECT * FROM floors WHERE id=:id');
                $src->execute([':id' => $id]);
                $fl = $src->fetch();
                if (!$fl) { $pdo->rollBack(); apiError(404, 'Etage nicht gefunden.'); }
                $newName = $fl['name'] . ' (Kopie)';
                $ins = $pdo->prepare('INSERT INTO floors (building_id,name,level,notes,sort_order,created_at,updated_at) VALUES (:bid,:n,:lv,:notes,:so,:now,:now2)');
                $ins->execute([':bid'=>$fl['building_id'],':n'=>$newName,':lv'=>$fl['level'],':notes'=>$fl['notes'],':so'=>(int)$fl['sort_order']+1,':now'=>nowUtc(),':now2'=>nowUtc()]);
                $newFloorId = (int)$pdo->lastInsertId();
                $rooms = $pdo->prepare('SELECT * FROM rooms WHERE floor_id=:fid');
                $rooms->execute([':fid'=>$id]);
                foreach ($rooms->fetchAll() as $ro) {
                    $rIns = $pdo->prepare('INSERT INTO rooms (floor_id,name,room_number,notes,sort_order,created_at,updated_at) VALUES (:fid,:n,:rn,:notes,:so,:now,:now2)');
                    $rIns->execute([':fid'=>$newFloorId,':n'=>$ro['name'],':rn'=>$ro['room_number'],':notes'=>$ro['notes'],':so'=>$ro['sort_order'],':now'=>nowUtc(),':now2'=>nowUtc()]);
                }
                $pdo->commit();
                apiJson(['id'=>$newFloorId,'name'=>$newName], 201);

            case 'room':
                $src = $pdo->prepare('SELECT * FROM rooms WHERE id=:id');
                $src->execute([':id' => $id]);
                $ro = $src->fetch();
                if (!$ro) { $pdo->rollBack(); apiError(404, 'Raum nicht gefunden.'); }
                $newName = $ro['name'] . ' (Kopie)';
                $ins = $pdo->prepare('INSERT INTO rooms (floor_id,name,room_number,notes,sort_order,created_at,updated_at) VALUES (:fid,:n,:rn,:notes,:so,:now,:now2)');
                $ins->execute([':fid'=>$ro['floor_id'],':n'=>$newName,':rn'=>$ro['room_number'],':notes'=>$ro['notes'],':so'=>(int)$ro['sort_order']+1,':now'=>nowUtc(),':now2'=>nowUtc()]);
                $pdo->commit();
                apiJson(['id'=>(int)$pdo->lastInsertId(),'name'=>$newName], 201);

            default:
                $pdo->rollBack();
                apiError(400, 'Duplizieren nicht unterstützt für: ' . $entity);
        }
    } catch (Throwable $e) {
        $pdo->rollBack();
        apiError(503, 'Duplizieren fehlgeschlagen: ' . $e->getMessage());
    }
}

function handleArchive(string $entity, int $id): never
{
    // Soft-delete via deleted_at timestamp (reversible)
    $table = match($entity) {
        'building' => 'buildings',
        'floor'    => 'floors',
        'room'     => 'rooms',
        default    => '',
    };
    if ($table === '') apiError(400, 'Archivieren nicht unterstützt für: ' . $entity);

    // Check if column exists - we use a notes prefix "[ARCHIVIERT]" as archive marker
    try {
        $stmt = db()->prepare("UPDATE $table SET name = CONCAT('[Archiviert] ', name), updated_at=:now WHERE id=:id AND name NOT LIKE '[Archiviert]%'");
        $stmt->execute([':now'=>nowUtc(), ':id'=>$id]);
        if ($stmt->rowCount() === 0) {
            // Already archived? Unarchive
            $stmt2 = db()->prepare("UPDATE $table SET name = REPLACE(name, '[Archiviert] ', ''), updated_at=:now WHERE id=:id");
            $stmt2->execute([':now'=>nowUtc(), ':id'=>$id]);
            apiJson(['ok'=>true, 'archived'=>false]);
        }
        apiJson(['ok'=>true, 'archived'=>true]);
    } catch (Throwable $e) {
        apiError(503, 'Archivieren fehlgeschlagen: ' . $e->getMessage());
    }
}

function handleMove(string $entity, int $id, array $body): never
{
    $targetId = (int)($body['target_id'] ?? 0);
    if ($targetId === 0) apiError(400, 'Ziel-ID ist erforderlich.');

    try {
        switch ($entity) {
            case 'floor':
                // Move floor to another building
                db()->prepare('UPDATE floors SET building_id=:bid, updated_at=:now WHERE id=:id')
                    ->execute([':bid'=>$targetId, ':now'=>nowUtc(), ':id'=>$id]);
                break;
            case 'room':
                // Move room to another floor
                db()->prepare('UPDATE rooms SET floor_id=:fid, updated_at=:now WHERE id=:id')
                    ->execute([':fid'=>$targetId, ':now'=>nowUtc(), ':id'=>$id]);
                break;
            case 'window':
                // Move window to another room
                db()->prepare('UPDATE windows SET room_id=:rid, updated_at=:now WHERE id=:id AND deleted_at IS NULL')
                    ->execute([':rid'=>$targetId, ':now'=>nowUtc(), ':id'=>$id]);
                break;
            default:
                apiError(400, 'Verschieben nicht unterstützt für: ' . $entity);
        }
        apiJson(['ok'=>true]);
    } catch (Throwable $e) {
        apiError(503, 'Verschieben fehlgeschlagen: ' . $e->getMessage());
    }
}

// ─── Mapping ─────────────────────────────────────────────────────────────────

function mapBuilding(array $row): array
{
    return [
        'id'             => (int) $row['id'],
        'name'           => $row['name'],
        'code'           => $row['code'] ?? '',
        'sort_order'     => (int) $row['sort_order'],
        'window_count'   => (int) $row['window_count'],
        'sash_count'     => (int) $row['sash_count'],
        'sash_completed' => (int) $row['sash_completed'],
        'sash_defect'    => (int) $row['sash_defect'],
        'progress_pct'   => $row['sash_count'] > 0
            ? (int) round((int)$row['sash_completed'] / (int)$row['sash_count'] * 100)
            : 0,
    ];
}

function mapFloor(array $row): array
{
    return [
        'id'             => (int) $row['id'],
        'name'           => $row['name'],
        'level'          => (int) $row['level'],
        'sort_order'     => (int) $row['sort_order'],
        'room_count'     => (int) $row['room_count'],
        'window_count'   => (int) $row['window_count'],
        'sash_count'     => (int) $row['sash_count'],
        'sash_completed' => (int) $row['sash_completed'],
        'progress_pct'   => $row['sash_count'] > 0
            ? (int) round((int)$row['sash_completed'] / (int)$row['sash_count'] * 100)
            : 0,
    ];
}

function mapRoom(array $row): array
{
    return [
        'id'             => (int) $row['id'],
        'name'           => $row['name'],
        'room_number'    => $row['room_number'] ?? '',
        'sort_order'     => (int) $row['sort_order'],
        'window_count'   => (int) $row['window_count'],
        'sash_count'     => (int) $row['sash_count'],
        'sash_completed' => (int) $row['sash_completed'],
        'sash_defect'    => (int) $row['sash_defect'],
        'progress_pct'   => $row['sash_count'] > 0
            ? (int) round((int)$row['sash_completed'] / (int)$row['sash_count'] * 100)
            : 0,
    ];
}

function mapWindowInRoom(array $row): array
{
    return [
        'id'             => (int) $row['id'],
        'window_number'  => $row['window_number'] ?? '',
        'record_id'      => $row['record_id'],
        'status'         => $row['status'],
        'updated_at'     => $row['updated_at'],
        'sash_count'     => (int) $row['sash_count'],
        'sash_completed' => (int) $row['sash_completed'],
        'sash_defect'    => (int) $row['sash_defect'],
        'room_name'      => $row['room_name'] ?? null,
        'room_number'    => $row['room_no'] ?? null,
        'floor_name'     => $row['floor_name'] ?? null,
        'building_name'  => $row['building_name'] ?? null,
        'building_id'    => isset($row['building_id']) ? (int)$row['building_id'] : null,
        'floor_id'       => isset($row['floor_id']) ? (int)$row['floor_id'] : null,
        'progress_pct'   => $row['sash_count'] > 0
            ? (int) round((int)$row['sash_completed'] / (int)$row['sash_count'] * 100)
            : 0,
    ];
}

function mapSash(array $row): array
{
    return [
        'id'              => (int) $row['id'],
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
        'window_number'   => $row['window_number'] ?? '',
        'window_id'       => 0, // filled per context
        'room_name'       => $row['room_name'] ?? null,
        'room_number'     => $row['room_no'] ?? null,
        'floor_name'      => $row['floor_name'] ?? null,
        'floor_id'        => isset($row['floor_id']) ? (int)$row['floor_id'] : null,
        'building_name'   => $row['building_name'] ?? null,
        'building_id'     => isset($row['building_id']) ? (int)$row['building_id'] : null,
    ];
}

// ─── Hilfsfunktionen ─────────────────────────────────────────────────────────

function requireRole(array $user, array $roles): void
{
    if (!in_array($user['role'], $roles, true)) {
        apiError(403, 'Keine Berechtigung für diese Aktion.');
    }
}
