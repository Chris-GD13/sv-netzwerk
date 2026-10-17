<?php
declare(strict_types=1);
require_once __DIR__.'/auth_check.php';
require_once __DIR__.'/api/private-business-access.php';
if (!pbCanCreate($user)) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="de"><meta charset="utf-8"><title>Zugriff nicht freigegeben</title><p>Die Erstellung von Angeboten und Aufträgen ist für dieses Benutzerkonto nicht freigegeben.</p><a href="/intern/privatauftraege/">Zu den Privataufträgen</a></html>';
    exit;
}
