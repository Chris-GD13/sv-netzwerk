<?php
declare(strict_types=1);
require_once __DIR__.'/auth_check.php';
require_once __DIR__.'/api/administration-access.php';
if (!svnetCanManageWorkRules($user)) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="de"><meta charset="utf-8"><title>Zugriff nicht freigegeben</title><p>Dieser Bereich ist für dieses Benutzerkonto nicht freigegeben.</p><a href="/intern/tagescockpit/">Zum Tagescockpit</a></html>';
    exit();
}
