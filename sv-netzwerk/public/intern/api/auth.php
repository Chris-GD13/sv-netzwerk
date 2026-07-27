<?php
/**
 * Authentifizierungs-API – SV-Netzwerk Prüfportal
 *
 * Endpunkte:
 *   POST ?action=login   – E-Mail + Passwort → Session
 *   POST ?action=logout  – Session beenden
 *   GET  ?action=session – Aktuelle Session abfragen
 *   POST ?action=reset   – Passwort-Zurücksetzung (E-Mail-Versand via PHP mail())
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

commonHeaders();
startSession();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

match (true) {
    $method === 'POST' && $action === 'login'   => handleLogin(),
    $method === 'POST' && $action === 'logout'  => handleLogout(),
    $method === 'GET'  && $action === 'session' => handleSession(),
    $method === 'POST' && $action === 'reset'   => handleReset(),
    default                                     => apiError(404, 'Unbekannter Endpunkt.'),
};

function handleLogin(): never
{
    $body     = requestBody();
    $email    = trim((string) ($body['email'] ?? ''));
    $password = (string) ($body['password'] ?? '');

    if ($email === '' || $password === '') {
        apiError(400, 'E-Mail und Passwort sind erforderlich.');
    }

    try {
        $stmt = db()->prepare(
            'SELECT id, email, full_name, role, is_active, password_hash
             FROM users
             WHERE email = :email'
        );
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();
    } catch (Throwable $e) {
        apiError(503, 'Datenbankfehler beim Anmelden.');
    }

    if (!$user || !$user['is_active'] || !password_verify($password, $user['password_hash'])) {
        // Konstante Antwortzeit gegen Timing-Angriffe (gültiger bcrypt-Hash eines Dummy-Passworts)
        password_verify('dummy', '$2y$12$invalidhashpadding000000000000000000000000000zAIlmfxXfhW');
        apiError(401, 'Anmeldung fehlgeschlagen.');
    }

    session_regenerate_id(true);
    $_SESSION['user_id']  = $user['id'];
    $_SESSION['user_role'] = $user['role'];

    try {
        db()->prepare('UPDATE users SET last_login_at = :now WHERE id = :id')
            ->execute([':now' => nowUtc(), ':id' => $user['id']]);
    } catch (Throwable) {
        // nicht kritisch
    }

    apiJson([
        'id'        => $user['id'],
        'email'     => $user['email'],
        'full_name' => $user['full_name'],
        'role'      => $user['role'],
    ]);
}

function handleLogout(): never
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION = [];
        session_destroy();
    }
    apiJson(['ok' => true]);
}

function handleSession(): never
{
    if (empty($_SESSION['user_id'])) {
        apiJson(['user' => null]);
    }

    $user = currentUser();
    if ($user === null) {
        unset($_SESSION['user_id'], $_SESSION['user_role']);
        apiJson(['user' => null]);
    }

    apiJson([
        'user' => [
            'id'        => $user['id'],
            'email'     => $user['email'],
            'full_name' => $user['full_name'],
            'role'      => $user['role'],
        ],
    ]);
}

function handleReset(): never
{
    $body  = requestBody();
    $email = trim((string) ($body['email'] ?? ''));

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        apiError(400, 'Gültige E-Mail-Adresse erforderlich.');
    }

    try {
        $stmt = db()->prepare('SELECT id, full_name FROM users WHERE email = :email AND is_active = 1');
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch();
    } catch (Throwable) {
        apiError(503, 'Datenbankfehler.');
    }

    if ($user) {
        $token   = bin2hex(random_bytes(32));
        $expires = (new DateTimeImmutable('+2 hours', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        try {
            db()->prepare(
                'INSERT INTO password_resets (user_id, token, expires_at) VALUES (:uid, :token, :exp)
                 ON DUPLICATE KEY UPDATE token = :token2, expires_at = :exp2, created_at = NOW()'
            )->execute([':uid' => $user['id'], ':token' => $token, ':exp' => $expires,
                        ':token2' => $token, ':exp2' => $expires]);
        } catch (Throwable) {
            apiError(503, 'Token konnte nicht gespeichert werden.');
        }

        $origin  = rtrim((string) ($_SERVER['HTTP_ORIGIN'] ?? 'https://sv-netzwerk.eu'), '/');
        $link    = "$origin/intern/login/?reset_token=" . urlencode($token);
        $name    = $user['full_name'];
        $subject = 'Passwort zurücksetzen – ' . appProjectName();
        $body    = "Hallo $name,\n\nbitte verwenden Sie folgenden Link, um Ihr Passwort zurückzusetzen:\n\n$link\n\nDer Link ist zwei Stunden gültig.\n\nFalls Sie diese Anfrage nicht gestellt haben, ignorieren Sie diese E-Mail.\n";
        $from    = env('MAIL_FROM', 'noreply@sv-netzwerk.eu');
        $headers = "From: $from\r\nContent-Type: text/plain; charset=utf-8\r\n";

        @mail($email, $subject, $body, $headers);
    }

    // Immer OK zurückgeben (kein Enumeration-Angriff)
    apiJson(['ok' => true]);
}
