<?php
/**
 * Integrationstests für die aktuelle Portal-API.
 *
 * Ausführung:
 *   php tests/integration_test.php <BASE_URL> <ADMIN_EMAIL> <ADMIN_PASSWORD>
 */

declare(strict_types=1);

$baseUrl       = rtrim($argv[1] ?? 'http://127.0.0.1:8080/intern/api', '/');
$adminEmail    = $argv[2] ?? 'admin@testprojekt.local';
$adminPassword = $argv[3] ?? 'Test2026!';

$passed = 0;
$failed = 0;
$errors = [];

function test(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed, $errors;
    if ($condition) {
        $passed++;
        echo "  ✓ $name\n";
    } else {
        $failed++;
        $errors[] = $detail === '' ? $name : "$name: $detail";
        echo "  ✗ $name" . ($detail !== '' ? " ($detail)" : "") . "\n";
    }
}

function apiCall(string $url, string $method = 'GET', ?array $data = null, ?string $sessionCookie = null): array
{
    $ch = curl_init($url);
    $headers = ['Accept: application/json'];

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_HEADER, true);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
    } elseif ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    }

    if ($data !== null) {
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE);
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    }

    if ($sessionCookie) {
        curl_setopt($ch, CURLOPT_COOKIE, $sessionCookie);
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $raw = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $rawHeaders = substr((string) $raw, 0, $headerSize);
    $bodyRaw = substr((string) $raw, $headerSize);
    $bodyJson = json_decode($bodyRaw, true);

    $cookie = $sessionCookie ?? '';
    if (preg_match('/Set-Cookie:\s*PHPSESSID=([^;]+)/i', $rawHeaders, $m)) {
        $cookie = 'PHPSESSID=' . $m[1];
    }

    return [
        'code' => $httpCode,
        'body' => $bodyJson ?? $bodyRaw,
        'cookie' => $cookie,
    ];
}

echo "═══════════════════════════════════════════════════════════\n";
echo " INTEGRATIONSTESTS - SV-Netzwerk Portal-API\n";
echo " URL: $baseUrl\n";
echo " Zeitpunkt: " . date('Y-m-d H:i:s') . "\n";
echo "═══════════════════════════════════════════════════════════\n\n";

echo "── 1. Authentifizierung ──────────────────────────────────\n";
$login = apiCall("$baseUrl/auth.php?action=login", 'POST', [
    'email' => $adminEmail,
    'password' => $adminPassword,
]);
$adminCookie = $login['cookie'] ?? '';
test('Admin-Login erfolgreich', $login['code'] === 200, 'HTTP ' . $login['code']);
test('Session-Cookie gesetzt', $adminCookie !== '');
test(
    'Rolle ist Administrator',
    strtolower((string) ($login['body']['role'] ?? '')) === 'administrator'
        || strtolower((string) ($login['body']['role'] ?? '')) === 'admin'
);

$session = apiCall("$baseUrl/auth.php?action=session", 'GET', null, $adminCookie);
test('Session abrufbar', $session['code'] === 200, 'HTTP ' . $session['code']);
test('Session enthält Benutzer', !empty($session['body']['user']['email'] ?? ''));

echo "\n── 2. Benutzerverzeichnis ────────────────────────────────\n";
$users = apiCall("$baseUrl/users.php", 'GET', null, $adminCookie);
test('Benutzerliste abrufbar', $users['code'] === 200, 'HTTP ' . $users['code']);
test('Mindestens 4 Benutzer vorhanden', is_array($users['body']) && count($users['body']) >= 4, 'Anzahl: ' . (is_array($users['body']) ? count($users['body']) : 0));

echo "\n── 3. Projekt- und Hierarchiedaten ───────────────────────\n";
$projects = apiCall("$baseUrl/projects.php", 'GET', null, $adminCookie);
test('Projektliste abrufbar', $projects['code'] === 200, 'HTTP ' . $projects['code']);
test('Mindestens 1 aktives Projekt', !empty($projects['body']['projects']) && count($projects['body']['projects']) >= 1);

$buildings = apiCall("$baseUrl/hierarchy.php?project_id=1", 'GET', null, $adminCookie);
test('Gebäudeliste abrufbar', $buildings['code'] === 200, 'HTTP ' . $buildings['code']);
test('Mindestens 1 Gebäude vorhanden', is_array($buildings['body']) && count($buildings['body']) >= 1, 'Anzahl: ' . (is_array($buildings['body']) ? count($buildings['body']) : 0));

$firstBuildingId = is_array($buildings['body']) && !empty($buildings['body'][0]['id']) ? (int)$buildings['body'][0]['id'] : 0;
if ($firstBuildingId > 0) {
    $floors = apiCall("$baseUrl/hierarchy.php?building_id=$firstBuildingId", 'GET', null, $adminCookie);
    test('Etagenliste abrufbar', $floors['code'] === 200, 'HTTP ' . $floors['code']);
    test('Mindestens 1 Etage vorhanden', is_array($floors['body']) && count($floors['body']) >= 1, 'Anzahl: ' . (is_array($floors['body']) ? count($floors['body']) : 0));
}

$windows = apiCall("$baseUrl/windows.php?project_id=1", 'GET', null, $adminCookie);
test('Fensterliste abrufbar', $windows['code'] === 200, 'HTTP ' . $windows['code']);
test('Mindestens 1 Fenster vorhanden', is_array($windows['body']) && count($windows['body']) >= 1, 'Anzahl: ' . (is_array($windows['body']) ? count($windows['body']) : 0));

echo "\n── 4. CRUD-Basistest (Gebäude) ───────────────────────────\n";
$create = apiCall("$baseUrl/hierarchy.php?entity=building&project_id=1", 'POST', [
    'name' => 'Integrationstest Gebäude',
    'code' => 'ITG',
    'notes' => 'Automatischer Testdatensatz',
], $adminCookie);
test('Gebäude anlegen', $create['code'] === 201, 'HTTP ' . $create['code']);

$newBuildingId = (int) ($create['body']['id'] ?? 0);
if ($newBuildingId > 0) {
    $delete = apiCall("$baseUrl/hierarchy.php?entity=building&id=$newBuildingId", 'DELETE', null, $adminCookie);
    test('Gebäude löschen', $delete['code'] === 200, 'HTTP ' . $delete['code']);
}

echo "\n── 5. Rollenprüfung (Gast) ───────────────────────────────\n";
$guestLogin = apiCall("$baseUrl/auth.php?action=login", 'POST', [
    'email' => 'gast@testprojekt.local',
    'password' => 'Test2026!',
]);
$guestCookie = $guestLogin['cookie'] ?? '';
test('Gast-Login erfolgreich', $guestLogin['code'] === 200, 'HTTP ' . $guestLogin['code']);

if ($guestCookie !== '') {
    $guestUsers = apiCall("$baseUrl/users.php", 'GET', null, $guestCookie);
    test('Gast hat keinen Zugriff auf Benutzerliste', $guestUsers['code'] === 403, 'HTTP ' . $guestUsers['code']);
}

echo "\n═══════════════════════════════════════════════════════════\n";
echo " ERGEBNIS: $passed bestanden, $failed fehlgeschlagen\n";
echo "═══════════════════════════════════════════════════════════\n";

if ($failed > 0) {
    echo "\nFehler:\n";
    foreach ($errors as $error) {
        echo "  • $error\n";
    }
}

exit($failed > 0 ? 1 : 0);
