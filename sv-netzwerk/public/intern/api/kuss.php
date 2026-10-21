<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/kuss-core.php';
commonHeaders();
$user = requireAuth(); $owner = (int)$user['id'];
$action = (string)($_GET['action'] ?? 'status');
$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');
$_SESSION['kuss_csrf'] ??= bin2hex(random_bytes(32));
$csrf = $_SESSION['kuss_csrf'];
// Release the session before any remote API call.
session_write_close();
try {
    if (!in_array($action, ['status', 'connect', 'jobs'], true)) apiError(404, 'Unbekannte KUSS-Aktion.');
    if (($action === 'connect' && $method !== 'POST') || ($action !== 'connect' && $method !== 'GET')) apiError(405, 'HTTP-Methode nicht zulässig.');
    $pdo = db(); kussSchema($pdo); $connection = kussConnection($pdo, $owner);
    if ($action === 'status') apiJson(['ok' => true, 'connected' => $connection !== null,
        'expires_at' => $connection['expires_at'] ?? null, 'verified_at' => $connection['verified_at'] ?? null,
        'csrf' => $csrf]);
    if ($action === 'connect') {
        if (!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) apiError(415, 'JSON erforderlich.');
        if (!hash_equals($csrf, (string)($_SERVER['HTTP_X_KUSS_CSRF'] ?? ''))) apiError(403, 'Sitzungsprüfung fehlgeschlagen.');
        if (isset($_SERVER['HTTP_ORIGIN']) && $_SERVER['HTTP_ORIGIN'] !== 'https://www.sv-netzwerk.eu') apiError(403, 'Ungültiger Ursprung.');
        $token = trim((string)(requestBody()['token'] ?? '')); $claims = kussTokenClaims($token);
        $cipher = kussEncrypt($token, env('APP_SECRET'), $owner);
        // Failed verification leaves the existing working connection untouched.
        $jobs = kussPublicJobs(kussRequestJobs($token, kussJobsQuery([])));
        $expires = gmdate('Y-m-d H:i:s', (int)$claims['exp']); $verified = gmdate('Y-m-d H:i:s');
        $q = $pdo->prepare('INSERT INTO kuss_user_connections (user_id,token_ciphertext,expires_at,verified_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE token_ciphertext=VALUES(token_ciphertext),expires_at=VALUES(expires_at),verified_at=VALUES(verified_at)');
        $q->execute([$owner, $cipher, $expires, $verified]);
        $saved = kussConnection($pdo, $owner);
        if (!$saved || !hash_equals($token, kussDecrypt($saved['token_ciphertext'], env('APP_SECRET'), $owner))) throw new RuntimeException('Die KUSS-Verbindung konnte nicht bestätigt werden.');
        apiJson(['ok' => true, 'connected' => true, 'expires_at' => $expires, 'verified_at' => $verified, 'page' => $jobs['page']]);
    }
    if ($connection === null) apiError(409, 'Für deine Anmeldung ist noch keine KUSS-API-Verbindung eingerichtet.');
    $token = kussDecrypt($connection['token_ciphertext'], env('APP_SECRET'), $owner);
    apiJson(['ok' => true] + kussPublicJobs(kussRequestJobs($token, kussJobsQuery($_GET))));
} catch (InvalidArgumentException $e) { apiError(422, $e->getMessage());
} catch (RuntimeException $e) { apiError($e->getCode() === 502 ? 502 : 503, $e->getMessage());
} catch (Throwable $e) { apiError(503, 'Die KUSS-Verbindung ist derzeit nicht verfügbar.'); }
