<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
commonHeaders();
requireAuth();

/**
 * Foto-only-Quelle fuer das Projekt Bundesministerium Verteidigung Bonn.
 * Der lokale OneDrive-Pfad des Anwenders
 * C:\Users\chris\OneDrive - SV Büro Marc Schütt e.K\Dokumente - SV Büro Schütt\VS Schäden\Marc\Privatgutachten\2026\Bundesministerium Verteidigung_Bonn
 * entspricht serverseitig diesem SharePoint-Pfad. Der Server greift bewusst
 * ueber Microsoft Graph auf SharePoint zu und nicht auf das lokale C:-Laufwerk.
 */
const PROJECT_PHOTO_ROOT = 'VS Schäden/Marc/Privatgutachten/2026/Bundesministerium Verteidigung_Bonn';

function sppConfig(string $key, string $default = ''): string
{
    $value = getenv($key);
    return $value === false || trim($value) === '' ? $default : trim($value);
}

function sppAccessToken(): string
{
    static $token = null;
    if (is_string($token) && $token !== '') return $token;

    $tenantId = sppConfig('MS_TENANT_ID');
    $clientId = sppConfig('MS_CLIENT_ID');
    $clientSecret = sppConfig('MS_CLIENT_SECRET');
    if ($tenantId === '' || $clientId === '' || $clientSecret === '') {
        apiError(503, 'Die SharePoint-Verbindung ist auf dem Server noch nicht vollständig eingerichtet.');
    }

    $curl = curl_init('https://login.microsoftonline.com/' . rawurlencode($tenantId) . '/oauth2/v2.0/token');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS => http_build_query([
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials',
        ]),
    ]);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    $decoded = is_string($response) ? json_decode($response, true) : null;
    if ($status < 200 || $status >= 300 || !is_array($decoded) || empty($decoded['access_token'])) {
        apiError(503, 'Microsoft-Anmeldung für den SharePoint-Fotoimport fehlgeschlagen.');
    }
    return $token = (string) $decoded['access_token'];
}

function sppRequest(string $url): array
{
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . sppAccessToken()],
    ]);
    $response = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if ($status < 200 || $status >= 300 || !is_string($response)) {
        apiError(503, 'SharePoint konnte für den Fotoimport nicht gelesen werden (HTTP ' . $status . ').');
    }
    $decoded = json_decode($response, true);
    if (!is_array($decoded)) apiError(503, 'SharePoint hat eine ungültige Antwort geliefert.');
    return $decoded;
}

function sppSiteId(): string
{
    $configured = sppConfig('MS_SHAREPOINT_SITE_ID');
    if ($configured !== '') return $configured;
    $host = sppConfig('MS_SHAREPOINT_HOST', 'sv1schuett.sharepoint.com');
    $path = sppConfig('MS_SHAREPOINT_SITE_PATH', '/sites/SVBroSchtt');
    $site = sppRequest('https://graph.microsoft.com/v1.0/sites/' . rawurlencode($host) . ':' . str_replace('%2F', '/', rawurlencode($path)) . '?$select=id');
    $id = (string) ($site['id'] ?? '');
    if ($id === '') apiError(503, 'Die konfigurierte SharePoint-Site wurde nicht gefunden.');
    return $id;
}

function sppDriveId(): string
{
    $configured = sppConfig('MS_SHAREPOINT_DRIVE_ID');
    if ($configured !== '') return $configured;
    $drive = sppRequest('https://graph.microsoft.com/v1.0/sites/' . rawurlencode(sppSiteId()) . '/drive?$select=id');
    $id = (string) ($drive['id'] ?? '');
    if ($id === '') apiError(503, 'Die SharePoint-Dokumentbibliothek wurde nicht gefunden.');
    return $id;
}

function sppItemByPath(string $path): array
{
    $segments = array_values(array_filter(explode('/', trim($path, '/')), static fn($part) => $part !== ''));
    $parentId = '';
    $matched = null;
    foreach ($segments as $segment) {
        $url = 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode(sppDriveId())
            . ($parentId === '' ? '/root/children' : '/items/' . rawurlencode($parentId) . '/children')
            . '?$select=id,name,size,file,folder&$top=200';
        $matched = null;
        while ($url !== '') {
            $page = sppRequest($url);
            foreach (($page['value'] ?? []) as $item) {
                if (is_array($item) && strcasecmp((string) ($item['name'] ?? ''), $segment) === 0) {
                    $matched = $item;
                    break 2;
                }
            }
            $url = (string) ($page['@odata.nextLink'] ?? '');
        }
        if (!is_array($matched) || empty($matched['id'])) apiError(404, 'SharePoint-Pfad nicht gefunden: ' . $segment);
        $parentId = (string) $matched['id'];
    }
    return is_array($matched) ? $matched : [];
}

$root = sppItemByPath(PROJECT_PHOTO_ROOT);
$rootId = (string) ($root['id'] ?? '');
if ($rootId === '') apiError(404, 'Der Projektordner für den Fotoimport wurde nicht gefunden.');

$photos = [];
$foldersScanned = 0;
$queue = [[$rootId, '', 0]];
while ($queue !== []) {
    [$parentId, $relativePath, $depth] = array_shift($queue);
    if ($depth > 10) continue;
    $foldersScanned++;
    $url = 'https://graph.microsoft.com/v1.0/drives/' . rawurlencode(sppDriveId()) . '/items/' . rawurlencode((string) $parentId)
        . '/children?$select=id,name,size,file,folder,createdDateTime,lastModifiedDateTime&$top=200';
    while ($url !== '') {
        $page = sppRequest($url);
        foreach (($page['value'] ?? []) as $item) {
            if (!is_array($item)) continue;
            $name = (string) ($item['name'] ?? '');
            $itemPath = ltrim($relativePath . '/' . $name, '/');
            if (!empty($item['folder'])) {
                $queue[] = [(string) ($item['id'] ?? ''), $itemPath, $depth + 1];
                continue;
            }
            if (empty($item['file']) || !preg_match('/\.(jpe?g|png|webp|tiff?|heic|heif)$/i', $name)) continue;
            $photos[] = [
                'id' => (string) ($item['id'] ?? ''),
                'name' => $name,
                'path' => $itemPath,
                'size' => (int) ($item['size'] ?? 0),
                'mime_type' => (string) ($item['file']['mimeType'] ?? 'application/octet-stream'),
            ];
        }
        $url = (string) ($page['@odata.nextLink'] ?? '');
    }
}

usort($photos, static fn(array $a, array $b): int => strnatcasecmp((string) $a['path'], (string) $b['path']));
apiJson([
    'ok' => true,
    'folder_name' => (string) ($root['name'] ?? basename(PROJECT_PHOTO_ROOT)),
    'source_path' => PROJECT_PHOTO_ROOT,
    'recursive' => true,
    'folders_scanned' => $foldersScanned,
    'photos' => $photos,
]);
