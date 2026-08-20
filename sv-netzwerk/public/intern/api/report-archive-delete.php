<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
commonHeaders();
$user = requireAuth();

if (!in_array((string)($user['role'] ?? ''), ['administrator','projektleiter','sachverstaendiger'], true)) {
    apiError(403, 'Keine Berechtigung zum Löschen archivierter Gutachten.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') apiError(405, 'Methode nicht erlaubt.');
$id = max(1, (int)($_GET['id'] ?? 0));

function raSettingGet(string $key, string $default=''): string
{
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS app_settings (
            setting_key VARCHAR(190) PRIMARY KEY,
            setting_value MEDIUMTEXT NULL,
            updated_at DATETIME NOT NULL,
            updated_by VARCHAR(190) NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $stmt = db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key=:k LIMIT 1');
        $stmt->execute([':k'=>$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? $default : (string)$value;
    } catch (Throwable $e) {
        return $default;
    }
}

function raB64url(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function raHttp(string $method, string $url, array $headers = [], ?string $body = null, bool $auth = true): array
{
    if ($auth) $headers[] = 'Authorization: Bearer ' . raAccessToken();
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CUSTOMREQUEST=>$method,
        CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_CONNECTTIMEOUT=>12,
        CURLOPT_TIMEOUT=>90,
        CURLOPT_FOLLOWLOCATION=>true,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($response === false || $err !== '') apiError(503, 'Google-Drive-Verbindung fehlgeschlagen.');
    return ['status'=>$status,'body'=>(string)$response];
}

function raAccessToken(): string
{
    static $token = null;
    if ($token !== null) return $token;

    $serviceJson = trim(env('GOOGLE_DRIVE_SERVICE_ACCOUNT_JSON', ''));
    if ($serviceJson !== '') {
        if (!str_starts_with($serviceJson, '{')) {
            $decoded = base64_decode($serviceJson, true);
            if ($decoded !== false) $serviceJson = $decoded;
        }
        $svc = json_decode($serviceJson, true);
        if (is_array($svc) && !empty($svc['client_email']) && !empty($svc['private_key'])) {
            $now = time();
            $header = raB64url(json_encode(['alg'=>'RS256','typ'=>'JWT'], JSON_UNESCAPED_SLASHES));
            $claims = raB64url(json_encode([
                'iss'=>$svc['client_email'],
                'scope'=>'https://www.googleapis.com/auth/drive',
                'aud'=>'https://oauth2.googleapis.com/token',
                'iat'=>$now,
                'exp'=>$now + 3500,
            ], JSON_UNESCAPED_SLASHES));
            $input = $header.'.'.$claims;
            $signature = '';
            if (openssl_sign($input, $signature, $svc['private_key'], OPENSSL_ALGO_SHA256)) {
                $jwt = $input.'.'.raB64url($signature);
                $resp = raHttp('POST', 'https://oauth2.googleapis.com/token', ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
                    'grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion'=>$jwt,
                ]), false);
                $data = json_decode($resp['body'], true);
                if ($resp['status'] === 200 && !empty($data['access_token'])) return $token = (string)$data['access_token'];
            }
        }
    }

    $clientId = env('GOOGLE_DRIVE_CLIENT_ID', raSettingGet('google_drive_client_id'));
    $clientSecret = env('GOOGLE_DRIVE_CLIENT_SECRET', raSettingGet('google_drive_client_secret'));
    $refreshToken = env('GOOGLE_DRIVE_REFRESH_TOKEN', raSettingGet('google_drive_refresh_token'));
    if ($clientId !== '' && $clientSecret !== '' && $refreshToken !== '') {
        $resp = raHttp('POST', 'https://oauth2.googleapis.com/token', ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
            'client_id'=>$clientId,
            'client_secret'=>$clientSecret,
            'refresh_token'=>$refreshToken,
            'grant_type'=>'refresh_token',
        ]), false);
        $data = json_decode($resp['body'], true);
        if ($resp['status'] === 200 && !empty($data['access_token'])) return $token = (string)$data['access_token'];
    }
    apiError(503, 'Google Drive ist nicht verbunden.');
}

$stmt = db()->prepare('SELECT * FROM report_archive WHERE id=:id LIMIT 1');
$stmt->execute([':id'=>$id]);
$row = $stmt->fetch();
if (!$row) apiError(404, 'Gutachten nicht gefunden.');

$driveId = trim((string)($row['drive_file_id'] ?? ''));
if ($driveId !== '') {
    $resp = raHttp('DELETE', 'https://www.googleapis.com/drive/v3/files/'.rawurlencode($driveId).'?supportsAllDrives=true');
    if (!in_array($resp['status'], [200,204,404], true)) {
        error_log('[report-archive-delete] Drive delete failed '.$resp['status'].' '.substr($resp['body'],0,800));
        apiError(503, 'Gutachten konnte in Google Drive nicht gelöscht werden.');
    }
} else {
    $configured = env('REPORTS_DIR', '');
    $dir = $configured !== '' ? rtrim($configured, '/') : dirname(photosDir()) . '/reports';
    $candidate = $dir.'/'.basename((string)($row['storage_name'] ?? ''));
    if (is_file($candidate) && !@unlink($candidate)) apiError(503, 'Lokale Archivdatei konnte nicht gelöscht werden.');
}

$del = db()->prepare('DELETE FROM report_archive WHERE id=:id');
$del->execute([':id'=>$id]);
apiJson(['ok'=>true,'id'=>$id]);
