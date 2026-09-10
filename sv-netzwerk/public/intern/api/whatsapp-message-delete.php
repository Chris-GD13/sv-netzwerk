<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/profile-routing.php';

commonHeaders();
$user = requireAuth();
if (!in_array((string)($user['role'] ?? ''), ['administrator','projektleiter','pruefer','sachverstaendiger'], true)) apiError(403, 'Keine Berechtigung.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') apiError(405, 'POST erforderlich.');

try {
    $body = requestBody();
    $wamid = trim((string)($body['wamid'] ?? ''));
    if ($wamid === '' || mb_strlen($wamid) > 190) apiError(400, 'WhatsApp-Nachricht fehlt.');
    $profile = svnetSelectedProfile($user, (string)($_SESSION['svnet_selected_expert'] ?? ''));
    if (!in_array($profile, ['christian','marc','holger'], true)) apiError(403, 'Für dieses Bearbeiterprofil ist keine WhatsApp-Anbindung vorgesehen.');

    $stmt = db()->prepare('SELECT direction,drive_file_id,folder_id FROM whatsapp_messages WHERE wamid=:wamid AND profile_key=:profile LIMIT 1');
    $stmt->execute([':wamid'=>$wamid, ':profile'=>$profile]);
    $row = $stmt->fetch();
    if (!is_array($row)) apiError(404, 'WhatsApp-Nachricht wurde nicht gefunden.');

    // Löscht ausschließlich den Eintrag aus der Portal-Historie. Bereits an WhatsApp
    // zugestellte Nachrichten sowie in der Schadenakte gespeicherte Dateien bleiben erhalten.
    $stmt = db()->prepare('DELETE FROM whatsapp_messages WHERE wamid=:wamid AND profile_key=:profile');
    $stmt->execute([':wamid'=>$wamid, ':profile'=>$profile]);
    apiJson(['ok'=>true,'deleted'=>true,'wamid'=>$wamid,'drive_file_preserved'=>!empty($row['drive_file_id'])]);
} catch (Throwable $error) {
    apiError(503, $error->getMessage());
}
