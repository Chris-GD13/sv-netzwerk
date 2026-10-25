<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/ionos-storage.php';
require_once __DIR__ . '/pdf-ocr.php';
require_once __DIR__ . '/pdf-text-quality.php';

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
    if (!ionosStorageEnabled()) throw new RuntimeException('Der IONOS-Dateispeicher ist derzeit nicht erreichbar.');
    return ionosList(['q'=>"'".str_replace("'", "\\'", $parentId)."' in parents and trashed=false", 'pageSize'=>1000])['files'] ?? [];
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
    if (!ionosStorageEnabled()) throw new RuntimeException('Der IONOS-Dateispeicher ist derzeit nicht erreichbar.');
    $item = ionosItem($fileId);
    $bytes = ionosBytes($fileId);
    if (strlen($bytes) > MCP_MAX_FILE_BYTES) throw new RuntimeException('Die Datei ist für die direkte Auswertung größer als 15 MB.');
    return ['bytes'=>$bytes, 'mime_type'=>(string)($item['mimeType'] ?? $mimeType)];
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

/** Return a bounded text layer for PDFs so MCP clients can review figures without
 * having to decode a binary resource in their own sandbox. */
function mcpDrivePdfText(string $bytes): string
{
    if (!function_exists('exec') || !function_exists('shell_exec') || $bytes === '') return '';
    $binary = trim((string)@shell_exec('command -v pdftotext 2>/dev/null'));
    if ($binary === '') return '';
    $tmp = tempnam(sys_get_temp_dir(), 'mcp-pdf-');
    if ($tmp === false || @file_put_contents($tmp, $bytes) !== strlen($bytes)) { if ($tmp) @unlink($tmp); return ''; }
    try {
        $out = [];
        @exec(escapeshellarg($binary).' -layout '.escapeshellarg($tmp).' - 2>/dev/null', $out, $code);
        if ($code !== 0) return '';
        $text = trim(implode("\n", $out));
        // Some PDFs expose a font-encoded glyph stream which is technically a
        // text layer but is not readable (control characters and glyph IDs).
        // Do not present that as usable text to the model.
        if (!mcpPdfTextUsable($text)) return '';
        return mb_substr($text, 0, 500000);
    } finally { @unlink($tmp); }
}

/** Render pages when a PDF's embedded font map makes its text layer unusable. */
function mcpDrivePdfImages(string $bytes): array
{
    if (!function_exists('exec') || $bytes === '') return [];
    $binary = trim((string)@shell_exec('command -v pdftoppm 2>/dev/null'));
    if ($binary === '') return [];
    $tmp = tempnam(sys_get_temp_dir(), 'mcp-pdf-');
    if ($tmp === false || @file_put_contents($tmp, $bytes) !== strlen($bytes)) { if ($tmp) @unlink($tmp); return []; }
    $dir = $tmp.'_pages'; @mkdir($dir, 0700, true);
    try {
        $prefix = $dir.'/page'; $out = []; $code = 1;
        @exec(escapeshellarg($binary).' -png -f 1 -l 6 -scale-to 1600 '.escapeshellarg($tmp).' '.escapeshellarg($prefix).' 2>/dev/null', $out, $code);
        if ($code !== 0) return [];
        $images = [];
        foreach (glob($prefix.'-*.png') ?: [] as $path) {
            $image = @file_get_contents($path);
            if ($image !== false && strlen($image) <= 2500000) $images[] = ['mimeType'=>'image/png', 'data'=>base64_encode($image)];
        }
        return array_slice($images, 0, 6);
    } finally {
        foreach (glob($dir.'/*') ?: [] as $path) @unlink($path);
        @rmdir($dir); @unlink($tmp);
    }
}
