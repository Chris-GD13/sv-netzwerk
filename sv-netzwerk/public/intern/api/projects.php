<?php
/**
 * Projekte-API – SV-Netzwerk Prüfportal
 * 
 * GET  /intern/api/projects.php → Alle aktiven Projekte
 * POST /intern/api/projects.php → Neues Projekt anlegen
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

commonHeaders();
$user = requireAuth();

$method = $_SERVER['REQUEST_METHOD'];

function requireProjectRole(array $user, array $roles): void {
    if (!in_array($user['role'] ?? '', $roles, true)) {
        apiError(403, 'Keine Berechtigung für diese Aktion.');
    }
}

if ($method === 'GET') {
    $stmt = db()->query("SELECT id, project_code, title, object_name, address, planned_window_count, is_active,
        (SELECT COUNT(*) FROM windows WHERE windows.project_id = projects.id AND windows.deleted_at IS NULL) as window_count,
        (SELECT COUNT(*) FROM buildings WHERE buildings.project_id = projects.id) as building_count
        FROM projects WHERE is_active = 1 ORDER BY id ASC");
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    apiJson(['projects' => $projects]);
} elseif ($method === 'POST') {
    requireProjectRole($user, ['administrator', 'projektleiter']);
    $body = requestBody();
    
    $title = trim((string)($body['title'] ?? ''));
    $objectName = trim((string)($body['object_name'] ?? ''));
    $address = trim((string)($body['address'] ?? ''));
    $windowCount = (int)($body['planned_window_count'] ?? 0);
    
    if ($title === '') {
        apiError(400, 'Projektname ist erforderlich.');
    }
    
    // Generate URL-safe project_code from title
    $code = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $title), '-'));
    $code = substr($code, 0, 50);
    
    // Check uniqueness
    $exists = db()->prepare('SELECT id FROM projects WHERE project_code = :c');
    $exists->execute([':c' => $code]);
    if ($exists->fetch()) {
        $code .= '-' . date('Ymd');
    }
    
    try {
        $stmt = db()->prepare('INSERT INTO projects (project_code, title, object_name, address, planned_window_count, is_active) VALUES (:code, :title, :obj, :addr, :wc, 1)');
        $stmt->execute([':code' => $code, ':title' => $title, ':obj' => $objectName, ':addr' => $address, ':wc' => $windowCount]);
        $newId = (int)db()->lastInsertId();
        apiJson(['id' => $newId, 'project_code' => $code], 201);
    } catch (Throwable $e) {
        apiError(503, 'Projekt konnte nicht angelegt werden: ' . $e->getMessage());
    }
} else {
    apiError(405, 'Methode nicht erlaubt.');
}
