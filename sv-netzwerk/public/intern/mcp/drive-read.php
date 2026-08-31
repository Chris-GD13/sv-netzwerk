<?php
declare(strict_types=1);

const MCP_CASE_META_NAME = '00_Falldaten.json';
const MCP_DRIVE_FOLDER_MIME = 'application/vnd.google-apps.folder';
const MCP_MAX_CASE_FILES = 250;
const MCP_MAX_FILE_BYTES = 15728640;

function mcpDriveSetting(string $key): string
{
    try {
        $stmt = db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key=:key LIMIT 1');
        $stmt->execute([':key' => $key]);
        return (string)($stmt->fetchColumn() ?: '');
    } catch (Throwable) {
        return '';
    }
}

function mcpDriveB64url(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function mcpDriveHttp(string $method, string $url, array $headers = [], ?string $body = null, bool $authorize = true): array
{
    if ($authorize) $headers[] = 'Authorization: Bearer '.mcpDriveToken();
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_TIMEOUT => 90,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    if ($body !== null) curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
    $response = curl_exec($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $error = curl_error($handle);
    curl_close($handle);
    if ($response === false || $error !== '') throw new RuntimeException('Google Drive ist derzeit nicht erreichbar.');
    return ['status' => $status, 'body' => (string)$response];
}

function mcpDriveToken(): string
{
    static $token = null;
    if ($token !== null) return $token;
    $serviceJson = trim(env('GOOGLE_DRIVE_SERVICE_ACCOUNT_JSON', ''));
    if ($serviceJson !== '') {
        if (!str_starts_with($serviceJson, '{')) $serviceJson = (string)(base64_decode($serviceJson, true) ?: $serviceJson);
        $service = json_decode($serviceJson, true);
        if (is_array($service) && !empty($service['client_email']) && !empty($service['private_key'])) {
            $now = time();
            $header = mcpDriveB64url((string)json_encode(['alg'=>'RS256','typ'=>'JWT']));
            $claims = mcpDriveB64url((string)json_encode([
                'iss'=>$service['client_email'],
                'scope'=>'https://www.googleapis.com/auth/drive.readonly',
                'aud'=>'https://oauth2.googleapis.com/token',
                'iat'=>$now,
                'exp'=>$now + 3500,
            ]));
            $input = $header.'.'.$claims;
            $signature = '';
            if (openssl_sign($input, $signature, (string)$service['private_key'], OPENSSL_ALGO_SHA256)) {
                $response = mcpDriveHttp('POST', 'https://oauth2.googleapis.com/token', ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
                    'grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion'=>$input.'.'.mcpDriveB64url($signature),
                ]), false);
                $data = json_decode($response['body'], true);
                if ($response['status'] === 200 && !empty($data['access_token'])) return $token = (string)$data['access_token'];
            }
        }
    }
    $clientId = env('GOOGLE_DRIVE_CLIENT_ID', mcpDriveSetting('google_drive_client_id'));
    $clientSecret = env('GOOGLE_DRIVE_CLIENT_SECRET', mcpDriveSetting('google_drive_client_secret'));
    $refreshToken = env('GOOGLE_DRIVE_REFRESH_TOKEN', mcpDriveSetting('google_drive_refresh_token'));
    if ($clientId !== '' && $clientSecret !== '' && $refreshToken !== '') {
        $response = mcpDriveHttp('POST', 'https://oauth2.googleapis.com/token', ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
            'client_id'=>$clientId,
            'client_secret'=>$clientSecret,
            'refresh_token'=>$refreshToken,
            'grant_type'=>'refresh_token',
        ]), false);
        $data = json_decode($response['body'], true);
        if ($response['status'] === 200 && !empty($data['access_token'])) return $token = (string)$data['access_token'];
    }
    throw new RuntimeException('Google Drive ist im Prüfportal noch nicht verbunden.');
}

