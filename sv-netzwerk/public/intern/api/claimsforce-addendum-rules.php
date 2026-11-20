<?php
declare(strict_types=1);
require_once __DIR__.'/services/RuleDriveRead.php';

function cfaDrive(string $path, array $query = []): string
{
    $response = svRuleHttp('GET', 'https://www.googleapis.com/drive/v3/files'.$path.'?'.http_build_query($query));
    if ($response['status'] !== 200) throw new RuntimeException('Regelstand: Google Drive antwortet mit HTTP '.$response['status'].'.');
    return $response['body'];
}

function cfaRuleManifest(string $route): array
{
    $root = '1XKQSSk3tFtwd4C-y0b1Av0SKvyfrf6N1';
    $master = '17DnTx3-g9Rn7NEMebwB4HTDKe592kwrC';
    $open = '1iCg2VzF9UfBWzYu3icA6m0Mc1PRll5sl';
    $routing = '1A8xR5lE64juKpXiqa459AyEzs3zrHOG9';
    $query = "trashed = false and ('$root' in parents or '$master' in parents or '$open' in parents or name = '00_FALLART_ROUTING.md')";
    $sources = [];
    $masters = 0;
    $token = '';
    do {
        $params = ['q'=>$query, 'pageSize'=>100, 'fields'=>'nextPageToken,incompleteSearch,files(id,name,parents,mimeType,modifiedTime,version)',
            'supportsAllDrives'=>'true', 'includeItemsFromAllDrives'=>'true'];
        if ($token !== '') $params['pageToken'] = $token;
        $page = json_decode(cfaDrive('', $params), true, 512, JSON_THROW_ON_ERROR);
        if (!empty($page['incompleteSearch'])) throw new RuntimeException('Regelstand: Google Drive meldet eine unvollständige Suche.');
        foreach ($page['files'] ?? [] as $file) {
            $id = $file['id'];
            $parents = $file['parents'] ?? [];
            $name = $file['name'];
            $mime = $file['mimeType'];
            if (str_starts_with($mime, 'image/') || $mime === 'application/vnd.google-apps.folder') continue;
            if ($id === $routing) $role = 'routing';
            elseif (in_array($master, $parents, true) && str_starts_with($name, 'MASTER-ARBEITSSTANDARD')) { $role = 'master'; $masters++; }
            elseif (in_array($open, $parents, true) || in_array($root, $parents, true) && preg_match('/Neuerung|VERBINDLICH/iu', $name)) {
                if ($route !== 'sv_gf' && preg_match('/(?:_GF_|Engel)/iu', $name)) continue;
                $role = 'update';
            } else continue;
            $sources[$id] = ['id'=>$id, 'name'=>$name, 'role'=>$role, 'mime'=>$mime,
                'modifiedAt'=>$file['modifiedTime'] ?? '', 'version'=>(string)($file['version'] ?? '')];
        }
        $token = $page['nextPageToken'] ?? '';
    } while ($token !== '');
    if (!isset($sources[$routing]) || $masters !== 1) throw new RuntimeException('Regelstand: Fallart-Routing und aktueller Master müssen eindeutig in Drive verfügbar sein.');
    ksort($sources);
    return $sources;
}

function cfaRoute(string $insurer, string $claim): string
{
    if (preg_match('/(?:Sparkassen\s*Versicherung|^SV$)/iu', trim($insurer))) {
        return preg_match('/\bGF\b/i', $claim) ? 'sv_gf' : 'sv_standard';
    }
    return 'claimsforce';
}

function cfaSender(string $master): array
{
    if (!preg_match('/konsolidierte Fassung\s+([\d.]+)/u', $master, $version)
        || !preg_match('/Unter jeden fertigen Bericht, Nachtrag, Brief und E-Mail-Text:\s*\n((?:[^\n]+\n){3,10}?[^\n]*https?:\/\/[^\n]+)/u', $master, $block)) {
        throw new RuntimeException('Regelstand / Absender: Der verbindliche Block im Master konnte nicht eindeutig gelesen werden.');
    }
    return [$version[1], cfaText("Mit freundlichen Grüßen\n".$block[1])];
}

function cfaRules(string $route, ?array $previous = null): array
{
    $sources = cfaRuleManifest($route);
    if ($sources === ($previous['sources'] ?? null)) return $previous;
    $read = [];
    foreach (['routing','master','update'] as $role) {
        foreach ($sources as $id=>$source) {
            if ($source['role'] !== $role) continue;
            if (($previous['sources'][$id] ?? null) === $source && $role !== 'master') {
                $read[$id] = $previous['contentHashes'][$id];
                continue;
            }
            $path = '/'.rawurlencode($id);
            $native = $source['mime'] === 'application/vnd.google-apps.document';
            $content = str_replace("\r\n", "\n", cfaDrive($path.($native ? '/export' : ''),
                $native ? ['mimeType'=>'text/plain'] : ['alt'=>'media', 'supportsAllDrives'=>'true']));
            if (trim($content) === '') throw new RuntimeException('Regelstand: Leere Quelle '.$source['name'].'.');
            $read[$id] = hash('sha256', $content);
            if ($role === 'master') [$version,$sender] = cfaSender($content);
        }
    }
    if ($sources !== cfaRuleManifest($route)) throw new RuntimeException('Regelstand: Drive-Quellen wurden beim Lesen geändert. Bitte neu laden.');
    return ['route'=>$route, 'sources'=>$sources, 'contentHashes'=>$read, 'masterVersion'=>$version,
        'sender'=>$sender, 'readAt'=>gmdate('c')];
}
