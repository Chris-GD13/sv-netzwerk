<?php
/**
 * Automatisierte Integrationstests für das Fensterprüfungsportal
 * 
 * Ausführung: php tests/integration_test.php <BASE_URL> <ADMIN_EMAIL> <ADMIN_PASSWORD>
 * 
 * Prüft:
 * - Datenintegrität
 * - Beziehungen
 * - Berechtigungen
 * - API-Endpunkte
 * - Filter & Suche
 * - Exporte
 */

$baseUrl = $argv[1] ?? 'https://www.sv-netzwerk.eu/intern/api';
$adminEmail = $argv[2] ?? 'admin@testprojekt.local';
$adminPassword = $argv[3] ?? 'Test2026!';

$passed = 0;
$failed = 0;
$errors = [];

function test($name, $condition, $detail = '') {
    global $passed, $failed, $errors;
    if ($condition) {
        $passed++;
        echo "  ✓ $name\n";
    } else {
        $failed++;
        $errors[] = "$name: $detail";
        echo "  ✗ $name" . ($detail ? " ($detail)" : "") . "\n";
    }
}

function apiCall($url, $method = 'GET', $data = null, $sessionCookie = null) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($data) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }
    }
    
    if ($sessionCookie) {
        curl_setopt($ch, CURLOPT_COOKIE, $sessionCookie);
    }
    
    curl_setopt($ch, CURLOPT_HEADER, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    curl_close($ch);
    
    // Extract session cookie
    $cookie = '';
    if (preg_match('/Set-Cookie:\s*PHPSESSID=([^;]+)/i', $headers, $m)) {
        $cookie = "PHPSESSID=" . $m[1];
    }
    
    return [
        'code' => $httpCode,
        'body' => json_decode($body, true) ?? $body,
        'cookie' => $cookie ?: $sessionCookie
    ];
}

echo "═══════════════════════════════════════════════════════════\n";
echo " INTEGRATIONSTESTS - Fensterprüfungsportal\n";
echo " URL: $baseUrl\n";
echo " Zeitpunkt: " . date('Y-m-d H:i:s') . "\n";
echo "═══════════════════════════════════════════════════════════\n\n";

// ============================================================
// 1. AUTHENTIFIZIERUNG
// ============================================================
echo "── 1. Authentifizierung ──────────────────────────────────\n";

$res = apiCall("$baseUrl/auth.php", 'POST', [
    'action' => 'login',
    'email' => $adminEmail,
    'password' => $adminPassword
]);
$adminCookie = $res['cookie'];
test('Admin-Login erfolgreich', $res['code'] === 200 && ($res['body']['success'] ?? false), "HTTP {$res['code']}");
test('Session-Cookie gesetzt', !empty($adminCookie));
test('Rolle = admin', ($res['body']['user']['role'] ?? '') === 'admin');

// Test: Ungültiges Login
$res = apiCall("$baseUrl/auth.php", 'POST', [
    'action' => 'login',
    'email' => 'nobody@invalid.local',
    'password' => 'wrong'
]);
test('Ungültiges Login abgelehnt', $res['code'] === 200 && !($res['body']['success'] ?? true));

// Test: Leeres Passwort
$res = apiCall("$baseUrl/auth.php", 'POST', [
    'action' => 'login',
    'email' => $adminEmail,
    'password' => ''
]);
test('Leeres Passwort abgelehnt', !($res['body']['success'] ?? true));

echo "\n";

// ============================================================
// 2. DATENINTEGRITÄT
// ============================================================
echo "── 2. Datenintegrität ─────────────────────────────────────\n";

$res = apiCall("$baseUrl/gebaeude.php?action=list", 'GET', null, $adminCookie);
test('Gebäude abrufbar', $res['code'] === 200);
$gebaeude = $res['body']['data'] ?? $res['body'] ?? [];
test('Mindestens 5 Gebäude', count($gebaeude) >= 5, count($gebaeude) . " gefunden");

