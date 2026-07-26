<?php
/**
 * KI-gestützter Dokumentenimport – Fensterbeschlagsprüfung BMVg Bonn
 *
 * POST /api/ai-import.php?action=analyze   – Datei hochladen und KI-Analyse starten
 * POST /api/ai-import.php?action=apply     – Analyseergebnis anwenden (Daten anlegen/ergänzen)
 *
 * Erlaubte Rollen: administrator, pruefer
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

commonHeaders();

$user = requireAuth();
requireRole($user, ['administrator', 'pruefer']);

$action = $_GET['action'] ?? '';

match ($action) {
    'analyze' => handleAnalyze($user),
    'apply'   => handleApply($user),
    default   => apiError(400, 'Unbekannte Aktion. Erlaubt: analyze, apply'),
};

// ─── Analyse ─────────────────────────────────────────────────────────────────

function handleAnalyze(array $user): never
{
    $apiKey = env('OPENAI_API_KEY', '');
    if ($apiKey === '') {
        apiError(503, 'OpenAI API-Key ist nicht konfiguriert.');
    }

    if (empty($_FILES['file'])) {
        apiError(400, 'Keine Datei hochgeladen.');
    }

    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        apiError(400, 'Fehler beim Datei-Upload: Code ' . $file['error']);
    }

    $maxSize = 20 * 1024 * 1024; // 20 MB
    if ($file['size'] > $maxSize) {
        apiError(400, 'Datei zu groß. Maximal 20 MB erlaubt.');
    }

    $mime = mime_content_type($file['tmp_name']) ?: '';
    $allowedMimes = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'application/pdf',
        'text/csv', 'text/plain',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-excel',
    ];

    if (!in_array($mime, $allowedMimes, true)) {
        apiError(400, 'Dateityp nicht unterstützt. Erlaubt: Bilder, PDF, CSV, Excel.');
    }

    // Bestehende Projektdaten laden für Kontext
    $existingData = loadExistingProjectData();

    // Datei für die API vorbereiten
    $fileContent = file_get_contents($file['tmp_name']);
    $base64 = base64_encode($fileContent);
    $fileName = $file['name'];

    // Analyse durch OpenAI Vision
    $result = callOpenAI($apiKey, $base64, $mime, $fileName, $existingData);

    if ($result === null) {
        apiError(503, 'KI-Analyse fehlgeschlagen. Bitte erneut versuchen.');
    }

    apiJson([
        'ok'       => true,
        'analysis' => $result,
        'file_name' => $fileName,
        'file_type' => $mime,
    ]);
}

// ─── Anwenden ────────────────────────────────────────────────────────────────

function handleApply(array $user): never
{
    $body = requestBody();
    $items = $body['items'] ?? [];

    if (empty($items) || !is_array($items)) {
        apiError(400, 'Keine Einträge zum Anwenden übergeben.');
    }

    $created = [];
    $skipped = [];
    $errors  = [];

    foreach ($items as $item) {
        $type = $item['type'] ?? '';
        $data = $item['data'] ?? [];

        try {
            switch ($type) {
                case 'building':
                    $result = applyBuilding($data);
                    break;
                case 'floor':
                    $result = applyFloor($data);
                    break;
                case 'room':
                    $result = applyRoom($data);
                    break;
                case 'window':
                    $result = applyWindow($data);
                    break;
                default:
                    $result = ['status' => 'error', 'message' => "Unbekannter Typ: $type"];
            }

            if ($result['status'] === 'created') {
                $created[] = $result;
            } elseif ($result['status'] === 'exists') {
                $skipped[] = $result;
            } else {
                $errors[] = $result;
            }
        } catch (\Throwable $e) {
            $errors[] = ['status' => 'error', 'type' => $type, 'message' => $e->getMessage()];
        }
    }

    apiJson([
        'ok'      => true,
        'created' => $created,
        'skipped' => $skipped,
        'errors'  => $errors,
        'summary' => count($created) . ' angelegt, ' . count($skipped) . ' übersprungen, ' . count($errors) . ' Fehler',
    ]);
}

// ─── OpenAI API ──────────────────────────────────────────────────────────────

function callOpenAI(string $apiKey, string $base64, string $mime, string $fileName, array $existingData): ?array
{
    $systemPrompt = <<<PROMPT
Du bist ein Assistent für die Fensterbeschlagsprüfung BMVg Bonn. Du analysierst hochgeladene Dokumente (Baupläne, Fensterlisten, Raumlisten, Prüfberichte etc.) und extrahierst strukturierte Daten.

Bestehende Projektdaten (NICHT überschreiben, nur ergänzen):
- Gebäude: {$existingData['buildings_summary']}
- Etagen: {$existingData['floors_summary']}
- Räume: {$existingData['rooms_summary']}
- Fenster: {$existingData['windows_count']} Datensätze

REGELN:
1. Extrahiere alle erkennbaren Gebäude, Etagen, Räume und Fenster aus dem Dokument
2. Wenn ein Eintrag bereits existiert (gleicher Name/Code), markiere ihn als "exists"
3. Neue Einträge als "new" markieren
4. Gib die Daten als JSON-Array zurück

Antworte AUSSCHLIESSLICH mit einem JSON-Objekt in folgendem Format:
{
  "document_type": "bauplan|fensterliste|raumliste|pruefbericht|sonstiges",
  "summary": "Kurzbeschreibung des Dokuments",
  "items": [
    {
      "type": "building|floor|room|window",
      "status": "new|exists",
      "data": {
        // Für building: {"name": "...", "code": "..."}
        // Für floor: {"name": "...", "building_name": "...", "level": 0}
        // Für room: {"name": "...", "room_number": "...", "floor_name": "...", "building_name": "..."}
        // Für window: {"window_number": "...", "room_name": "...", "floor_name": "...", "building_name": "..."}
      },
      "confidence": 0.0-1.0
    }
  ]
}
PROMPT;

    $content = [];

    // Text-basierte Dateien direkt als Text senden
    if (str_starts_with($mime, 'text/') || $mime === 'application/csv') {
        $text = base64_decode($base64);
        $content[] = ['type' => 'text', 'text' => "Datei: $fileName\n\nInhalt:\n$text"];
    } elseif ($mime === 'application/pdf') {
        // PDF als base64 an GPT-4o (unterstützt PDF-Input)
        $content[] = [
            'type' => 'file',
            'file' => [
                'filename' => $fileName,
                'file_data' => "data:$mime;base64,$base64",
            ],
        ];
    } else {
        // Bild
        $content[] = [
            'type' => 'image_url',
            'image_url' => [
                'url' => "data:$mime;base64,$base64",
                'detail' => 'high',
            ],
        ];
    }

    $content[] = ['type' => 'text', 'text' => "Analysiere dieses Dokument und extrahiere alle Gebäude-/Etagen-/Raum-/Fensterdaten."];

    $payload = [
        'model'    => 'gpt-4o',
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $content],
        ],
        'max_tokens'  => 4096,
        'temperature' => 0.1,
    ];

    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            "Authorization: Bearer $apiKey",
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 120,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || $response === false) {
        error_log("[ai-import] OpenAI Fehler: HTTP $httpCode – " . substr((string)$response, 0, 500));
        return null;
    }

    $decoded = json_decode($response, true);
    $text = $decoded['choices'][0]['message']['content'] ?? '';

    // JSON aus der Antwort extrahieren (ggf. aus Markdown-Codeblock)
    if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $text, $m)) {
        $text = $m[1];
    }

    $parsed = json_decode($text, true);
    if ($parsed === null) {
        error_log("[ai-import] JSON-Parse-Fehler: " . substr($text, 0, 500));
        return null;
    }

    return $parsed;
}

// ─── Daten anwenden ──────────────────────────────────────────────────────────

function applyBuilding(array $data): array
{
    $name = trim($data['name'] ?? '');
    $code = trim($data['code'] ?? '');
    if ($name === '') return ['status' => 'error', 'message' => 'Gebäudename fehlt'];

    // Prüfen ob bereits vorhanden
    $existing = db()->prepare('SELECT id FROM buildings WHERE project_id=1 AND (name=:n OR (code!=\'\' AND code=:c))');
    $existing->execute([':n' => $name, ':c' => $code]);
    if ($existing->fetch()) {
        return ['status' => 'exists', 'type' => 'building', 'name' => $name];
    }

    $maxOrder = (int) db()->query('SELECT COALESCE(MAX(sort_order),0) FROM buildings WHERE project_id=1')->fetchColumn();
    $stmt = db()->prepare('INSERT INTO buildings (project_id,name,code,sort_order,created_at,updated_at) VALUES (1,:n,:c,:so,:now,:now2)');
    $stmt->execute([':n' => $name, ':c' => $code, ':so' => $maxOrder + 10, ':now' => nowUtc(), ':now2' => nowUtc()]);

    return ['status' => 'created', 'type' => 'building', 'name' => $name, 'id' => (int)db()->lastInsertId()];
}

function applyFloor(array $data): array
{
    $name = trim($data['name'] ?? '');
    $buildingName = trim($data['building_name'] ?? '');
    $level = (int)($data['level'] ?? 0);
    if ($name === '') return ['status' => 'error', 'message' => 'Etagenname fehlt'];

    // Gebäude finden
    $building = db()->prepare('SELECT id FROM buildings WHERE project_id=1 AND name=:n');
    $building->execute([':n' => $buildingName]);
    $bid = $building->fetchColumn();
    if (!$bid) return ['status' => 'error', 'message' => "Gebäude '$buildingName' nicht gefunden"];

    // Prüfen ob Etage bereits vorhanden
    $existing = db()->prepare('SELECT id FROM floors WHERE building_id=:bid AND name=:n');
    $existing->execute([':bid' => $bid, ':n' => $name]);
    if ($existing->fetch()) {
        return ['status' => 'exists', 'type' => 'floor', 'name' => $name, 'building' => $buildingName];
    }

    $stmt = db()->prepare('INSERT INTO floors (building_id,name,level,sort_order,created_at,updated_at) VALUES (:bid,:n,:lv,COALESCE((SELECT MAX(sort_order) FROM floors f2 WHERE f2.building_id=:bid2),0)+10,:now,:now2)');
    $stmt->execute([':bid' => $bid, ':bid2' => $bid, ':n' => $name, ':lv' => $level, ':now' => nowUtc(), ':now2' => nowUtc()]);

    return ['status' => 'created', 'type' => 'floor', 'name' => $name, 'building' => $buildingName, 'id' => (int)db()->lastInsertId()];
}

function applyRoom(array $data): array
{
    $name = trim($data['name'] ?? '');
    $roomNumber = trim($data['room_number'] ?? '');
    $floorName = trim($data['floor_name'] ?? '');
    $buildingName = trim($data['building_name'] ?? '');
    if ($name === '') return ['status' => 'error', 'message' => 'Raumname fehlt'];

    // Gebäude → Etage finden
    $floor = db()->prepare(
        'SELECT fl.id FROM floors fl JOIN buildings b ON b.id=fl.building_id WHERE b.project_id=1 AND b.name=:bn AND fl.name=:fn'
    );
    $floor->execute([':bn' => $buildingName, ':fn' => $floorName]);
    $fid = $floor->fetchColumn();
    if (!$fid) return ['status' => 'error', 'message' => "Etage '$floorName' in '$buildingName' nicht gefunden"];

    // Prüfen ob Raum bereits vorhanden
    $existing = db()->prepare('SELECT id FROM rooms WHERE floor_id=:fid AND (name=:n OR (room_number!=\'\' AND room_number=:rn))');
    $existing->execute([':fid' => $fid, ':n' => $name, ':rn' => $roomNumber]);
    if ($existing->fetch()) {
        return ['status' => 'exists', 'type' => 'room', 'name' => $name, 'floor' => $floorName];
    }

    $stmt = db()->prepare('INSERT INTO rooms (floor_id,name,room_number,sort_order,created_at,updated_at) VALUES (:fid,:n,:rn,COALESCE((SELECT MAX(sort_order) FROM rooms r2 WHERE r2.floor_id=:fid2),0)+10,:now,:now2)');
    $stmt->execute([':fid' => $fid, ':fid2' => $fid, ':n' => $name, ':rn' => $roomNumber, ':now' => nowUtc(), ':now2' => nowUtc()]);

    return ['status' => 'created', 'type' => 'room', 'name' => $name, 'number' => $roomNumber, 'id' => (int)db()->lastInsertId()];
}

function applyWindow(array $data): array
{
    $windowNumber = trim($data['window_number'] ?? '');
    $roomName = trim($data['room_name'] ?? '');
    $floorName = trim($data['floor_name'] ?? '');
    $buildingName = trim($data['building_name'] ?? '');
    if ($windowNumber === '') return ['status' => 'error', 'message' => 'Fensternummer fehlt'];

    // Raum finden
    $room = db()->prepare(
        'SELECT ro.id FROM rooms ro JOIN floors fl ON fl.id=ro.floor_id JOIN buildings b ON b.id=fl.building_id WHERE b.project_id=1 AND b.name=:bn AND fl.name=:fn AND ro.name=:rn'
    );
    $room->execute([':bn' => $buildingName, ':fn' => $floorName, ':rn' => $roomName]);
    $rid = $room->fetchColumn();
    if (!$rid) return ['status' => 'error', 'message' => "Raum '$roomName' in '$floorName/$buildingName' nicht gefunden"];

    // Prüfen ob Fenster bereits vorhanden
    $existing = db()->prepare('SELECT id FROM windows WHERE room_id=:rid AND window_number=:wn AND deleted_at IS NULL');
    $existing->execute([':rid' => $rid, ':wn' => $windowNumber]);
    if ($existing->fetch()) {
        return ['status' => 'exists', 'type' => 'window', 'number' => $windowNumber, 'room' => $roomName];
    }

    $recordId = 'BMVG-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    $stmt = db()->prepare('INSERT INTO windows (project_id,room_id,record_id,window_number,room_label,building_label,floor_label,status,created_at,updated_at) VALUES (1,:rid,:recid,:wnum,:rlabel,:blabel,:flabel,\'nicht begonnen\',:now,:now2)');
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
    $rooms = db()->query("SELECT ro.name, ro.room_number, fl.name AS floor, b.name AS building FROM rooms ro JOIN floors fl ON fl.id=ro.floor_id JOIN buildings b ON b.id=fl.building_id WHERE b.project_id=1 LIMIT 100")->fetchAll();
    $windowCount = (int) db()->query("SELECT COUNT(*) FROM windows WHERE project_id=1 AND deleted_at IS NULL")->fetchColumn();

    $bSummary = empty($buildings) ? 'keine' : implode(', ', array_map(fn($b) => $b['name'] . ($b['code'] ? " ({$b['code']})" : ''), $buildings));
    $fSummary = empty($floors) ? 'keine' : implode(', ', array_map(fn($f) => "{$f['name']} ({$f['building']})", $floors));
    $rSummary = empty($rooms) ? 'keine' : implode(', ', array_slice(array_map(fn($r) => $r['room_number'] ? "{$r['room_number']} {$r['name']}" : $r['name'], $rooms), 0, 30));

    return [
        'buildings_summary' => $bSummary,
        'floors_summary'    => $fSummary,
        'rooms_summary'     => $rSummary,
        'windows_count'     => (string) $windowCount,
    ];
}

function requireRole(array $user, array $roles): void
{
    if (!in_array($user['role'], $roles, true)) {
        apiError(403, 'Keine Berechtigung für diese Aktion.');
    }
}