function mcpDriveApi(string $path, array $query = []): array
{
    $query += ['supportsAllDrives' => 'true'];
    $url = 'https://www.googleapis.com/drive/v3/'.ltrim($path, '/').'?'.http_build_query($query);
    $response = mcpDriveHttp('GET', $url);
    if ($response['status'] < 200 || $response['status'] >= 300) throw new RuntimeException('Google-Drive-Dateien konnten nicht gelesen werden.');
    $data = json_decode($response['body'], true);
    return is_array($data) ? $data : [];
}

function mcpDriveChildren(string $parentId): array
{
    $escaped = str_replace("'", "\\'", $parentId);
    $data = mcpDriveApi('files', [
        'q'=>"'{$escaped}' in parents and trashed=false",
        'fields'=>'files(id,name,mimeType,modifiedTime,size,webViewLink,parents)',
        'pageSize'=>1000,
        'orderBy'=>'name_natural',
    ]);
    return is_array($data['files'] ?? null) ? $data['files'] : [];
}

function mcpDriveCaseFiles(string $folderId): array
{
    $result = [];
    $walk = function (string $parentId, string $path, int $depth) use (&$walk, &$result): void {
        if ($depth > 5 || count($result) >= MCP_MAX_CASE_FILES) return;
        foreach (mcpDriveChildren($parentId) as $item) {
            if (count($result) >= MCP_MAX_CASE_FILES) break;
            $name = trim((string)($item['name'] ?? ''));
            $itemPath = ltrim($path.'/'.$name, '/');
            if (($item['mimeType'] ?? '') === MCP_DRIVE_FOLDER_MIME) {
                $walk((string)$item['id'], $itemPath, $depth + 1);
                continue;
            }
            $result[] = [
                'id'=>(string)($item['id'] ?? ''),
                'name'=>$name,
                'path'=>$itemPath,
                'mime_type'=>(string)($item['mimeType'] ?? 'application/octet-stream'),
                'size'=>isset($item['size']) ? (int)$item['size'] : null,
                'modified_at'=>$item['modifiedTime'] ?? null,
            ];
        }
    };
    $walk($folderId, '', 0);
    return $result;
}

function mcpDriveCaseMeta(string $folderId, array $files): array
{
    foreach ($files as $file) {
        if (($file['name'] ?? '') !== MCP_CASE_META_NAME) continue;
        $download = mcpDriveDownload((string)$file['id'], (string)$file['mime_type']);
        $data = json_decode($download['bytes'], true);
        return is_array($data) ? $data : [];
    }
    return [];
}

function mcpDriveFindCaseFile(string $folderId, string $fileId): array
{
    foreach (mcpDriveCaseFiles($folderId) as $file) {
        if (hash_equals((string)$file['id'], $fileId)) return $file;
    }
    throw new RuntimeException('Die Datei gehört nicht zu diesem Schadenfall.');
}

function mcpDriveDownload(string $fileId, string $mimeType): array
{
    $exportMime = null;
    if ($mimeType === 'application/vnd.google-apps.document') $exportMime = 'text/plain';
    if ($mimeType === 'application/vnd.google-apps.spreadsheet') $exportMime = 'text/csv';
    if ($mimeType === 'application/vnd.google-apps.presentation') $exportMime = 'application/pdf';
    $path = 'https://www.googleapis.com/drive/v3/files/'.rawurlencode($fileId);
    $url = $exportMime !== null
        ? $path.'/export?'.http_build_query(['mimeType'=>$exportMime])
        : $path.'?'.http_build_query(['alt'=>'media','supportsAllDrives'=>'true']);
    $response = mcpDriveHttp('GET', $url);
    if ($response['status'] !== 200) throw new RuntimeException('Die ausgewählte Akte konnte nicht aus Google Drive geladen werden.');
    if (strlen($response['body']) > MCP_MAX_FILE_BYTES) throw new RuntimeException('Die Datei ist für die direkte Auswertung größer als 15 MB.');
    return ['bytes'=>$response['body'], 'mime_type'=>$exportMime ?? $mimeType];
}

function mcpDriveIsText(string $mimeType): bool
{
    return str_starts_with($mimeType, 'text/') || in_array($mimeType, [
        'application/json',
        'application/xml',
        'application/csv',
        'application/javascript',
    ], true);
}
