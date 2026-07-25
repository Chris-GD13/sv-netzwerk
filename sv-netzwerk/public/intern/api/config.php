<?php
/**
 * Konfiguration – Fensterbeschlagsprüfung BMVg Bonn
 *
 * Lädt Datenbankverbindung und Anwendungseinstellungen aus der .env-Datei
 * oder Umgebungsvariablen. Keine Zugangsdaten im Quellcode.
 */

declare(strict_types=1);

define('APP_NAME', 'Fensterbeschlagsprüfung BMVg Bonn');
define('APP_VERSION', '1.0.0');
define('DEFAULT_PROJECT_ID', 1);

/** Lädt Schlüssel-Wert-Paare aus der .env-Datei im Stammverzeichnis. */
function loadEnv(): void
{
    $paths = [
        dirname(__DIR__, 5) . '/.env',
        dirname(__DIR__, 4) . '/.env',
        dirname(__DIR__, 3) . '/.env',
    ];
    foreach ($paths as $path) {
        if (!is_file($path)) {
            continue;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value, " \t\n\r\0\x0B\"'");
            if ($key !== '' && !isset($_ENV[$key])) {
                $_ENV[$key] = $value;
                putenv("$key=$value");
            }
        }
        return;
    }
}

loadEnv();

function env(string $key, string $default = ''): string
{
    return $_ENV[$key] ?? getenv($key) ?: $default;
}

/** Gibt eine PDO-Datenbankverbindung zurück (lazy singleton). */
function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $host    = env('DB_HOST', 'localhost');
    $port    = env('DB_PORT', '3306');
    $name    = env('DB_NAME', '');
    $user    = env('DB_USER', '');
    $pass    = env('DB_PASS', '');
    $charset = 'utf8mb4';

    if ($name === '' || $user === '') {
        apiError(503, 'Datenbankverbindung nicht konfiguriert. Bitte .env einrichten.');
    }

    $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=$charset";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec("SET time_zone='+00:00'");
    return $pdo;
}

/** Pfad für Foto-Uploads. */
function photosDir(): string
{
    $configured = env('PHOTOS_DIR', '');
    if ($configured !== '' && is_dir($configured)) {
        return rtrim($configured, '/');
    }
    return dirname(__DIR__) . '/photos';
}

/** Gibt eine JSON-Fehlerantwort aus und beendet die Ausführung. */
function apiError(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Gibt eine JSON-Erfolgsantwort aus. */
function apiJson(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Liest den JSON-Anfrage-Body. */
function requestBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

/** Gibt die aktuelle UTC-Zeit als MySQL-DATETIME-String zurück. */
function nowUtc(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
}

/** Setzt gemeinsame HTTP-Header (CORS nur für gleiche Herkunft, CSP). */
function commonHeaders(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Cache-Control: no-store');
}

/** Initialisiert eine sichere Session. */
function startSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

/** Gibt den aktuell angemeldeten Benutzer zurück oder null. */
function currentUser(): ?array
{
    startSession();
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    try {
        $stmt = db()->prepare(
            'SELECT u.id, u.email, u.full_name, u.role, u.is_active
             FROM users u
             WHERE u.id = :id AND u.is_active = 1'
        );
        $stmt->execute([':id' => $_SESSION['user_id']]);
        $user = $stmt->fetch();
        return $user ?: null;
    } catch (Throwable) {
        return null;
    }
}

/** Erfordert eine aktive Anmeldung; bricht sonst mit 401 ab. */
function requireAuth(): array
{
    $user = currentUser();
    if ($user === null) {
        apiError(401, 'Nicht angemeldet.');
    }
    return $user;
}
