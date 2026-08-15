<?php
/**
 * Konfiguration – SV-Netzwerk Prüfportal
 *
 * Lädt Datenbankverbindung und Anwendungseinstellungen aus der .env-Datei
 * oder Umgebungsvariablen. Keine Zugangsdaten im Quellcode.
 */

declare(strict_types=1);

define('APP_VERSION', '1.0.0');
define('DEFAULT_PROJECT_ID', 1);

function ensureRuntimeSchema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table
               AND COLUMN_NAME = :column'
        );

        $stmt->execute([':table' => 'users', ':column' => 'last_seen_at']);
        if ((int) $stmt->fetchColumn() === 0) {
            $pdo->exec('ALTER TABLE users ADD COLUMN last_seen_at DATETIME NULL AFTER last_login_at');
            $pdo->exec('ALTER TABLE users ADD INDEX idx_users_last_seen (last_seen_at)');
        }

        // windows.room_id – added after initial deployment; required for building-hierarchy queries and Excel import
        $stmt->execute([':table' => 'windows', ':column' => 'room_id']);
        if ((int) $stmt->fetchColumn() === 0) {
            $pdo->exec('ALTER TABLE windows ADD COLUMN room_id INT UNSIGNED NULL AFTER project_id');
        }

        // photos.sash_id – added after initial deployment; required for sash-level photo assignment
        $stmt->execute([':table' => 'photos', ':column' => 'sash_id']);
        if ((int) $stmt->fetchColumn() === 0) {
            $pdo->exec('ALTER TABLE photos ADD COLUMN sash_id INT UNSIGNED NULL AFTER window_id');
        }
    } catch (Throwable $e) {
        error_log('[config] Runtime-Migration fehlgeschlagen: ' . $e->getMessage());
    }
}

/** Lädt Schlüssel-Wert-Paare aus der .env-Datei im Stammverzeichnis. */
function loadEnv(): void
{
    $paths = [
        __DIR__ . '/.env',
        dirname(__DIR__, 1) . '/.env',
        dirname(__DIR__, 2) . '/.env',
        dirname(__DIR__, 3) . '/.env',
        dirname(__DIR__, 4) . '/.env',
        dirname(__DIR__, 5) . '/.env',
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
    if (isset($_ENV[$key])) {
        return (string) $_ENV[$key];
    }
    $val = getenv($key);
    return $val !== false ? $val : $default;
}

/** Gibt den konfigurierten Projektnamen zurück. */
function appProjectName(): string   { return env('PROJECT_NAME', 'SV-Netzwerk Prüfportal'); }
/** Gibt den Auftraggeber zurück. */
function appClientName(): string    { return env('CLIENT_NAME', 'Bundesministerium der Verteidigung'); }
/** Gibt den Auftragnehmer zurück. */
function appCompanyName(): string   { return env('COMPANY_NAME', 'SV-Büro Marc Schütt e.K.'); }
/** Gibt die Absenderadresse für E-Mail-Benachrichtigungen zurück. */
function appMailFrom(): string      { return env('MAIL_FROM', 'noreply@sv-schuett.eu'); }
/** Gibt die Administratoren-E-Mail zurück. */
function appMailAdmin(): string     { return env('MAIL_ADMIN', 'admin@sv-schuett.eu'); }

/** Erstellt einen sicheren Passwort-Hash (Argon2ID). */
function hashPassword(string $password): string
{
    return password_hash($password, PASSWORD_ARGON2ID);
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
    ensureRuntimeSchema($pdo);
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

/** Schreibt die in alten Excel-Listen verwendeten Prüfkuerzel verständlich aus. */
function expandInspectionAbbreviations(string $text): string
{
    $labels = [
        'MEG' => 'muss eingestellt werden',
        'WA'  => 'Wartung notwendig',
        'SOK' => 'sonst OK',
        'SC'  => 'schleift',
        'SB'  => 'Scheibe',
        'BE'  => 'Beschlag defekt',
    ];
    $expanded = preg_replace_callback('/\\b(MEG|WA|SOK|SC|SB|BE)\\b/iu', static function (array $match) use ($labels): string {
        return $labels[strtoupper($match[1])] ?? $match[0];
    }, $text) ?? $text;

    return autoCorrectInspectionText($expanded);
}

/** Bereinigt typische Schreib- und Zeichensetzungsfehler aus Excel-Freitexten. */
function autoCorrectInspectionText(string $text): string
{
    $corrected = trim($text);
    $corrected = preg_replace('/\\s+/u', ' ', $corrected) ?? $corrected;
    $corrected = preg_replace('/\\s+([,.;:!?])/u', '$1', $corrected) ?? $corrected;
    $corrected = preg_replace('/([,.;:!?])(?!\\s|$)/u', '$1 ', $corrected) ?? $corrected;

    $typos = [
        '/\\beingestelt\\b/iu' => 'eingestellt',
        '/\\beingestelllt\\b/iu' => 'eingestellt',
        '/\\bschliest\\b/iu' => 'schließt',
        '/\\bschliesst\\b/iu' => 'schließt',
        '/\\bnicht\\s+zugaenglich\\b/iu' => 'nicht zugänglich',
        '/\\bpruefung\\b/iu' => 'Prüfung',
    ];
    foreach ($typos as $pattern => $replacement) {
        $corrected = preg_replace($pattern, $replacement, $corrected) ?? $corrected;
    }

    if ($corrected !== '') {
        $corrected = mb_strtoupper(mb_substr($corrected, 0, 1), 'UTF-8') . mb_substr($corrected, 1, null, 'UTF-8');
    }
    return $corrected;
}

function normalizeInspectionFormRemarks(array $data): array
{
    foreach (['visible_special_features', 'expert_note', 'recommended_action', 'accessibility_note'] as $field) {
        if (isset($data[$field]) && is_string($data[$field])) {
            $data[$field] = expandInspectionAbbreviations($data[$field]);
        }
    }
    return $data;
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
    // Fotoimporte können bei mehreren hundert Bildern deutlich länger als
    // die PHP-Standardlaufzeit von häufig 24 Minuten dauern. Der Auth-Poll
    // erneuert die Aktivität jede Minute; eine aktive Prüfung bleibt dadurch
    // angemeldet, ohne eine dauerhafte Browser-Cookie-Sitzung zu erzeugen.
    $sessionLifetime = (int) env('PORTAL_SESSION_LIFETIME_SECONDS', '28800');
    $sessionLifetime = max(1800, min(86400, $sessionLifetime));
    ini_set('session.gc_maxlifetime', (string) $sessionLifetime);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
    if (!empty($_SESSION['user_id'])) {
        // Eine echte Änderung verhindert, dass session.lazy_write den
        // Sitzungszeitstempel trotz erfolgreichem Keepalive unverändert lässt.
        $_SESSION['last_activity_at'] = time();
    }
}

function touchCurrentUserPresence(int $userId): void
{
    startSession();
    $now = time();
    $lastTouched = (int) ($_SESSION['presence_touched_at'] ?? 0);
    if ($lastTouched > 0 && ($now - $lastTouched) < 60) {
        return;
    }

    $_SESSION['presence_touched_at'] = $now;

    try {
        db()->prepare('UPDATE users SET last_seen_at = :now WHERE id = :id')
            ->execute([':now' => nowUtc(), ':id' => $userId]);
    } catch (Throwable $e) {
        error_log('[config] last_seen_at konnte nicht aktualisiert werden: ' . $e->getMessage());
    }
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
        if ($user) {
            touchCurrentUserPresence((int) $user['id']);
        }
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
