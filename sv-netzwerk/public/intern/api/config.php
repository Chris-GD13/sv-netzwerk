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
require_once __DIR__ . '/case-search.php';

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

        // projects.created_by_user_id – ownership controls destructive project actions.
        $stmt->execute([':table' => 'projects', ':column' => 'created_by_user_id']);
        if ((int) $stmt->fetchColumn() === 0) {
            $pdo->exec('ALTER TABLE projects ADD COLUMN created_by_user_id INT UNSIGNED NULL AFTER is_active');
            $pdo->exec('ALTER TABLE projects ADD INDEX idx_projects_created_by (created_by_user_id)');
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS case_folder_owners (
            folder_id VARCHAR(190) PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            user_email VARCHAR(255) NOT NULL,
            registered_at DATETIME NOT NULL,
            KEY idx_case_owner_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        foreach ([
            'case_no' => 'VARCHAR(190) NULL',
            'policy_no' => 'VARCHAR(190) NULL',
            'object_name' => 'VARCHAR(500) NULL',
            'damage_type' => 'VARCHAR(500) NULL',
            'case_type' => 'VARCHAR(190) NULL',
            'folder_name' => 'VARCHAR(500) NULL',
            'modified_time' => 'VARCHAR(50) NULL',
            'web_view_link' => 'VARCHAR(1000) NULL',
            'search_text' => 'TEXT NULL',
            'meta_json' => 'MEDIUMTEXT NULL',
        ] as $column => $definition) {
            $stmt->execute([':table' => 'case_folder_owners', ':column' => $column]);
            if ((int) $stmt->fetchColumn() === 0) {
                $pdo->exec("ALTER TABLE case_folder_owners ADD COLUMN {$column} {$definition}");
            }
        }
    } catch (Throwable $e) {
        error_log('[config] Runtime-Migration fehlgeschlagen: ' . $e->getMessage());
    }
}

