<?php
declare(strict_types=1);
const KUSS_API_BASE = 'https://portal.kussgmbh.de/api/contractor';

function kussTokenClaims(string $token): array
{
    if (strlen($token) > 12000 || !preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/D', $token)) {
        throw new InvalidArgumentException('Der KUSS-Token hat kein gültiges Format.');
    }
    $part = explode('.', $token)[1];
    $claims = json_decode((string)base64_decode(strtr($part, '-_', '+/'), true), true);
    // Metadata only. Authorization is verified by KUSS, never by this unsigned payload.
    if (!is_array($claims) || !is_numeric($claims['exp'] ?? null) || (float)$claims['exp'] <= time()) {
        throw new InvalidArgumentException('Der KUSS-Token ist abgelaufen oder enthält kein Ablaufdatum.');
    }
    return $claims;
}
function kussEncrypt(string $token, string $secret, int $owner): string
{
    if ($secret === '' || $owner < 1) throw new RuntimeException('Die sichere KUSS-Ablage ist nicht konfiguriert.');
    $iv = random_bytes(12); $tag = '';
    $key = hash('sha256', 'svnet:kuss:v1:' . $secret, true);
    $cipher = openssl_encrypt($token, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, (string)$owner);
    if ($cipher === false) throw new RuntimeException('Der KUSS-Token konnte nicht verschlüsselt werden.');
    return base64_encode($iv . $tag . $cipher);
}
function kussDecrypt(string $payload, string $secret, int $owner): string
{
    if ($secret === '' || $owner < 1) throw new RuntimeException('Die sichere KUSS-Ablage ist nicht konfiguriert.');
    $raw = base64_decode($payload, true);
    if ($raw === false || strlen($raw) < 29) throw new RuntimeException('Die KUSS-Verbindung muss neu eingerichtet werden.');
    $key = hash('sha256', 'svnet:kuss:v1:' . $secret, true);
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16), (string)$owner);
    if ($plain === false) throw new RuntimeException('Die KUSS-Verbindung muss neu eingerichtet werden.');
    return $plain;
}
function kussSchema(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS kuss_user_connections (
        user_id INT UNSIGNED PRIMARY KEY,
        token_ciphertext TEXT NOT NULL,
        expires_at DATETIME NOT NULL,
        verified_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function kussConnection(PDO $pdo, int $owner): ?array
{
    $q = $pdo->prepare('SELECT token_ciphertext, expires_at, verified_at FROM kuss_user_connections WHERE user_id = ?');
    $q->execute([$owner]); $row = $q->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}
function kussJobsQuery(array $input): array
{
    $page = filter_var($input['page'] ?? 1, FILTER_VALIDATE_INT);
    if ($page === false || $page < 1 || $page > 100000) throw new InvalidArgumentException('Ungültige KUSS-Seite.');
    $search = trim((string)($input['search'] ?? ''));
    if (strlen($search) > 240) throw new InvalidArgumentException('Der KUSS-Suchtext ist zu lang.');
    $query = ['page[number]' => $page];
    if ($search !== '') $query['filter[search]'] = $search;
    return $query;
}
function kussRequest(string $token, string $method, string $path, array $query = [], ?array $payload = null, array $expected = [200]): array
{
    kussTokenClaims($token);
    $url = KUSS_API_BASE . '/' . ltrim($path, '/');
    if ($query !== []) $url .= '?' . http_build_query($query);
    $curl = curl_init($url);
    if ($curl === false) throw new RuntimeException('KUSS ist derzeit nicht erreichbar.');
    $headers = ['Authorization: Bearer ' . $token, 'Accept: application/vnd.api+json', 'Content-Type: application/vnd.api+json'];
    $options = [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 45, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_CUSTOMREQUEST => strtoupper($method), CURLOPT_HTTPHEADER => $headers];
    if ($payload !== null) $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    curl_setopt_array($curl, $options);
    $body = curl_exec($curl); $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); curl_close($curl);
    if ($status === 401 || $status === 403) throw new RuntimeException('KUSS hat den API-Zugriff abgelehnt. Bitte Token und Berechtigungen prüfen.', 502);
    if ($body === false || !in_array($status, $expected, true)) throw new RuntimeException('KUSS konnte nicht abgefragt werden' . ($status ? ' (HTTP ' . $status . ')' : '') . '.', 502);
    $data = json_decode((string)$body, true);
    if (!is_array($data)) throw new RuntimeException('KUSS hat keine gültige Antwort geliefert.', 502);
    return $data;
}
function kussRequestJobs(string $token, array $query): array
{
    $data = kussRequest($token, 'GET', '/jobs', $query);
    if (!isset($data['data']) || !is_array($data['data'])) throw new RuntimeException('KUSS hat keine gültige Auftragsliste geliefert.', 502);
    return $data;
}
function kussRequestJob(string $token, string $id): array { return kussRequest($token, 'GET', '/jobs/' . rawurlencode($id)); }
function kussRequestJobMessages(string $token, string $id): array { return kussRequest($token, 'GET', '/jobs/' . rawurlencode($id) . '/messages'); }
function kussRequestJobCaseFile(string $token, string $id): array { return kussRequest($token, 'GET', '/jobs/' . rawurlencode($id) . '/case-file'); }
function kussRequestAttachment(string $token, string $id): array { return kussRequest($token, 'GET', '/attachments/' . rawurlencode($id)); }
function kussDownload(string $token, string $path): array
{
    kussTokenClaims($token); $curl = curl_init(KUSS_API_BASE . '/' . ltrim($path, '/'));
    if ($curl === false) throw new RuntimeException('KUSS ist derzeit nicht erreichbar.');
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 60, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: */*']]);
    $body = curl_exec($curl); $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE); $mime = (string)curl_getinfo($curl, CURLINFO_CONTENT_TYPE); curl_close($curl);
    if ($status === 401 || $status === 403) throw new RuntimeException('KUSS hat den API-Zugriff abgelehnt. Bitte Token und Berechtigungen prüfen.', 502);
    if ($body === false || $status !== 200) throw new RuntimeException('KUSS-Anhang konnte nicht geladen werden.', 502);
    return ['bytes' => (string)$body, 'mime' => preg_replace('/;.*$/', '', $mime) ?: 'application/octet-stream'];
}
function kussPublicJobs(array $response): array
{
    $jobs = [];
    foreach ($response['data'] ?? [] as $job) {
        if (!is_array($job) || !in_array($job['type'] ?? '', ['jobs', 'job'], true)) continue;
        $a = (array)($job['attributes'] ?? []);
        $jobs[] = ['id' => (string)($job['id'] ?? ''), 'case' => (string)($a['case'] ?? ''),
            'reference' => (string)($a['kuss-reference'] ?? ''), 'subject' => (string)($a['subject'] ?? ''),
            'state' => (string)($a['state'] ?? ''), 'insurer' => (string)($a['insurance-company'] ?? '')];
    }
    $p = (array)($response['meta']['page'] ?? []);
    return ['jobs' => $jobs, 'page' => ['current' => (int)($p['current-page'] ?? 1),
        'last' => (int)($p['last-page'] ?? 1), 'total' => (int)($p['total'] ?? count($jobs))]];
}
