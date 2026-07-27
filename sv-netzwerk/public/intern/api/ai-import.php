<?php
/**
 * KI-gestützter Dokumentenimport – SV-Netzwerk Prüfportal
 *
 * POST /api/ai-import.php?action=analyze   – Datei hochladen und KI-Analyse starten
 * POST /api/ai-import.php?action=apply     – Analyseergebnis anwenden (Daten anlegen/ergänzen)
 *
 * Erlaubte Rollen: administrator, pruefer
 * Unterstützt: Bilder, PDF, CSV, Excel, Word, E-Mail (.msg) · max. 200 MB
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/services/AiService.php';

commonHeaders();

$user = requireAuth();
if (!in_array($user['role'], ['administrator', 'pruefer'], true)) {
    apiError(403, 'KI-Import ist nur für Administratoren und Prüfer verfügbar.');
}

$action = $_GET['action'] ?? '';

match ($action) {
    'analyze' => handleAnalyze($user),
    'apply'   => handleApply($user),
    default   => apiError(400, 'Unbekannte Aktion. Erlaubt: analyze, apply'),
};

// ─── Analyse ─────────────────────────────────────────────────────────────────

function handleAnalyze(array $user): never
{
    $ai = new AiService();
    if (!$ai->isConfigured()) {
        apiError(503, 'OpenAI API-Key ist nicht konfiguriert. Bitte Administrator kontaktieren.');
    }

    if (empty($_FILES['file'])) {
        apiError(400, 'Keine Datei hochgeladen.');
    }

    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        apiError(400, 'Fehler beim Datei-Upload: Code ' . $file['error']);
    }

    $maxSize = 200 * 1024 * 1024; // 200 MB
    if ($file['size'] > $maxSize) {
        apiError(400, 'Datei zu groß. Maximal 20 MB erlaubt.');
    }

    $mime = mime_content_type($file['tmp_name']) ?: '';
    // .msg-Dateien werden oft als octet-stream erkannt – Endung prüfen
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext === 'msg' && $mime === 'application/octet-stream') {
        $mime = 'application/vnd.ms-outlook';
    }
    if (!in_array($mime, AiService::supportedMimeTypes(), true)) {
        apiError(400, 'Dateityp nicht unterstützt (' . $mime . '). Erlaubt: Bilder, PDF, CSV, Excel, Word, E-Mail (.msg) · max. 200 MB.');
    }

    // Bestehende Projektdaten laden für Datenabgleich
    $existingData = loadExistingProjectData();

    // Datei vorbereiten
    $fileContent = file_get_contents($file['tmp_name']);
    $base64 = base64_encode($fileContent);
    $fileName = $file['name'];

    // KI-Analyse durchführen
    $result = $ai->analyzeDocument($base64, $mime, $fileName, $existingData);

    if ($result === null) {
        apiError(503, 'KI-Analyse fehlgeschlagen. Bitte erneut versuchen oder ein anderes Format wählen.');
    }

    // Server-seitiger Datenabgleich für präzisere Status-Klassifizierung
    $result['items'] = classifyItems($result['items'] ?? []);

    apiJson([
        'ok'       => true,
        'analysis' => $result,
        'file_name' => $fileName,
        'file_type' => $mime,
    ]);
}

// ─── Server-seitige Klassifizierung ──────────────────────────────────────────

function classifyItems(array $items): array
{
    foreach ($items as &$item) {
        $type = $item['type'] ?? '';
        $data = $item['data'] ?? [];

        switch ($type) {
            case 'building':
                $item['status'] = classifyBuilding($data);
                break;
            case 'floor':
                $item['status'] = classifyFloor($data);
                break;
            case 'room':
                $item['status'] = classifyRoom($data);
                break;
            case 'window':
                $item['status'] = classifyWindow($data, $item);
                break;
        }
    }
    unset($item);
    return $items;
}

function classifyBuilding(array $data): string
{
    $name = trim($data['name'] ?? '');
    $code = trim($data['code'] ?? '');
    if ($name === '') return 'new';

    $stmt = db()->prepare('SELECT id, name, code FROM buildings WHERE project_id=1 AND (name=:n OR (code!=\'\' AND code=:c))');
    $stmt->execute([':n' => $name, ':c' => $code ?: '---']);
    $existing = $stmt->fetch();

    if (!$existing) return 'new';
    if ($existing['name'] === $name && ($code === '' || $existing['code'] === $code)) return 'exists';
    return 'conflict';
}

function classifyFloor(array $data): string
{
    $name = trim($data['name'] ?? '');
    $buildingName = trim($data['building_name'] ?? '');
    if ($name === '' || $buildingName === '') return 'new';

    $stmt = db()->prepare(
        'SELECT fl.id FROM floors fl JOIN buildings b ON b.id=fl.building_id WHERE b.project_id=1 AND b.name=:bn AND fl.name=:fn'
    );
    $stmt->execute([':bn' => $buildingName, ':fn' => $name]);
    return $stmt->fetch() ? 'exists' : 'new';
}

function classifyRoom(array $data): string
{
    $name = trim($data['name'] ?? '');
    $roomNumber = trim($data['room_number'] ?? '');
    $floorName = trim($data['floor_name'] ?? '');
    $buildingName = trim($data['building_name'] ?? '');
    if ($name === '' && $roomNumber === '') return 'new';

    $stmt = db()->prepare(
        'SELECT ro.id FROM rooms ro JOIN floors fl ON fl.id=ro.floor_id JOIN buildings b ON b.id=fl.building_id
         WHERE b.project_id=1 AND b.name=:bn AND fl.name=:fn AND (ro.name=:n OR (ro.room_number!=\'\' AND ro.room_number=:rn))'
    );
    $stmt->execute([':bn' => $buildingName, ':fn' => $floorName, ':n' => $name, ':rn' => $roomNumber ?: '---']);
    return $stmt->fetch() ? 'exists' : 'new';
}

function classifyWindow(array $data, array &$item): string
{
    $windowNumber = trim($data['window_number'] ?? '');
    $buildingName = trim($data['building_name'] ?? '');
    if ($windowNumber === '') return 'new';

    $stmt = db()->prepare(
        'SELECT w.id, w.window_number, w.room_label, w.building_label, w.floor_label
         FROM windows w
         JOIN rooms ro ON ro.id=w.room_id
         JOIN floors fl ON fl.id=ro.floor_id
         JOIN buildings b ON b.id=fl.building_id
         WHERE w.project_id=1 AND w.window_number=:wn AND b.name=:bn AND w.deleted_at IS NULL'
    );
    $stmt->execute([':wn' => $windowNumber, ':bn' => $buildingName]);
    $existing = $stmt->fetch();

    if (!$existing) return 'new';

    // Prüfen ob neue Daten als Ergänzung zählen
    $hasNewInfo = !empty($data['manufacturer']) || !empty($data['hardware_system']) || !empty($data['width_mm']) || !empty($data['notes']);
    if ($hasNewInfo) {
        $item['change_description'] = 'Zusätzliche Informationen verfügbar (Hersteller/Maße/Beschlag)';
        return 'update';
    }

    return 'exists';
}

// ─── Anwenden ────────────────────────────────────────────────────────────────

function handleApply(array $user): never
{
    $body = requestBody();
    $items = $body['items'] ?? [];

    if (empty($items) || !is_array($items)) {
        apiError(400, 'Keine Einträge zum Anwenden übergeben.');
    }

    $created  = [];
    $updated  = [];
    $skipped  = [];
    $errors   = [];

    foreach ($items as $item) {
        $type   = $item['type'] ?? '';
        $status = $item['status'] ?? 'new';
        $data   = $item['data'] ?? [];

        try {
            $result = match ($type) {
                'building' => applyBuilding($data, $status),
                'floor'    => applyFloor($data, $status),
                'room'     => applyRoom($data, $status),
                'window'   => applyWindow($data, $status),
                default    => ['status' => 'error', 'message' => "Unbekannter Typ: $type"],
            };

            match ($result['status']) {
                'created' => $created[] = $result,
                'updated' => $updated[] = $result,
                'exists'  => $skipped[] = $result,
                default   => $errors[]  = $result,
            };
        } catch (\Throwable $e) {
            $errors[] = ['status' => 'error', 'type' => $type, 'message' => $e->getMessage()];
        }
    }

    $summaryParts = [];
    if (count($created) > 0) $summaryParts[] = count($created) . ' angelegt';
    if (count($updated) > 0) $summaryParts[] = count($updated) . ' ergänzt';
    if (count($skipped) > 0) $summaryParts[] = count($skipped) . ' übersprungen';
    if (count($errors) > 0)  $summaryParts[] = count($errors) . ' Fehler';

    apiJson([
        'ok'      => true,
        'created' => $created,
        'updated' => $updated,
        'skipped' => $skipped,
        'errors'  => $errors,
        'summary' => implode(', ', $summaryParts) ?: 'Keine Änderungen',
    ]);
}

// ─── Daten anwenden ──────────────────────────────────────────────────────────

function applyBuilding(array $data, string $status): array
{
    $name = trim($data['name'] ?? '');
    $code = trim($data['code'] ?? '');
    if ($name === '') return ['status' => 'error', 'message' => 'Gebäudename fehlt'];

    $existing = db()->prepare('SELECT id FROM buildings WHERE project_id=1 AND (name=:n OR (code!=\'\' AND code=:c))');
    $existing->execute([':n' => $name, ':c' => $code ?: '---']);
    if ($existing->fetch()) {
        return ['status' => 'exists', 'type' => 'building', 'name' => $name];
    }

    $maxOrder = (int) db()->query('SELECT COALESCE(MAX(sort_order),0) FROM buildings WHERE project_id=1')->fetchColumn();
    $stmt = db()->prepare('INSERT INTO buildings (project_id,name,code,sort_order,created_at,updated_at) VALUES (1,:n,:c,:so,:now,:now2)');
    $stmt->execute([':n' => $name, ':c' => $code, ':so' => $maxOrder + 10, ':now' => nowUtc(), ':now2' => nowUtc()]);

    return ['status' => 'created', 'type' => 'building', 'name' => $name, 'id' => (int)db()->lastInsertId()];
}

function applyFloor(array $data, string $status): array
{
    $name = trim($data['name'] ?? '');
    $buildingName = trim($data['building_name'] ?? '');
    $level = (int)($data['level'] ?? 0);
    if ($name === '') return ['status' => 'error', 'message' => 'Etagenname fehlt'];

    $building = db()->prepare('SELECT id FROM buildings WHERE project_id=1 AND name=:n');
    $building->execute([':n' => $buildingName]);
    $bid = $building->fetchColumn();
    if (!$bid) return ['status' => 'error', 'message' => "Gebäude '$buildingName' nicht gefunden. Bitte zuerst anlegen."];

    $existing = db()->prepare('SELECT id FROM floors WHERE building_id=:bid AND name=:n');
    $existing->execute([':bid' => $bid, ':n' => $name]);
    if ($existing->fetch()) {
        return ['status' => 'exists', 'type' => 'floor', 'name' => $name, 'building' => $buildingName];
    }

    $stmt = db()->prepare('INSERT INTO floors (building_id,name,level,sort_order,created_at,updated_at) VALUES (:bid,:n,:lv,COALESCE((SELECT MAX(sort_order) FROM floors f2 WHERE f2.building_id=:bid2),0)+10,:now,:now2)');
    $stmt->execute([':bid' => $bid, ':bid2' => $bid, ':n' => $name, ':lv' => $level, ':now' => nowUtc(), ':now2' => nowUtc()]);

    return ['status' => 'created', 'type' => 'floor', 'name' => $name, 'building' => $buildingName, 'id' => (int)db()->lastInsertId()];
}

function applyRoom(array $data, string $status): array
{
    $name = trim($data['name'] ?? '');
    $roomNumber = trim($data['room_number'] ?? '');
    $floorName = trim($data['floor_name'] ?? '');
    $buildingName = trim($data['building_name'] ?? '');
    if ($name === '') return ['status' => 'error', 'message' => 'Raumname fehlt'];

    $floor = db()->prepare(
        'SELECT fl.id FROM floors fl JOIN buildings b ON b.id=fl.building_id WHERE b.project_id=1 AND b.name=:bn AND fl.name=:fn'
    );
    $floor->execute([':bn' => $buildingName, ':fn' => $floorName]);
    $fid = $floor->fetchColumn();
    if (!$fid) return ['status' => 'error', 'message' => "Etage '$floorName' in '$buildingName' nicht gefunden."];

    $existing = db()->prepare('SELECT id FROM rooms WHERE floor_id=:fid AND (name=:n OR (room_number!=\'\' AND room_number=:rn))');
    $existing->execute([':fid' => $fid, ':n' => $name, ':rn' => $roomNumber ?: '---']);
    if ($existing->fetch()) {
        return ['status' => 'exists', 'type' => 'room', 'name' => $name, 'floor' => $floorName];
    }

    $stmt = db()->prepare('INSERT INTO rooms (floor_id,name,room_number,sort_order,created_at,updated_at) VALUES (:fid,:n,:rn,COALESCE((SELECT MAX(sort_order) FROM rooms r2 WHERE r2.floor_id=:fid2),0)+10,:now,:now2)');
    $stmt->execute([':fid' => $fid, ':fid2' => $fid, ':n' => $name, ':rn' => $roomNumber, ':now' => nowUtc(), ':now2' => nowUtc()]);

    return ['status' => 'created', 'type' => 'room', 'name' => $name, 'number' => $roomNumber, 'id' => (int)db()->lastInsertId()];
}

function applyWindow(array $data, string $status): array
{
    $windowNumber = trim($data['window_number'] ?? '');
    $roomName = trim($data['room_name'] ?? '');
    $floorName = trim($data['floor_name'] ?? '');
    $buildingName = trim($data['building_name'] ?? '');
    if ($windowNumber === '') return ['status' => 'error', 'message' => 'Fensternummer fehlt'];

    // Raum finden
    $room = db()->prepare(
        'SELECT ro.id FROM rooms ro JOIN floors fl ON fl.id=ro.floor_id JOIN buildings b ON b.id=fl.building_id
         WHERE b.project_id=1 AND b.name=:bn AND fl.name=:fn AND ro.name=:rn'
    );
    $room->execute([':bn' => $buildingName, ':fn' => $floorName, ':rn' => $roomName]);
    $rid = $room->fetchColumn();
    if (!$rid) return ['status' => 'error', 'message' => "Raum '$roomName' in '$floorName/$buildingName' nicht gefunden."];

    // Prüfen ob Fenster bereits vorhanden
    $existingStmt = db()->prepare('SELECT id FROM windows WHERE room_id=:rid AND window_number=:wn AND deleted_at IS NULL');
    $existingStmt->execute([':rid' => $rid, ':wn' => $windowNumber]);
    $existingWindow = $existingStmt->fetch();

    if ($existingWindow && $status === 'update') {
        // Ergänzung: Nur leere Felder befüllen (niemals überschreiben)
        $updates = [];
        $params = [':id' => $existingWindow['id']];
        $fields = ['manufacturer' => 'manufacturer', 'hardware_system' => 'hardware_system', 'notes' => 'notes'];
        foreach ($fields as $srcKey => $dbCol) {
            $val = trim($data[$srcKey] ?? '');
            if ($val !== '') {
                $updates[] = "$dbCol = CASE WHEN $dbCol IS NULL OR $dbCol = '' THEN :$dbCol ELSE $dbCol END";
                $params[":$dbCol"] = $val;
            }
        }
        if (!empty($updates)) {
            $updates[] = "updated_at = :now";
            $params[':now'] = nowUtc();
            $sql = 'UPDATE windows SET ' . implode(', ', $updates) . ' WHERE id = :id';
            db()->prepare($sql)->execute($params);
            return ['status' => 'updated', 'type' => 'window', 'number' => $windowNumber, 'id' => (int)$existingWindow['id']];
        }
        return ['status' => 'exists', 'type' => 'window', 'number' => $windowNumber];
    }

    if ($existingWindow) {
        return ['status' => 'exists', 'type' => 'window', 'number' => $windowNumber, 'room' => $roomName];
    }

    // Neu anlegen
    $recordId = 'BMVG-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    $stmt = db()->prepare(
        'INSERT INTO windows (project_id,room_id,record_id,window_number,room_label,building_label,floor_label,status,created_at,updated_at)
         VALUES (1,:rid,:recid,:wnum,:rlabel,:blabel,:flabel,\'nicht begonnen\',:now,:now2)'
    );
    $stmt->execute([
        ':rid' => $rid, ':recid' => $recordId, ':wnum' => $windowNumber,
        ':rlabel' => $roomName, ':blabel' => $buildingName, ':flabel' => $floorName,
        ':now' => nowUtc(), ':now2' => nowUtc(),
    ]);

    return ['status' => 'created', 'type' => 'window', 'number' => $windowNumber, 'record_id' => $recordId, 'id' => (int)db()->lastInsertId()];
}

// ─── Hilfsfunktionen ─────────────────────────────────────────────────────────

function loadExistingProjectData(): array
{
    $buildings = db()->query("SELECT name, code FROM buildings WHERE project_id=1 ORDER BY name")->fetchAll();
    $floors = db()->query("SELECT fl.name, b.name AS building FROM floors fl JOIN buildings b ON b.id=fl.building_id WHERE b.project_id=1")->fetchAll();
    $rooms = db()->query("SELECT ro.name, ro.room_number, fl.name AS floor, b.name AS building FROM rooms ro JOIN floors fl ON fl.id=ro.floor_id JOIN buildings b ON b.id=fl.building_id WHERE b.project_id=1 LIMIT 200")->fetchAll();
    $windowCount = (int) db()->query("SELECT COUNT(*) FROM windows WHERE project_id=1 AND deleted_at IS NULL")->fetchColumn();
    $windows = db()->query("SELECT w.window_number, w.room_label, w.floor_label, w.building_label FROM windows w WHERE w.project_id=1 AND w.deleted_at IS NULL ORDER BY w.window_number LIMIT 50")->fetchAll();

    $bSummary = empty($buildings) ? 'keine' : implode(', ', array_map(fn($b) => $b['name'] . ($b['code'] ? " ({$b['code']})" : ''), $buildings));
    $fSummary = empty($floors) ? 'keine' : implode(', ', array_map(fn($f) => "{$f['name']} ({$f['building']})", $floors));
    $rSummary = empty($rooms) ? 'keine' : implode(', ', array_slice(array_map(fn($r) => $r['room_number'] ? "{$r['room_number']} {$r['name']}" : $r['name'], $rooms), 0, 50));
    $wSummary = empty($windows) ? '' : "\n- Fenster (Auszug): " . implode(', ', array_map(fn($w) => $w['window_number'], $windows));

    return [
        'buildings_summary' => $bSummary,
        'floors_summary'    => $fSummary,
        'rooms_summary'     => $rSummary,
        'windows_count'     => (string) $windowCount,
        'windows_summary'   => $wSummary,
    ];
}
