<?php
/**
 * Datenintegritäts-Test (aktuelles Portal-Schema)
 *
 * Ausführung: php tests/data_integrity_test.php
 */

declare(strict_types=1);

$envFile = __DIR__ . '/../sv-netzwerk/public/intern/api/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (str_starts_with($line, '#')) continue;
        if (strpos($line, '=') !== false) {
            putenv(trim($line));
        }
    }
}

$host = getenv('DB_HOST') ?: 'localhost';
$db   = getenv('DB_NAME') ?: 'fensterpruefung';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo "DB-Verbindung fehlgeschlagen: " . $e->getMessage() . "\n";
    exit(2);
}

$passed = 0;
$failed = 0;

function check(string $name, string $query, string|int $expectation, PDO $pdo): void
{
    global $passed, $failed;
    try {
        $result = (int) $pdo->query($query)->fetchColumn();
        $ok = false;

        if ($expectation === 'positive') $ok = $result > 0;
        elseif ($expectation === 'zero') $ok = $result === 0;
        else $ok = $result >= (int) $expectation;

        if ($ok) {
            $passed++;
            echo "  ✓ $name (=$result)\n";
        } else {
            $failed++;
            echo "  ✗ $name (erwartet: $expectation, erhalten: $result)\n";
        }
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $name (Fehler: {$e->getMessage()})\n";
    }
}

echo "═══════════════════════════════════════════════════\n";
echo " DATENINTEGRITÄTS-TEST (Portal v1.1)\n";
echo "═══════════════════════════════════════════════════\n\n";

echo "── Mindestmengen ────────────────────────────────\n";
check('Benutzer >= 4', "SELECT COUNT(*) FROM users", 4, $pdo);
check('Gebäude >= 1', "SELECT COUNT(*) FROM buildings", 1, $pdo);
check('Etagen >= 1', "SELECT COUNT(*) FROM floors", 1, $pdo);
check('Räume >= 1', "SELECT COUNT(*) FROM rooms", 1, $pdo);
check('Fenster >= 1', "SELECT COUNT(*) FROM windows WHERE deleted_at IS NULL", 1, $pdo);
check('Flügel >= 1', "SELECT COUNT(*) FROM window_sashes WHERE deleted_at IS NULL", 1, $pdo);

echo "\n── Referentielle Integrität ─────────────────────\n";
check(
    'Alle Etagen haben gültiges Gebäude',
    "SELECT COUNT(*) FROM floors f LEFT JOIN buildings b ON f.building_id = b.id WHERE b.id IS NULL",
    'zero',
    $pdo
);
check(
    'Alle Räume haben gültige Etage',
    "SELECT COUNT(*) FROM rooms r LEFT JOIN floors f ON r.floor_id = f.id WHERE f.id IS NULL",
    'zero',
    $pdo
);
check(
    'Alle Fenster haben gültigen Raum (oder NULL)',
    "SELECT COUNT(*) FROM windows w LEFT JOIN rooms r ON w.room_id = r.id WHERE w.room_id IS NOT NULL AND r.id IS NULL",
    'zero',
    $pdo
);
check(
    'Alle Flügel haben gültiges Fenster',
    "SELECT COUNT(*) FROM window_sashes s LEFT JOIN windows w ON s.window_id = w.id WHERE w.id IS NULL",
    'zero',
    $pdo
);

echo "\n── Rollen und Zugriff ───────────────────────────\n";
check('Administrator vorhanden', "SELECT COUNT(*) FROM users WHERE LOWER(role) IN ('administrator','admin') AND is_active = 1", 'positive', $pdo);
check('cw@sv-schuett.eu vorhanden', "SELECT COUNT(*) FROM users WHERE LOWER(email) = 'cw@sv-schuett.eu' AND is_active = 1", 'positive', $pdo);

echo "\n── Statusqualität ───────────────────────────────\n";
check('Fenster mit Statuswert', "SELECT COUNT(*) FROM windows WHERE status IS NULL OR status = ''", 'zero', $pdo);
check('Flügel mit Statuswert', "SELECT COUNT(*) FROM window_sashes WHERE status IS NULL OR status = ''", 'zero', $pdo);

echo "\n═══════════════════════════════════════════════════\n";
echo " ERGEBNIS: $passed bestanden, $failed fehlgeschlagen\n";
echo "═══════════════════════════════════════════════════\n";

exit($failed > 0 ? 1 : 0);
