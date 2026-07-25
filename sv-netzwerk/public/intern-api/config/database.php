<?php
declare(strict_types=1);

namespace SvIntern\Config;

/**
 * Lazily-initialised PDO-Singleton fuer MySQL 8.0.
 * Verbindungsparameter kommen ausschliesslich aus der .env-Datei.
 */
final class Database
{
    private static ?\PDO $instance = null;

    public static function getInstance(): \PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $host = Config::getRequired('DB_HOST');
        $port = Config::getInt('DB_PORT', 3306);
        $name = Config::getRequired('DB_NAME');
        $user = Config::getRequired('DB_USER');
        $pass = Config::getRequired('DB_PASSWORD');

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

        self::$instance = new \PDO($dsn, $user, $pass, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => false,
            \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+00:00'",
        ]);

        return self::$instance;
    }

    /** Gibt true zurueck wenn die Datenbankverbindung hergestellt werden kann. */
    public static function isReachable(): bool
    {
        try {
            self::getInstance()->query('SELECT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    // Kein Clone, kein Deserialisieren
    private function __clone() {}
}
