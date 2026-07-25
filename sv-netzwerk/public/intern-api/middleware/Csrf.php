<?php
declare(strict_types=1);

namespace SvIntern\Middleware;

/**
 * CSRF-Schutz mit synchronisiertem Token (im Session gespeichert).
 *
 * Das Token wird als X-CSRF-Token HTTP-Header oder als _csrf-POST-Feld erwartet.
 * Das Double-Submit-Cookie-Pattern wird NICHT verwendet, da es bei Subdomain-Angriffen
 * angreifbar ist. Das serverseitige Synchronisierungstoken ist die sicherere Variante.
 */
final class Csrf
{
    private const HEADER_NAME = 'HTTP_X_CSRF_TOKEN';
    private const FIELD_NAME  = '_csrf';

    /**
     * Validiert das CSRF-Token der aktuellen Anfrage.
     * Bricht mit 403 ab wenn das Token fehlt oder ungueltig ist.
     *
     * @param array<string,string> $session
     */
    public static function validate(array $session): void
    {
        $expected = $session['csrf_token'] ?? '';
        if ($expected === '') {
            \jsonError('CSRF-Token fehlt in der Session.', 403);
        }

        $received = $_SERVER[self::HEADER_NAME]
            ?? $_POST[self::FIELD_NAME]
            ?? '';

        if (!hash_equals($expected, (string) $received)) {
            \jsonError('Ungueltige oder fehlende CSRF-Token.', 403);
        }
    }
}
