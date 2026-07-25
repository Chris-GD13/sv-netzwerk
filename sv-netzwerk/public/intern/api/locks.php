<?php
/**
 * Datensatzsperren API – Fensterbeschlagsprüfung BMVg Bonn
 *
 * POST   ?action=acquire&id={windowId} – Sperre setzen/erneuern
 * DELETE ?id={windowId}               – Sperre freigeben
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

commonHeaders();

$user   = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$id     = isset($_GET['id']) ? (int) $_GET['id'] : null;

match (true) {
    $method === 'POST'   && $action === 'acquire' && $id => handleAcquire($id, $user),
    $method === 'DELETE' && $id !== null                 => handleRelease($id, $user),
    default                                              => apiError(404, 'Unbekannter Endpunkt.'),
};

function handleAcquire(int $windowId, array $user): never
{
    $body           = requestBody();
    $timeoutMinutes = (int) ($body['timeout_minutes'] ?? 15);
    $expires        = (new DateTimeImmutable("+$timeoutMinutes minutes", new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

    try {
        // Prüfen ob jemand anderes die Sperre hält
        $stmt = db()->prepare(
            'SELECT owner_id, owner_name, expires_at FROM record_locks
             WHERE window_id = :wid AND expires_at > UTC_TIMESTAMP()'
        );
        $stmt->execute([':wid' => $windowId]);
        $existing = $stmt->fetch();

        if ($existing && (int) $existing['owner_id'] !== $user['id']) {
            apiJson([
                'ok'         => false,
                'owner_id'   => (int) $existing['owner_id'],
                'owner_name' => $existing['owner_name'],
                'expires_at' => $existing['expires_at'],
                'message'    => "Gesperrt von {$existing['owner_name']}.",
            ]);
        }

        // Eigene Sperre setzen oder erneuern
        db()->prepare(
            'INSERT INTO record_locks (window_id, owner_id, owner_name, expires_at)
             VALUES (:wid, :oid, :on, :exp)
             ON DUPLICATE KEY UPDATE owner_id = :oid2, owner_name = :on2, expires_at = :exp2'
        )->execute([
            ':wid'  => $windowId,
            ':oid'  => $user['id'],
            ':on'   => $user['full_name'] ?: $user['email'],
            ':exp'  => $expires,
            ':oid2' => $user['id'],
            ':on2'  => $user['full_name'] ?: $user['email'],
            ':exp2' => $expires,
        ]);
    } catch (Throwable $e) {
        apiError(503, 'Sperre konnte nicht gesetzt werden.');
    }

    apiJson([
        'ok'         => true,
        'owner_id'   => $user['id'],
        'owner_name' => $user['full_name'] ?: $user['email'],
        'expires_at' => $expires,
    ]);
}

function handleRelease(int $windowId, array $user): never
{
    try {
        db()->prepare(
            'DELETE FROM record_locks WHERE window_id = :wid AND owner_id = :oid'
        )->execute([':wid' => $windowId, ':oid' => $user['id']]);
    } catch (Throwable) {
        apiError(503, 'Sperre konnte nicht freigegeben werden.');
    }

    apiJson(['ok' => true]);
}
