<?php
/**
 * Datenintegritäts-Test
 * 
 * Prüft die Referenzdatenbank auf Konsistenz.
 * Ausführung: php tests/data_integrity_test.php
 * 
 * Benötigt: DB-Verbindungsdaten in Umgebungsvariablen oder .env
 */

// Load .env if exists
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
$db = getenv('DB_NAME') ?: 'fensterpruefung';
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

function check($name, $query, $expectation, $pdo) {
    global $passed, $failed;
    try {
        $stmt = $pdo->query($query);
        $result = $stmt->fetchColumn();
        $ok = false;
        
        if ($expectation === 'positive') $ok = $result > 0;
        elseif ($expectation === 'zero') $ok = $result == 0;
        elseif (is_numeric($expectation)) $ok = $result >= $expectation;
        else $ok = $result == $expectation;
        
        if ($ok) {
            $passed++;
            echo "  ✓ $name (=$result)\n";
        } else {
            $failed++;
            echo "  ✗ $name (erwartet: $expectation, erhalten: $result)\n";
        }
    } catch (Exception $e) {
        $failed++;
        echo "  ✗ $name (Fehler: " . $e->getMessage() . ")\n";
    }
}

echo "═══════════════════════════════════════════════════\n";
echo " DATENINTEGRITÄTS-TEST\n";
echo "═══════════════════════════════════════════════════\n\n";

echo "── Mindestmengen ────────────────────────────────\n";
check('Gebäude >= 5', "SELECT COUNT(*) FROM gebaeude", 5, $pdo);
check('Etagen >= 20', "SELECT COUNT(*) FROM etagen", 20, $pdo);
check('Räume >= 200', "SELECT COUNT(*) FROM raeume", 200, $pdo);
check('Fenster >= 800', "SELECT COUNT(*) FROM fenster", 800, $pdo);
check('Flügel >= 1000', "SELECT COUNT(*) FROM fluegel", 1000, $pdo);
check('Benutzer >= 6', "SELECT COUNT(*) FROM benutzer", 6, $pdo);

echo "\n── Referentielle Integrität ─────────────────────\n";
check('Alle Etagen haben gültiges Gebäude',
    "SELECT COUNT(*) FROM etagen e LEFT JOIN gebaeude g ON e.gebaeude_id = g.id WHERE g.id IS NULL",
    'zero', $pdo);
check('Alle Räume haben gültige Etage',
    "SELECT COUNT(*) FROM raeume r LEFT JOIN etagen e ON r.etage_id = e.id WHERE e.id IS NULL",
    'zero', $pdo);
check('Alle Fenster haben gültigen Raum',
    "SELECT COUNT(*) FROM fenster f LEFT JOIN raeume r ON f.raum_id = r.id WHERE r.id IS NULL",
    'zero', $pdo);
check('Alle Flügel haben gültiges Fenster',
    "SELECT COUNT(*) FROM fluegel fl LEFT JOIN fenster f ON fl.fenster_id = f.id WHERE f.id IS NULL",
    'zero', $pdo);
check('Alle Fotos haben gültiges Fenster',
    "SELECT COUNT(*) FROM fotos fo LEFT JOIN fenster f ON fo.fenster_id = f.id WHERE f.id IS NULL",
    'zero', $pdo);

echo "\n── Statusverteilung ─────────────────────────────\n";
check('Fenster mit Status "nicht_begonnen"',
    "SELECT COUNT(*) FROM fenster WHERE status = 'nicht_begonnen'", 'positive', $pdo);
check('Fenster mit Status "abgeschlossen"',
    "SELECT COUNT(*) FROM fenster WHERE status = 'abgeschlossen'", 'positive', $pdo);
check('Fenster mit Status "mangel"',
    "SELECT COUNT(*) FROM fenster WHERE status = 'mangel'", 'positive', $pdo);
check('Fenster mit Status "in_bearbeitung"',
    "SELECT COUNT(*) FROM fenster WHERE status = 'in_bearbeitung'", 'positive', $pdo);

echo "\n── Rollenverteilung ─────────────────────────────\n";
check('Administrator vorhanden',
    "SELECT COUNT(*) FROM benutzer WHERE rolle = 'admin' AND is_active = 1", 'positive', $pdo);
check('Prüfer vorhanden',
    "SELECT COUNT(*) FROM benutzer WHERE rolle = 'pruefer' AND is_active = 1", 'positive', $pdo);
check('Sachverständiger vorhanden',
    "SELECT COUNT(*) FROM benutzer WHERE rolle = 'sachverstaendiger' AND is_active = 1", 'positive', $pdo);

echo "\n── Datenqualität ────────────────────────────────\n";
check('Keine NULL-Fensternummern',
    "SELECT COUNT(*) FROM fenster WHERE fensternummer IS NULL OR fensternummer = ''",
    'zero', $pdo);
check('Keine NULL-Raumnummern',
    "SELECT COUNT(*) FROM raeume WHERE raumnummer IS NULL OR raumnummer = ''",
    'zero', $pdo);
check('Fensterbreite plausibel (300-5000mm)',
    "SELECT COUNT(*) FROM fenster WHERE breite_mm < 300 OR breite_mm > 5000",
    'zero', $pdo);
check('Fensterhöhe plausibel (200-4000mm)',
    "SELECT COUNT(*) FROM fenster WHERE hoehe_mm < 200 OR hoehe_mm > 4000",
    'zero', $pdo);
check('Baujahre plausibel (1970-2025)',
    "SELECT COUNT(*) FROM fenster WHERE baujahr < 1970 OR baujahr > 2025",
    'zero', $pdo);

echo "\n── Bewusste Fehlerfälle ─────────────────────────\n";
check('Doppelte Fensternummern vorhanden (Testfall)',
    "SELECT COUNT(*) FROM (SELECT fensternummer FROM fenster GROUP BY fensternummer HAVING COUNT(*) > 1) t",
    'positive', $pdo);

echo "\n═══════════════════════════════════════════════════\n";
echo " ERGEBNIS: $passed bestanden, $failed fehlgeschlagen\n";
echo "═══════════════════════════════════════════════════\n";

exit($failed > 0 ? 1 : 0);
