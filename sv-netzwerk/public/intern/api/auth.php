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

function getClientIp(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
        $val = trim((string) ($_SERVER[$key] ?? ''));
        if ($val !== '') {
            return trim(explode(',', $val)[0]);
        }
    }
    return '0.0.0.0';
}

function notifyAdminLoginBlock(string $ip, string $email, string $reason): void
{
    $to      = appMailAdmin();
    $subject = '[Prüfportal] Login-Sperrung: ' . $reason;
    $time    = (new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin')))->format('d.m.Y H:i:s');
    $body    = "Login-Sperrung ausgelöst\n\n"
             . "Zeitpunkt:      $time Uhr\n"
             . "IP-Adresse:     $ip\n"
             . "E-Mail-Adresse: $email\n"
             . "Grund:          $reason\n"
             . "Sperrdauer:     15 Minuten\n\n"
             . "Das Prüfportal hat diese IP automatisch gesperrt.\n";
    $from    = appMailFrom();
    $headers = "From: $from\r\nContent-Type: text/plain; charset=utf-8\r\n";
    @mail($to, $subject, $body, $headers);
}

function isIpBlocked(string $ip): bool
{
    try {
        $stmt = db()->prepare(
            'SELECT 1
             FROM login_blocks
             WHERE ip = :ip AND blocked_until > UTC_TIMESTAMP()
             LIMIT 1'
        );
        $stmt->execute([':ip' => $ip]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('[auth] login_blocks-Prüfung fehlgeschlagen: ' . $e->getMessage());
        return false;
    }
}

function recordLoginAttempt(string $ip, string $email, string $attemptType): void
{
    try {
        db()->prepare(
            'INSERT INTO login_attempts (ip, email, attempt_type, attempted_at)
             VALUES (:ip, :email, :attempt_type, UTC_TIMESTAMP())'
        )->execute([
            ':ip'           => $ip,
            ':email'        => $email,
            ':attempt_type' => $attemptType,
        ]);
    } catch (Throwable $e) {
        error_log('[auth] login_attempts-Eintrag fehlgeschlagen: ' . $e->getMessage());
    }
}

function countRecentLoginAttempts(string $ip, string $attemptType): int
{
    try {
        $stmt = db()->prepare(
            'SELECT COUNT(*)
             FROM login_attempts
             WHERE ip = :ip
               AND attempt_type = :attempt_type
               AND attempted_at >= (UTC_TIMESTAMP() - INTERVAL 15 MINUTE)'
        );
        $stmt->execute([
            ':ip'           => $ip,
            ':attempt_type' => $attemptType,
        ]);
        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('[auth] login_attempts-Zählung fehlgeschlagen: ' . $e->getMessage());
        return 0;
    }
}

function blockLoginIp(string $ip, string $email, string $reason): void
{
    try {
        db()->prepare(
            'INSERT INTO login_blocks (ip, blocked_until, block_reason, email, blocked_at)
             VALUES (:ip, UTC_TIMESTAMP() + INTERVAL 15 MINUTE, :block_reason, :email, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
               blocked_until = VALUES(blocked_until),
               block_reason  = VALUES(block_reason),
               email         = VALUES(email),
               blocked_at    = VALUES(blocked_at)'
        )->execute([
            ':ip'           => $ip,
            ':block_reason' => $reason,
            ':email'        => $email,
        ]);
    } catch (Throwable $e) {
        error_log('[auth] login_blocks-Sperre fehlgeschlagen: ' . $e->getMessage());
    }

    notifyAdminLoginBlock($ip, $email, $reason);
}

function clearLoginProtectionState(string $ip): void
{
    try {
        db()->prepare('DELETE FROM login_blocks WHERE ip = :ip')->execute([':ip' => $ip]);
        db()->prepare('DELETE FROM login_attempts WHERE ip = :ip')->execute([':ip' => $ip]);
    } catch (Throwable $e) {
        error_log('[auth] Login-Schutzdaten konnten nicht bereinigt werden: ' . $e->getMessage());
    }
}

function handleLogin(): never
{
    $body     = requestBody();
    $email    = trim((string) ($body['email'] ?? ''));
    $password = (string) ($body['password'] ?? '');
    $ip       = getClientIp();
    $blockedMessage = 'Zu viele Anmeldeversuche. Bitte in 15 Minuten erneut versuchen.';

    if (isIpBlocked($ip)) {
        apiError(429, $blockedMessage);
    }

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

    if (!$user) {
        // Konstante Antwortzeit gegen Timing-Angriffe (gültiger bcrypt-Hash eines Dummy-Passworts)
        password_verify('dummy', '$2y$12$invalidhashpadding000000000000000000000000000zAIlmfxXfhW');
        recordLoginAttempt($ip, $email, 'email_not_found');
        if (countRecentLoginAttempts($ip, 'email_not_found') >= 2) {
            blockLoginIp($ip, $email, 'E-Mail nicht gefunden');
            apiError(429, $blockedMessage);
        }
        apiError(401, 'Anmeldung fehlgeschlagen.');
    }

    if (!(bool) $user['is_active']) {
        // Konstante Antwortzeit gegen Timing-Angriffe (gültiger bcrypt-Hash eines Dummy-Passworts)
        password_verify('dummy', '$2y$12$invalidhashpadding000000000000000000000000000zAIlmfxXfhW');
        apiError(401, 'Anmeldung fehlgeschlagen.');
    }

    if (!password_verify($password, (string) $user['password_hash'])) {
        // Konstante Antwortzeit gegen Timing-Angriffe (gültiger bcrypt-Hash eines Dummy-Passworts)
        password_verify('dummy', '$2y$12$invalidhashpadding000000000000000000000000000zAIlmfxXfhW');
        recordLoginAttempt($ip, $email, 'wrong_password');
        if (countRecentLoginAttempts($ip, 'wrong_password') >= 3) {
            blockLoginIp($ip, $email, 'falsches Passwort');
            apiError(429, $blockedMessage);
        }
        apiError(401, 'Anmeldung fehlgeschlagen.');
    }

    clearLoginProtectionState($ip);

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
