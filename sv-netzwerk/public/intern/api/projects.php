<?php
/**
 * Projekte-API – Fensterprüfungsportal
 * 
 * GET /intern/api/projects.php → Alle aktiven Projekte
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

commonHeaders();
requireAuth();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = db()->query("SELECT id, project_code, title, object_name, address, planned_window_count, is_active,
        (SELECT COUNT(*) FROM windows WHERE windows.project_id = projects.id AND windows.deleted_at IS NULL) as window_count,
        (SELECT COUNT(*) FROM buildings WHERE buildings.project_id = projects.id) as building_count
        FROM projects WHERE is_active = 1 ORDER BY id ASC");
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);
    apiJson(['projects' => $projects]);
} else {
    apiError(405, 'Methode nicht erlaubt.');
}
