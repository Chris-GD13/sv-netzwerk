<?php
/** Sichere Sammelaktionen für die Gruppierungen der Auswertung. */
declare(strict_types=1);

require_once __DIR__ . '/config.php';
commonHeaders();

function requireAnalysisGroupRole(array $user, array $roles): void
{
    if (!in_array($user['role'] ?? '', $roles, true)) {
        apiError(403, 'Keine Berechtigung für diese Aktion.');
    }
}

$user = requireAuth();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apiError(405, 'Methode nicht erlaubt.');
}
requireAnalysisGroupRole($user, ['administrator', 'projektleiter']);

$body = requestBody();
$projectId = isset($_GET['project_id']) ? (int) $_GET['project_id'] : DEFAULT_PROJECT_ID;
$groupType = (string)($body['group_type'] ?? '');
$groupValue = trim((string)($body['group_value'] ?? ''));
$action = (string)($body['action'] ?? '');
$targetValue = trim((string)($body['target_value'] ?? ''));

if (!in_array($groupType, ['building', 'floor', 'inspector', 'system'], true)) {
    apiError(400, 'Unbekannte Gruppierung.');
}
if (!in_array($action, ['rename', 'move', 'archive', 'delete'], true)) {
    apiError(400, 'Unbekannte Aktion.');
}
if ($groupValue === '') {
    apiError(400, 'Gruppenwert fehlt.');
}
if ($action === 'delete') {
    requireAnalysisGroupRole($user, ['administrator']);
}

$fieldConfig = [
    'building' => ['column' => 'building_label', 'json' => '$.building_label', 'empty' => 'Unbekannt'],
    'floor' => ['column' => 'floor_label', 'json' => '$.floor_label', 'empty' => 'Unbekannt'],
    'inspector' => ['column' => 'assigned_name', 'json' => '$.inspector_name', 'empty' => 'Nicht zugewiesen'],
    'system' => ['column' => null, 'json' => '$.window_system', 'empty' => 'Nicht erfasst'],
][$groupType];

$jsonExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(CASE WHEN JSON_VALID(form_data) THEN form_data ELSE '{}' END, '" . $fieldConfig['json'] . "')), '')";
$valueExpr = $fieldConfig['column'] !== null
    ? 'COALESCE(NULLIF(' . $fieldConfig['column'] . ", ''), :empty_value)"
    : 'COALESCE(' . $jsonExpr . ', :empty_value)';
$where = "project_id = :project_id AND deleted_at IS NULL AND $valueExpr = :group_value";
$params = [':project_id' => $projectId, ':empty_value' => $fieldConfig['empty'], ':group_value' => $groupValue];

try {
    $pdo = db();
    $pdo->beginTransaction();

    if ($action === 'delete') {
        $stmt = $pdo->prepare("UPDATE windows SET deleted_at = :now, updated_at = :now2 WHERE $where");
        $stmt->execute([':now' => nowUtc(), ':now2' => nowUtc()] + $params);
        $affected = $stmt->rowCount();
    } else {
        $newValue = $action === 'archive' ? '[Archiviert] ' . $groupValue : $targetValue;
        if ($newValue === '') {
            $pdo->rollBack();
            apiError(400, 'Neue Bezeichnung oder Ziel fehlt.');
        }
        $set = "form_data = JSON_SET(CASE WHEN JSON_VALID(form_data) THEN form_data ELSE '{}' END, '" . $fieldConfig['json'] . "', :new_json), updated_at = :now";
        if ($fieldConfig['column'] !== null) {
            $set = $fieldConfig['column'] . ' = :new_column, ' . $set;
        }
        $stmt = $pdo->prepare("UPDATE windows SET $set WHERE $where");
        $writeParams = [':new_json' => $newValue, ':now' => nowUtc()] + $params;
        if ($fieldConfig['column'] !== null) $writeParams[':new_column'] = $newValue;
        $stmt->execute($writeParams);
        $affected = $stmt->rowCount();

        // Beschriftung und Hierarchie bei Gebäude/Etage synchron halten.
        if ($groupType === 'building') {
            $sync = $pdo->prepare('UPDATE buildings SET name=:new_name, updated_at=:now WHERE project_id=:pid AND name=:old_name');
            $sync->execute([':new_name'=>$newValue, ':now'=>nowUtc(), ':pid'=>$projectId, ':old_name'=>$groupValue]);
        } elseif ($groupType === 'floor') {
            $sync = $pdo->prepare('UPDATE floors fl JOIN buildings b ON b.id=fl.building_id SET fl.name=:new_name, fl.updated_at=:now WHERE b.project_id=:pid AND fl.name=:old_name');
            $sync->execute([':new_name'=>$newValue, ':now'=>nowUtc(), ':pid'=>$projectId, ':old_name'=>$groupValue]);
        }
    }

    $pdo->commit();
    apiJson(['ok' => true, 'affected' => $affected, 'value' => $newValue ?? null]);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    error_log('[analysis-groups] ' . $e->getMessage());
    apiError(503, 'Sammelaktion konnte nicht ausgeführt werden.');
}
