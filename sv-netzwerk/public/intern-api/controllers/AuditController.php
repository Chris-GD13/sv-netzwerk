<?php
declare(strict_types=1);

namespace SvIntern\Controllers;

use SvIntern\Models\AuditLog;

final class AuditController
{
    /** GET /intern-api/inspections/{inspectionId}/audit */
    public static function list(array $session, \PDO $db, string $inspectionId): never
    {
        $model   = new AuditLog($db);
        $entries = $model->listByInspection($inspectionId);
        \jsonResponse(['data' => $entries]);
    }
}
