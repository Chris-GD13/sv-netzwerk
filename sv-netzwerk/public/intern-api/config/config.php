<?php
declare(strict_types=1);

namespace SvIntern\Config;

/**
 * Liest Konfigurationswerte aus der .env-Datei und der Serverumgebung.
 * Keine Credentials werden committet oder geloggt.
 */
final class Config
{
    private static array $values = [];
    private static bool $loaded = false;

    public static function load(): void
    {
        if (self::$loaded) {
            return;
        }

        // .env im selben Verzeichnis wie die PHP-Dateien (intern-api/)
        $envFile = dirname(__DIR__) . '/.env';
        if (is_readable($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                // Strip optional surrounding quotes
                if (
                    (str_starts_with($value, '"') && str_ends_with($value, '"')) ||
                    (str_starts_with($value, "'") && str_ends_with($value, "'"))
                ) {
                    $value = substr($value, 1, -1);
                }
                self::$values[$key] = $value;
            }
        }

        self::$loaded = true;
    }

    public static function get(string $key, string $default = ''): string
    {
        self::load();
        return self::$values[$key]
            ?? (($_ENV[$key] ?? null) !== null ? (string) $_ENV[$key] : null)
            ?? (getenv($key) !== false ? (string) getenv($key) : null)
            ?? $default;
    }

    public static function getRequired(string $key): string
    {
        $value = self::get($key);
        if ($value === '') {
            throw new \RuntimeException(
                "Pflicht-Konfiguration '{$key}' fehlt. Bitte .env pruefen."
            );
        }
        return $value;
    }

    public static function getInt(string $key, int $default = 0): int
    {
        $v = self::get($key);
        return $v !== '' ? (int) $v : $default;
    }

    public static function isProduction(): bool
    {
        return strtolower(self::get('APP_ENV', 'production')) === 'production';
    }

    /** Gibt an ob die Datenbankkonfiguration vollstaendig ist. */
    public static function isDatabaseConfigured(): bool
    {
        return self::get('DB_HOST') !== ''
            && self::get('DB_NAME') !== ''
            && self::get('DB_USER') !== ''
            && self::get('DB_PASSWORD') !== '';
    }
}
