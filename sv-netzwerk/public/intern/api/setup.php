<?php
/**
 * Einrichtungsassistent – SV-Netzwerk Prüfportal
 *
 * Erreichbar unter: /intern/api/setup.php
 * Führt Datenbankinitialisierung und Administratoranlage durch.
 * Nach erfolgreicher Einrichtung sollte der Zugriff auf diese Datei
 * durch Umbenennung oder .htaccess-Sperrung gesichert werden.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

commonHeaders();

$setupKey = env('SETUP_KEY', '');
$provided = $_GET['key'] ?? $_POST['key'] ?? '';

// SETUP_KEY ist verpflichtend – ohne gültigen Schlüssel kein Zugriff
if ($setupKey === '') {
    apiError(503, 'SETUP_KEY ist nicht konfiguriert. Bitte .env einrichten.');
}
if (!hash_equals($setupKey, $provided)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Ungültiger Einrichtungsschlüssel.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    handleStatus();
} elseif ($method === 'POST') {
    $action = $_GET['action'] ?? requestBody()['action'] ?? '';
    match ($action) {
        'install'         => handleInstall(),
        'create_admin'    => handleCreateAdmin(),
        'seed_reference'  => handleSeedReference(),
        'fix_titles'      => handleFixTitles(),
        default           => apiError(400, 'Unbekannte Aktion.'),
    };
} else {
    apiError(405, 'Methode nicht erlaubt.');
}

function handleStatus(): never
{
    $status = [
        'app'       => appProjectName(),
        'version'   => APP_VERSION,
        'db_ok'     => false,
        'schema_ok' => false,
        'admin_ok'  => false,
    ];

    try {
        db()->query('SELECT 1');
        $status['db_ok'] = true;
    } catch (Throwable $e) {
        error_log('[setup] DB-Verbindungsfehler: ' . $e->getMessage());
        $status['db_error'] = 'Datenbankverbindung fehlgeschlagen.';
        apiJson($status);
    }

    try {
        $tables = db()->query("SHOW TABLES LIKE 'users'")->fetchAll();
        $status['schema_ok'] = count($tables) > 0;
    } catch (Throwable) {}

    try {
        if ($status['schema_ok']) {
            $count = db()->query("SELECT COUNT(*) FROM users WHERE role = 'administrator'")->fetchColumn();
            $status['admin_ok'] = (int) $count > 0;
        }
    } catch (Throwable) {}

    apiJson($status);
}

function handleInstall(): never
{
    $sql = @file_get_contents(__DIR__ . '/schema.sql');
    if ($sql === false) {
        apiError(500, 'schema.sql nicht gefunden.');
    }

    // Kommentarzeilen entfernen, dann Statements aufteilen
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        static fn($s) => $s !== ''
    );

    $errors = [];
    foreach ($statements as $statement) {
        if (trim($statement) === '') {
            continue;
        }
        try {
            db()->exec($statement);
        } catch (Throwable $e) {
            // Ignoriere "already exists"-Fehler
            if (str_contains($e->getMessage(), 'already exists') || str_contains($e->getMessage(), 'Duplicate entry')) {
                continue;
            }
            error_log('[setup] SQL-Fehler: ' . substr($statement, 0, 120) . ' → ' . $e->getMessage());
            $errors[] = substr($statement, 0, 80) . ' → ' . $e->getMessage();
        }
    }

    if ($errors) {
        apiJson(['ok' => false, 'errors' => $errors], 207);
    }

    // Migrations: Spalten nachrüsten (Fehler bei "Duplicate column" ignorieren)
    $migrations = [
        'ALTER TABLE windows ADD COLUMN room_id INT UNSIGNED NULL AFTER project_id',
        'ALTER TABLE photos ADD COLUMN sash_id INT UNSIGNED NULL AFTER window_id',
    ];
    foreach ($migrations as $mig) {
        try {
            db()->exec($mig);
        } catch (Throwable $e) {
            if (!str_contains($e->getMessage(), 'Duplicate column')) {
                error_log('[setup] Migration-Hinweis: ' . $e->getMessage());
            }
        }
    }

    apiJson(['ok' => true, 'message' => 'Datenbankschema erfolgreich eingerichtet.']);
}

function handleCreateAdmin(): never
{
    $body     = requestBody();
    $email    = trim((string) ($body['email']     ?? ''));
    $name     = trim((string) ($body['full_name'] ?? ''));
    $password = (string) ($body['password'] ?? '');

    if ($email === '' || $name === '' || mb_strlen($password, 'UTF-8') < 10) {
        apiError(400, 'E-Mail, vollständiger Name und Passwort (min. 10 Zeichen) erforderlich.');
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        apiError(400, 'Ungültige E-Mail-Adresse.');
    }

    $hash = hashPassword($password);

    try {
        db()->prepare(
            'INSERT INTO users (email, full_name, role, password_hash, is_active, created_at, updated_at)
             VALUES (:email, :name, :role, :hash, 1, :now, :now2)
             ON DUPLICATE KEY UPDATE
               full_name     = :name2,
               role          = :role2,
               password_hash = :hash2,
               is_active     = 1,
               updated_at    = :now3'
        )->execute([
            ':email' => $email,
            ':name'  => $name,
            ':role'  => 'administrator',
            ':hash'  => $hash,
            ':now'   => nowUtc(),
            ':now2'  => nowUtc(),
            ':name2' => $name,
            ':role2' => 'administrator',
            ':hash2' => $hash,
            ':now3'  => nowUtc(),
        ]);
    } catch (Throwable $e) {
        error_log('[setup] Fehler beim Anlegen des Administrators: ' . $e->getMessage());
        apiError(503, 'Administrator konnte nicht angelegt werden.');
    }

    apiJson(['ok' => true, 'message' => "Administrator '$email' wurde angelegt."]);
}

function handleSeedReference(): never
{
    // Set longer execution time for large seed
    set_time_limit(120);
    
    try {
        require_once __DIR__ . '/seed_reference.php';
        $result = seedReferenceProject();
        apiJson($result);
    } catch (Throwable $e) {
        error_log('[setup] Seed-Fehler: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
        apiError(500, 'Referenzdaten konnten nicht angelegt werden: ' . $e->getMessage());
    }
}

function handleFixTitles(): never
{
    $pdo = db();
    // Update Referenzprojekt title
    $pdo->exec("UPDATE projects SET title = 'Testumgebung', object_name = 'Demonstrationsprojekt für Entwicklung und Schulung', address = 'Musterstraße 1, 53123 Musterstadt (fiktiv)' WHERE id = 2");
    apiJson(['ok' => true, 'message' => 'Titel aktualisiert.']);
}