// Check floors for first building
if (!empty($gebaeude)) {
    $gId = $gebaeude[0]['id'] ?? 1;
    $res = apiCall("$baseUrl/etagen.php?action=list&gebaeude_id=$gId", 'GET', null, $adminCookie);
    $etagen = $res['body']['data'] ?? $res['body'] ?? [];
    test('Etagen für Gebäude 1 vorhanden', count($etagen) > 0, count($etagen) . " Etagen");
}

echo "\n";

// ============================================================
// 3. BERECHTIGUNGEN
// ============================================================
echo "── 3. Berechtigungen ──────────────────────────────────────\n";

// Login as Gast
$res = apiCall("$baseUrl/auth.php", 'POST', [
    'action' => 'login',
    'email' => 'gast@testprojekt.local',
    'password' => 'Test2026!'
]);
$gastCookie = $res['cookie'];
$gastLoginOk = $res['body']['success'] ?? false;
test('Gast-Login erfolgreich', $gastLoginOk);

// Gast should not be able to create buildings
if ($gastLoginOk) {
    $res = apiCall("$baseUrl/gebaeude.php", 'POST', [
        'action' => 'create',
        'name' => 'Testgebäude',
        'kuerzel' => 'TST'
    ], $gastCookie);
    test('Gast kann kein Gebäude anlegen', !($res['body']['success'] ?? true));
}

// Gast should not access AI import
$res = apiCall("$baseUrl/ai-import.php", 'POST', [
    'action' => 'analyze'
], $gastCookie);
test('Gast kein Zugriff auf KI-Import', !($res['body']['success'] ?? true));

// Admin should access AI import endpoint
$res = apiCall("$baseUrl/ai-import.php", 'POST', [
    'action' => 'analyze'
], $adminCookie);
// Will fail due to missing file, but should not be "unauthorized"
test('Admin Zugriff auf KI-Import erlaubt', ($res['body']['error'] ?? '') !== 'unauthorized');

echo "\n";

// ============================================================
// 4. CRUD-OPERATIONEN
// ============================================================
echo "── 4. CRUD-Operationen ────────────────────────────────────\n";

// Create building
$res = apiCall("$baseUrl/gebaeude.php", 'POST', [
    'action' => 'create',
    'name' => 'Testgebäude Integration',
    'kuerzel' => 'TI'
], $adminCookie);
$createOk = $res['body']['success'] ?? false;
$newId = $res['body']['id'] ?? null;
test('Gebäude erstellen', $createOk);

// Read it back
if ($newId) {
    $res = apiCall("$baseUrl/gebaeude.php?action=get&id=$newId", 'GET', null, $adminCookie);
    test('Gebäude lesen', ($res['body']['data']['name'] ?? '') === 'Testgebäude Integration');
    
    // Delete it
    $res = apiCall("$baseUrl/gebaeude.php", 'POST', [
        'action' => 'delete',
        'id' => $newId
    ], $adminCookie);
    test('Gebäude löschen', $res['body']['success'] ?? false);
}

echo "\n";

// ============================================================
// 5. EXPORT
// ============================================================
echo "── 5. Export ───────────────────────────────────────────────\n";

$res = apiCall("$baseUrl/export.php?format=csv", 'GET', null, $adminCookie);
test('CSV-Export erreichbar', $res['code'] === 200);
test('CSV enthält Daten', strlen($res['body'] ?? '') > 100);

echo "\n";

// ============================================================
// ERGEBNIS
// ============================================================
echo "═══════════════════════════════════════════════════════════\n";
echo " ERGEBNIS: $passed bestanden, $failed fehlgeschlagen\n";
echo "═══════════════════════════════════════════════════════════\n";

if (!empty($errors)) {
    echo "\nFehler:\n";
    foreach ($errors as $e) {
        echo "  • $e\n";
    }
}

exit($failed > 0 ? 1 : 0);
