<?php
/**
 * Benutzerverwaltungs-API – Fensterbeschlagsprüfung BMVg Bonn
 *
 * Nur für Administratoren zugänglich.
 *
 * GET    /api/users.php              – Alle Benutzer auflisten
 * POST   /api/users.php              – Neuen Benutzer anlegen
 * PUT    /api/users.php?id={id}      – Benutzer aktualisieren (Name, Rolle, Status)
 * DELETE /api/users.php?id={id}      – Benutzer deaktivieren
 * POST   /api/users.php?action=set_password&id={id} – Passwort setzen
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

commonHeaders();

$actor  = requireAuth();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$id     = isset($_GET['id']) ? (int) $_GET['id'] : null;

if ($actor['role'] !== 'administrator') {
    apiError(403, 'Nur Administratoren dürfen die Benutzerverwaltung nutzen.');
}

match (true) {
    $method === 'GET'                                              => handleList(),
    $method === 'POST' && $action === 'set_password' && $id       => handleSetPassword($id, $actor),
    $method === 'POST'                                             => handleCreate($actor),
    $method === 'PUT'  && $id !== null                            => handleUpdate($id, $actor),
    $method === 'DELETE' && $id !== null                          => handleDeactivate($id, $actor),
    default                                                        => apiError(404, 'Unbekannter Endpunkt.'),
};

function handleList(): never
{
    try {
        $rows = db()->query(
            'SELECT id, email, full_name, role, is_active, last_login_at, created_at, updated_at
             FROM users
             ORDER BY full_name ASC'
        )->fetchAll();
    } catch (Throwable) {
        apiError(503, 'Benutzerliste konnte nicht geladen werden.');
    }
    apiJson($rows);
}

function handleCreate(array $actor): never
{
    $body     = requestBody();
    $email    = strtolower(trim((string) ($body['email'] ?? '')));
    $fullName = trim((string) ($body['full_name'] ?? ''));
    $role     = trim((string) ($body['role'] ?? 'pruefer'));
    $password = (string) ($body['password'] ?? '');

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        apiError(400, 'Gültige E-Mail-Adresse erforderlich.');
    }
    if ($fullName === '') {
        apiError(400, 'Name erforderlich.');
    }
    if (!in_array($role, ['administrator', 'projektleiter', 'sachverstaendiger', 'pruefer', 'gast'], true)) {
        apiError(400, 'Ungültige Rolle.');
    }
    if (mb_strlen($password, 'UTF-8') < 10) {
        apiError(400, 'Passwort muss mindestens 10 Zeichen lang sein.');
    }

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

    try {
        $stmt = db()->prepare(
            'INSERT INTO users (email, full_name, role, password_hash, is_active, created_at, updated_at)
             VALUES (:email, :name, :role, :hash, 1, :now, :now2)'
        );
        $stmt->execute([
            ':email' => $email,
            ':name'  => $fullName,
            ':role'  => $role,
            ':hash'  => $hash,
            ':now'   => nowUtc(),
            ':now2'  => nowUtc(),
        ]);
        $newId = (int) db()->lastInsertId();
    } catch (Throwable $e) {
        if (str_contains($e->getMessage(), 'Duplicate')) {
            apiError(409, 'E-Mail-Adresse bereits vorhanden.');
        }
        apiError(503, 'Benutzer konnte nicht angelegt werden.');
    }

    logAuditUser($newId, $actor, 'benutzer_angelegt', null, null,
        json_encode(['email' => $email, 'role' => $role], JSON_UNESCAPED_UNICODE));

    apiJson(['id' => $newId, 'email' => $email, 'full_name' => $fullName, 'role' => $role, 'is_active' => 1], 201);
}

function handleUpdate(int $id, array $actor): never
{
    $body     = requestBody();
    $fullName = trim((string) ($body['full_name'] ?? ''));
    $role     = trim((string) ($body['role'] ?? ''));
    $isActive = isset($body['is_active']) ? (int) (bool) $body['is_active'] : null;

    if ($fullName === '') {
        apiError(400, 'Name darf nicht leer sein.');
    }
    if ($role !== '' && !in_array($role, ['administrator', 'projektleiter', 'sachverstaendiger', 'pruefer', 'gast'], true)) {
        apiError(400, 'Ungültige Rolle.');
    }

    // Eigenen Administrator-Account nicht deaktivierbar
    if ($id === (int) $actor['id'] && $isActive === 0) {
        apiError(400, 'Sie können Ihr eigenes Konto nicht deaktivieren.');
    }

    try {
        $existing = db()->prepare('SELECT id, full_name, role, is_active FROM users WHERE id = :id');
        $existing->execute([':id' => $id]);
        $user = $existing->fetch();
    } catch (Throwable) {
        apiError(503, 'Datenbankfehler.');
    }
    if (!$user) {
        apiError(404, 'Benutzer nicht gefunden.');
    }

    $newRole     = $role !== '' ? $role : $user['role'];
    $newIsActive = $isActive !== null ? $isActive : $user['is_active'];

    try {
        db()->prepare(
            'UPDATE users SET full_name = :name, role = :role, is_active = :active, updated_at = :now
             WHERE id = :id'
        )->execute([':name' => $fullName, ':role' => $newRole, ':active' => $newIsActive, ':now' => nowUtc(), ':id' => $id]);
    } catch (Throwable) {
        apiError(503, 'Benutzer konnte nicht aktualisiert werden.');
    }

    logAuditUser($id, $actor, 'benutzer_aktualisiert',
        json_encode(['name' => $user['full_name'], 'role' => $user['role'], 'active' => $user['is_active']], JSON_UNESCAPED_UNICODE),
        json_encode(['name' => $fullName, 'role' => $newRole, 'active' => $newIsActive], JSON_UNESCAPED_UNICODE));

    apiJson(['id' => $id, 'full_name' => $fullName, 'role' => $newRole, 'is_active' => $newIsActive]);
}

function handleDeactivate(int $id, array $actor): never
{
    if ($id === (int) $actor['id']) {
        apiError(400, 'Sie können Ihr eigenes Konto nicht deaktivieren.');
    }

    try {
        $stmt = db()->prepare('UPDATE users SET is_active = 0, updated_at = :now WHERE id = :id');
        $stmt->execute([':now' => nowUtc(), ':id' => $id]);
    } catch (Throwable) {
        apiError(503, 'Benutzer konnte nicht deaktiviert werden.');
    }

    logAuditUser($id, $actor, 'benutzer_deaktiviert', '1', '0');

    apiJson(['ok' => true]);
}

function handleSetPassword(int $id, array $actor): never
{
    $body     = requestBody();
    $password = (string) ($body['password'] ?? '');

    if (mb_strlen($password, 'UTF-8') < 10) {
        apiError(400, 'Passwort muss mindestens 10 Zeichen lang sein.');
    }

    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

    try {
        db()->prepare('UPDATE users SET password_hash = :hash, updated_at = :now WHERE id = :id')
            ->execute([':hash' => $hash, ':now' => nowUtc(), ':id' => $id]);
    } catch (Throwable) {
        apiError(503, 'Passwort konnte nicht gesetzt werden.');
    }

    logAuditUser($id, $actor, 'passwort_geaendert', null, null, null);

    apiJson(['ok' => true]);
}

/** Schreibt Benutzerverwaltungsaktionen in ein einfaches JSON-Log (kein window_id FK). */
function logAuditUser(int $targetUserId, array $actor, string $action, ?string $old, ?string $new, ?string $reason = null): void
{
    // Für Benutzerverwaltungsaktionen wird window_id auf 0 gesetzt; in production kann
    // eine eigene Tabelle user_audit_logs angelegt werden. Hier reicht ein separates JSON-Log.
    // Da die audit_logs-Tabelle einen FK auf windows erfordert, loggen wir einfach in error_log.
    error_log(json_encode([
        'action'   => $action,
        'target'   => $targetUserId,
        'actor'    => $actor['id'],
        'actor_n'  => $actor['full_name'],
        'old'      => $old,
        'new'      => $new,
        'reason'   => $reason,
        'at'       => nowUtc(),
    ], JSON_UNESCAPED_UNICODE));
}
