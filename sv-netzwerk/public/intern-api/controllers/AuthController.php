<?php
declare(strict_types=1);

namespace SvIntern\Controllers;

use SvIntern\Middleware\Auth;
use SvIntern\Models\User;
use SvIntern\Models\AuditLog;
use SvIntern\Services\AuthService;

final class AuthController
{
    /**
     * POST /intern-api/auth/login
     * Kein CSRF erforderlich (kein aktiver Session-Kontext vor Login).
     */
    public static function login(?array $session): never
    {
        if ($session !== null) {
            \jsonResponse(['user' => self::sessionUser($session), 'csrf_token' => $session['csrf_token']]);
        }

        $body     = \readJsonBody();
        $email    = trim((string) ($body['email'] ?? ''));
        $password = (string) ($body['password'] ?? '');

        // Generische Fehlermeldung – keine Hinweise auf Existenz von E-Mail
        if ($email === '' || $password === '') {
            \jsonError('E-Mail oder Passwort ungueltig.', 401);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            \jsonError('E-Mail oder Passwort ungueltig.', 401);
        }

        $db          = \SvIntern\Config\Database::getInstance();
        $userModel   = new User($db);
        $authService = new AuthService($userModel);
        $ip          = \clientIp();

        $user = $authService->attemptLogin($email, $password, $ip);

        if ($user === null) {
            \jsonError('E-Mail oder Passwort ungueltig.', 401);
        }

        $csrfToken = Auth::createSession(
            userId: $user['id'],
            email:  $user['email'],
            name:   $user['full_name'],
            role:   $user['role'],
        );

        // Audit-Log
        $auditLog = new AuditLog($db);
        $auditLog->log(
            actorId:    $user['id'],
            actorName:  $user['full_name'],
            actionType: 'login',
            entityType: 'session',
            entityId:   $user['id'],
            ip:         $ip,
        );

        \jsonResponse([
            'user'       => [
                'id'   => $user['id'],
                'email' => $user['email'],
                'name'  => $user['full_name'],
                'role'  => $user['role'],
            ],
            'csrf_token' => $csrfToken,
        ]);
    }

    /**
     * GET /intern-api/auth/session
     * Gibt aktuelle Session-Daten zurueck (fuer Frontend-Initialisierung).
     */
    public static function session(?array $session): never
    {
        if ($session === null) {
            \jsonError('Nicht angemeldet.', 401);
        }
        \jsonResponse(['user' => self::sessionUser($session), 'csrf_token' => $session['csrf_token']]);
    }

    /**
     * POST /intern-api/auth/logout
     */
    public static function logout(?array $session): never
    {
        if ($session !== null) {
            $db       = \SvIntern\Config\Database::getInstance();
            $auditLog = new AuditLog($db);
            $auditLog->log(
                actorId:    $session['user_id'],
                actorName:  $session['user_name'],
                actionType: 'logout',
                entityType: 'session',
                entityId:   $session['user_id'],
                ip:         \clientIp(),
            );
        }
        Auth::destroySession();
        \jsonResponse(['ok' => true]);
    }

    private static function sessionUser(array $session): array
    {
        return [
            'id'   => $session['user_id'],
            'email' => $session['user_email'],
            'name'  => $session['user_name'],
            'role'  => $session['user_role'],
        ];
    }
}
