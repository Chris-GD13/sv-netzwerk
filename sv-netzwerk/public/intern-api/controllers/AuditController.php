<?php
declare(strict_types=1);

namespace SvIntern\Controllers;

use SvIntern\Models\AuditLog;

final class AuditController
{
    /** GET /intern-api/windows/{windowId}/audit */
    public static function list(array $session, \PDO $db, string $windowId): never
    {
        $model   = new AuditLog($db);
        $entries = $model->listByWindow($windowId);
        \jsonResponse(['data' => $entries]);
    }
}