/** Shared projects remain visible, but only administrators or their creator may delete them. */
function canDeleteProject(array $user, int $projectId): bool
{
    if (($user['role'] ?? '') === 'administrator') return true;
    if ($projectId <= 0 || empty($user['id'])) return false;
    $stmt = db()->prepare('SELECT created_by_user_id FROM projects WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $projectId]);
    $owner = $stmt->fetchColumn();
    return $owner !== false && $owner !== null && (int)$owner === (int)$user['id'];
}

function requireProjectDeleteAccess(array $user, int $projectId): void
{
    if (!canDeleteProject($user, $projectId)) {
        apiError(403, 'Dieses gemeinsame Projekt darf nur angesehen und bearbeitet, aber nicht gelöscht werden.');
    }
}

function registerCaseFolderOwner(string $folderId, array $user, array $meta = [], array $folder = []): void
{
    if ($folderId === '' || empty($user['id'])) return;
    $folderName = trim((string)($folder['name'] ?? ''));
    $searchText = caseSearchText($meta, $folderName);
    $metaJson = $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    $stmt = db()->prepare('INSERT INTO case_folder_owners(folder_id,user_id,user_email,case_no,policy_no,object_name,damage_type,case_type,folder_name,modified_time,web_view_link,search_text,meta_json,registered_at)
        VALUES(:f,:u,:e,:case_no,:policy_no,:object_name,:damage_type,:case_type,:folder_name,:modified_time,:web_view_link,:search_text,:meta_json,NOW())
        ON DUPLICATE KEY UPDATE user_id=VALUES(user_id),user_email=VALUES(user_email),
        case_no=COALESCE(NULLIF(VALUES(case_no),\'\'),case_no),policy_no=COALESCE(NULLIF(VALUES(policy_no),\'\'),policy_no),
        object_name=COALESCE(NULLIF(VALUES(object_name),\'\'),object_name),damage_type=COALESCE(NULLIF(VALUES(damage_type),\'\'),damage_type),
        case_type=COALESCE(NULLIF(VALUES(case_type),\'\'),case_type),folder_name=COALESCE(NULLIF(VALUES(folder_name),\'\'),folder_name),
        modified_time=COALESCE(NULLIF(VALUES(modified_time),\'\'),modified_time),web_view_link=COALESCE(NULLIF(VALUES(web_view_link),\'\'),web_view_link),
        search_text=IF(meta_json IS NOT NULL AND VALUES(meta_json) IS NULL,search_text,COALESCE(NULLIF(VALUES(search_text),\'\'),search_text)),
        meta_json=COALESCE(VALUES(meta_json),meta_json),registered_at=NOW()');
    $stmt->execute([
        ':f'=>$folderId, ':u'=>(int)$user['id'], ':e'=>(string)($user['email']??''),
        ':case_no'=>(string)($meta['schaden_nr']??''),
        ':policy_no'=>(string)($meta['versicherungsschein_nr']??''),
        ':object_name'=>(string)($meta['vn_objekt']??''),
        ':damage_type'=>(string)($meta['schadenart']??''),
        ':case_type'=>(string)($meta['fallart']??''),
        ':folder_name'=>$folderName,
        ':modified_time'=>(string)($folder['modifiedTime']??''),
        ':web_view_link'=>(string)($folder['webViewLink']??''),
        ':search_text'=>$searchText,
        ':meta_json'=>$metaJson,
    ]);
}

function searchCaseFolderIndex(array $user, string $query, int $limit = 30): array
{
    if (empty($user['id']) || caseSearchNormalize($query) === '') return [];
    $terms = array_slice(caseSearchTerms($query), 0, 6);
    $where = ['user_id = :user_id', 'user_email = :user_email'];
    $params = [':user_id' => (int)$user['id'], ':user_email' => (string)($user['email'] ?? '')];
    foreach ($terms as $index => $term) {
        $key = ':term_' . $index;
        $where[] = "search_text LIKE {$key}";
        $params[$key] = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
    }
    $sql = 'SELECT folder_id,folder_name,modified_time,web_view_link,search_text,meta_json FROM case_folder_owners WHERE '
        . implode(' AND ', $where) . ' ORDER BY registered_at DESC LIMIT ' . max(1, min(100, $limit));
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $results = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $meta = json_decode((string)($row['meta_json'] ?? ''), true);
        $results[] = [
            'id' => (string)$row['folder_id'],
            'name' => (string)($row['folder_name'] ?: ($meta['schaden_nr'] ?? 'Versicherungsfall')),
            'modifiedTime' => $row['modified_time'] ?: null,
            'webViewLink' => $row['web_view_link'] ?: null,
            'meta' => is_array($meta) ? $meta : [],
            '_search_text' => (string)($row['search_text'] ?? ''),
        ];
    }
    return $results;
}

function requireCaseFolderAccess(string $folderId, array $user): void
{
    if ($folderId === '') apiError(400, 'Kein aktiver Fallordner.');
    $stmt = db()->prepare('SELECT user_id FROM case_folder_owners WHERE folder_id=:f LIMIT 1');
    $stmt->execute([':f'=>$folderId]);
    $owner = $stmt->fetchColumn();
    if ($owner === false || (int)$owner !== (int)($user['id']??0)) {
        apiError(403, 'Dieser Schadenfall gehört zu einem anderen Benutzer und darf nicht geöffnet oder verändert werden.');
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
function appMailFrom(): string      { return env('MAIL_FROM', 'ws@sv-schuett.eu'); }
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
        // Der Import verwendet an mehreren Stellen denselben benannten Platzhalter
        // mehrfach in einer Anweisung (z. B. :now für created_at und updated_at).
        // Native MySQL-Prepares unterstützen das nicht zuverlässig und werfen HY093.
        PDO::ATTR_EMULATE_PREPARES   => true,
    ]);
    $pdo->exec("SET time_zone='+00:00'");
    ensureRuntimeSchema($pdo);
    return $pdo;
}

/** Pfad für Foto-Uploads. */
function photosDir(): string
{
    $configured = env('PHOTOS_DIR', '');
    if ($configured !== '') {
        $persistentDir = rtrim($configured, '/');
        if ((is_dir($persistentDir) || @mkdir($persistentDir, 0775, true)) && is_writable($persistentDir)) {
            return $persistentDir;
        }
        apiError(503, 'Das konfigurierte dauerhafte Foto-Verzeichnis ist nicht verfügbar oder nicht beschreibbar.');
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
