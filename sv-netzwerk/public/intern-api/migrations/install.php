#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * SVOS Inspection Platform – Installations-CLI
 *
 * Verwendung:
 *   php install.php [--migrate] [--create-admin]
 *
 * Optionen:
 *   --migrate       Datenbankschema anlegen (001_initial_schema.sql ausfuehren)
 *   --create-admin  Ersten Administrator-Account anlegen
 *
 * Sicherheit:
 *   - Laeuft NUR auf der Kommandozeile (nicht per HTTP aufrufbar)
 *   - Prueft ob bereits ein Admin existiert – verhindert doppeltes Setup
 *   - Passwort wird interaktiv abgefragt (kein Echo)
 *   - Speichert keine Credentials in Dateien
 *
 * Deployment:
 *   1. .env in intern-api/ anlegen (siehe env.example)
 *   2. php install.php --migrate
 *   3. php install.php --create-admin
 */

// Nur CLI-Ausfuehrung erlaubt
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'Dieses Skript darf nur auf der Kommandozeile ausgefuehrt werden.';
    exit(1);
}

// Bootstrap laden
require_once __DIR__ . '/bootstrap.php';

use SvIntern\Config\Config;
use SvIntern\Config\Database;
use SvIntern\Services\AuthService;

$args     = array_slice($argv ?? [], 1);
$migrate  = in_array('--migrate',      $args, true);
$mkAdmin  = in_array('--create-admin', $args, true);
$help     = in_array('--help',         $args, true) || empty($args);

if ($help) {
    echo <<<HELP
    SVOS Inspection Platform – Installations-CLI

    Verwendung:
      php install.php [Optionen]

    Optionen:
      --migrate       Datenbankschema anlegen
      --create-admin  Ersten Administrator anlegen
      --help          Diese Hilfe anzeigen

    Beispiel:
      php install.php --migrate --create-admin

    HELP;
    exit(0);
}

// ── Datenbankverbindung pruefen ──────────────────────────────────────────────
try {
    $db = Database::getInstance();
    echo "[OK] Datenbankverbindung hergestellt.\n";
} catch (\Throwable $e) {
    echo "[FEHLER] Datenbankverbindung fehlgeschlagen: " . $e->getMessage() . "\n";
    echo "        Bitte .env pruefen (DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD).\n";
    exit(1);
}

// ── Schema-Migration ──────────────────────────────────────────────────────────
if ($migrate) {
    $sqlFile = __DIR__ . '/migrations/001_initial_schema.sql';
    if (!file_exists($sqlFile)) {
        echo "[FEHLER] Schema-Datei nicht gefunden: {$sqlFile}\n";
        exit(1);
    }

    echo "[INFO] Schema wird angewendet...\n";

    $sql        = file_get_contents($sqlFile);
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        fn(string $s) => $s !== '' && !str_starts_with(ltrim($s), '--')
    );

    $db->exec('SET foreign_key_checks = 0');

    $errors = 0;
    foreach ($statements as $stmt) {
        // Leere Statements und reine Kommentare ueberspringen
        $cleaned = preg_replace('/--[^\n]*\n/', '', $stmt);
        if (trim((string) $cleaned) === '') {
            continue;
        }
        try {
            $db->exec($stmt);
        } catch (\PDOException $e) {
            // "Table already exists" und "Duplicate entry" sind bei IF NOT EXISTS / INSERT IGNORE ok
            if (!str_contains($e->getMessage(), '1050') && !str_contains($e->getMessage(), '1062')) {
                echo "[WARNUNG] SQL-Fehler: " . $e->getMessage() . "\n";
                $errors++;
            }
        }
    }

    $db->exec('SET foreign_key_checks = 1');

    if ($errors === 0) {
        echo "[OK] Schema erfolgreich angewendet.\n";
    } else {
        echo "[WARNUNG] Schema angewendet, {$errors} Fehler aufgetreten.\n";
    }
}

// ── Administrator anlegen ─────────────────────────────────────────────────────
if ($mkAdmin) {
    // Pruefen ob bereits ein Admin existiert
    $check = $db->query("SELECT COUNT(*) FROM users WHERE role = 'administrator' AND is_active = 1");
    $count = (int) $check->fetchColumn();

    if ($count > 0) {
        echo "[INFO] Es existiert bereits mindestens ein aktiver Administrator.\n";
        echo "       Wenn Sie einen weiteren anlegen moechten, bestaetigen Sie:\n";
        echo "       Wirklich fortfahren? [j/N] ";
        $confirm = strtolower(trim((string) fgets(STDIN)));
        if ($confirm !== 'j') {
            echo "[ABBRUCH]\n";
            exit(0);
        }
    }

    // Eingaben interaktiv abfragen
    echo "\n── Administrator anlegen ──\n";

    echo "E-Mail-Adresse: ";
    $email = strtolower(trim((string) fgets(STDIN)));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo "[FEHLER] Ungueltige E-Mail-Adresse.\n";
        exit(1);
    }

    echo "Vollstaendiger Name: ";
    $name = trim((string) fgets(STDIN));

    if ($name === '') {
        echo "[FEHLER] Name darf nicht leer sein.\n";
        exit(1);
    }

    // Passwort ohne Echo einlesen (funktioniert auf Unix/Linux)
    echo "Passwort (mind. 12 Zeichen, Gross-/Kleinbuchstaben, Ziffer): ";
    if (function_exists('readline')) {
        system('stty -echo');
        $password = (string) fgets(STDIN);
        system('stty echo');
        echo "\n";
    } else {
        $password = (string) fgets(STDIN);
    }
    $password = trim($password);

    $pwErrors = AuthService::validatePasswordStrength($password);
    if ($pwErrors) {
        echo "[FEHLER] " . implode("\n[FEHLER] ", $pwErrors) . "\n";
        exit(1);
    }

    echo "Passwort wiederholen: ";
    if (function_exists('readline')) {
        system('stty -echo');
        $confirm2 = trim((string) fgets(STDIN));
        system('stty echo');
        echo "\n";
    } else {
        $confirm2 = trim((string) fgets(STDIN));
    }

    if ($password !== $confirm2) {
        echo "[FEHLER] Passwoerter stimmen nicht ueberein.\n";
        exit(1);
    }

    // E-Mail auf Duplikate pruefen
    $dup = $db->prepare("SELECT COUNT(*) FROM users WHERE email = :email");
    $dup->execute([':email' => $email]);
    if ((int) $dup->fetchColumn() > 0) {
        echo "[FEHLER] E-Mail-Adresse bereits vergeben.\n";
        exit(1);
    }

    // Administrator anlegen
    $id   = generateUuid();
    $hash = AuthService::hashPassword($password);

    $stmt = $db->prepare(
        'INSERT INTO users (id, email, full_name, role, password_hash, is_active)
         VALUES (:id, :email, :name, \'administrator\', :hash, 1)'
    );
    $stmt->execute([':id' => $id, ':email' => $email, ':name' => $name, ':hash' => $hash]);

    echo "\n[OK] Administrator angelegt:\n";
    echo "     ID:    {$id}\n";
    echo "     Name:  {$name}\n";
    echo "     Email: {$email}\n";
    echo "     Rolle: administrator\n\n";
    echo "[INFO] Bitte notieren Sie die ID fuer spaetere Referenz.\n";
    echo "[INFO] Das Passwort wird nicht erneut angezeigt.\n\n";
}

echo "[FERTIG]\n";
exit(0);
