<?php
declare(strict_types=1);

namespace SvIntern\Middleware;

/**
 * Rollenbasierte Zugriffskontrolle.
 * Alle Pruefungen sind server-seitig und nicht umgehbar.
 */
final class Role
{
    public const ADMINISTRATOR = 'administrator';
    public const PRUEFER       = 'pruefer';
    public const AUSWERTUNG    = 'auswertung';

    private const HIERARCHY = [
        self::ADMINISTRATOR => 3,
        self::PRUEFER       => 2,
        self::AUSWERTUNG    => 1,
    ];

    /**
     * Prueft ob die Rolle mindestens die angegebene Stufe hat.
     * @param array<string,string> $session
     */
    public static function atLeast(array $session, string $minRole): bool
    {
        $current = self::HIERARCHY[$session['user_role']] ?? 0;
        $required = self::HIERARCHY[$minRole] ?? 99;
        return $current >= $required;
    }

    /**
     * Middleware: Bricht mit 403 ab wenn Rolle nicht ausreicht.
     * @param array<string,string> $session
     */
    public static function require(array $session, string $minRole): void
    {
        if (!self::atLeast($session, $minRole)) {
            \jsonError('Nicht autorisiert. Erforderliche Rolle: ' . $minRole . '.', 403);
        }
    }

    /**
     * Prueft ob der Nutzer Administrator ist.
     * @param array<string,string> $session
     */
    public static function isAdmin(array $session): bool
    {
        return $session['user_role'] === self::ADMINISTRATOR;
    }

    /**
     * Prueft ob der Nutzer Fenster bearbeiten darf (Pruefer oder Admin).
     * @param array<string,string> $session
     */
    public static function canEdit(array $session): bool
    {
        return in_array($session['user_role'], [self::ADMINISTRATOR, self::PRUEFER], true);
    }
}
