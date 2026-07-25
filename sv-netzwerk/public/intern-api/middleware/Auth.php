<?php
declare(strict_types=1);

namespace SvIntern\Middleware;

use SvIntern\Config\Config;

/**
 * Session-Verwaltung und Authentifizierungspruefung.
 *
 * Sicherheitsmerkmale:
 * - HttpOnly, Secure, SameSite=Lax Cookies
 * - Session-ID-Rotation nach Login
 * - Inaktivitaets-Timeout
 * - Absolutes Session-Maximum
 * - Strict-Mode verhindert Session-Fixation
 */
final class Auth
{
    private const SESSION_NAME = 'sv_intern';

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $lifetimeMin  = Config::getInt('SESSION_LIFETIME_MINUTES', 480);
        $absoluteHours = Config::getInt('SESSION_ABSOLUTE_HOURS', 12);

        ini_set('session.cookie_secure',    '1');
        ini_set('session.cookie_httponly',   '1');
        ini_set('session.cookie_samesite',   'Lax');
        ini_set('session.use_strict_mode',   '1');
        ini_set('session.use_only_cookies',  '1');
        ini_set('session.gc_maxlifetime',    (string) ($lifetimeMin * 60));

        session_name(self::SESSION_NAME);
        session_start([
            'cookie_lifetime' => 0,          // Session-Cookie (kein persistentes Cookie)
            'cookie_path'     => '/',
        ]);

        // Inaktivitaets-Timeout
        if (isset($_SESSION['last_activity'])) {
            $inactive = time() - (int) $_SESSION['last_activity'];
            if ($inactive > $lifetimeMin * 60) {
                self::destroySession();
                return;
            }
        }

        // Absolutes Timeout
        if (isset($_SESSION['login_at'])) {
            $age = time() - (int) $_SESSION['login_at'];
            if ($age > $absoluteHours * 3600) {
                self::destroySession();
                return;
            }
        }

        if (isset($_SESSION['user_id'])) {
            $_SESSION['last_activity'] = time();
        }
    }

    /**
     * Gibt die aktuelle Session-Nutzerdaten zurueck oder null wenn nicht angemeldet.
     * @return array{user_id:string,user_email:string,user_name:string,user_role:string,csrf_token:string}|null
     */
    public static function getSession(): ?array
    {
        self::startSession();

        if (!isset($_SESSION['user_id'], $_SESSION['user_email'], $_SESSION['user_role'])) {
            return null;
        }

        return [
            'user_id'    => (string) $_SESSION['user_id'],
            'user_email' => (string) $_SESSION['user_email'],
            'user_name'  => (string) ($_SESSION['user_name'] ?? ''),
            'user_role'  => (string) $_SESSION['user_role'],
            'csrf_token' => (string) ($_SESSION['csrf_token'] ?? ''),
        ];
    }

    /**
     * Legt eine neue authentifizierte Session an.
     * Rotiert die Session-ID um Session-Fixation zu verhindern.
     */
    public static function createSession(
        string $userId,
        string $email,
        string $name,
        string $role,
    ): string {
        self::startSession();

        // Session-ID rotieren
        session_regenerate_id(true);

        $csrfToken = bin2hex(random_bytes(32));

        $_SESSION['user_id']       = $userId;
        $_SESSION['user_email']    = $email;
        $_SESSION['user_name']     = $name;
        $_SESSION['user_role']     = $role;
        $_SESSION['csrf_token']    = $csrfToken;
        $_SESSION['login_at']      = time();
        $_SESSION['last_activity'] = time();

        return $csrfToken;
    }

    /**
     * Beendet die aktuelle Session sicher.
     */
    public static function destroySession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                [
                    'expires'  => time() - 3600,
                    'path'     => $params['path'],
                    'domain'   => $params['domain'],
                    'secure'   => true,
                    'httponly' => true,
                    'samesite' => 'Lax',
                ]
            );
            session_destroy();
        }
    }

    /**
     * Middleware: Prueft Authentifizierung, bricht mit 401 ab wenn nicht angemeldet.
     * @return array{user_id:string,user_email:string,user_name:string,user_role:string,csrf_token:string}
     */
    public static function require(): array
    {
        $session = self::getSession();
        if ($session === null) {
            \jsonError('Nicht angemeldet.', 401);
        }
        return $session;
    }
}
