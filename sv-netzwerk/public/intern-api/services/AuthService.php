<?php
declare(strict_types=1);

namespace SvIntern\Services;

use SvIntern\Models\User;

/**
 * Authentifizierungslogik: Login, Rate-Limiting, Passwort-Hashing.
 */
final class AuthService
{
    private const MAX_FAILURES    = 5;
    private const BLOCK_MINUTES   = 15;

    public function __construct(private readonly User $userModel) {}

    /**
     * Versucht einen Login und gibt Nutzerdaten oder null zurueck.
     * Fuehrt Rate-Limiting durch und protokolliert Versuche.
     *
     * @return array{id:string,email:string,full_name:string,role:string}|null
     */
    public function attemptLogin(string $email, string $password, string $ip): ?array
    {
        // Rate-Limiting: nach MAX_FAILURES Fehlversuchen in BLOCK_MINUTES abweisen
        $failures = $this->userModel->countRecentFailures($ip, self::BLOCK_MINUTES);
        if ($failures >= self::MAX_FAILURES) {
            // Weder Erfolg noch Fehler preisgeben – generic error response
            $this->userModel->recordLoginAttempt($email, $ip, false);
            return null;
        }

        $user = $this->userModel->findByEmail($email);

        // Passwort immer pruefen (auch wenn kein User gefunden), um Timing-Angriffe zu erschweren
        $dummyHash = '$argon2id$v=19$m=65536,t=4,p=1$dummy$dummy';
        $hash      = $user['password_hash'] ?? $dummyHash;

        if (!password_verify($password, $hash) || $user === null) {
            $this->userModel->recordLoginAttempt($email, $ip, false);
            return null;
        }

        $this->userModel->recordLoginAttempt($email, $ip, true);

        // Passwort-Rehash falls Algorithmus geaendert wurde
        if (password_needs_rehash($user['password_hash'], PASSWORD_ARGON2ID)) {
            $newHash = $this->hashPassword($password);
            $this->userModel->update($user['id'], $user['full_name'], $user['role'], $newHash);
        }

        return [
            'id'        => $user['id'],
            'email'     => $user['email'],
            'full_name' => $user['full_name'],
            'role'      => $user['role'],
        ];
    }

    /**
     * Erzeugt einen sicheren Argon2ID-Passwort-Hash (PHP 8.4, Argon2ID empfohlen).
     */
    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => 65536,  // 64 MB
            'time_cost'   => 4,
            'threads'     => 1,
        ]);
    }

    /**
     * Validiert ein Passwort gegen Mindestanforderungen.
     * @return string[] Liste von Fehlermeldungen (leer = OK)
     */
    public static function validatePasswordStrength(string $password): array
    {
        $errors = [];
        if (strlen($password) < 12) {
            $errors[] = 'Passwort muss mindestens 12 Zeichen lang sein.';
        }
        if (!preg_match('/[A-Z]/', $password)) {
            $errors[] = 'Passwort muss mindestens einen Grossbuchstaben enthalten.';
        }
        if (!preg_match('/[a-z]/', $password)) {
            $errors[] = 'Passwort muss mindestens einen Kleinbuchstaben enthalten.';
        }
        if (!preg_match('/[0-9]/', $password)) {
            $errors[] = 'Passwort muss mindestens eine Ziffer enthalten.';
        }
        return $errors;
    }
}
