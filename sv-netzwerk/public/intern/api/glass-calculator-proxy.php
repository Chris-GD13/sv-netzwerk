<?php
declare(strict_types=1);

// Der Rechner wird ausschließlich innerhalb des eigenen Portals gerahmt.
header_remove('X-Frame-Options');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: frame-ancestors 'self'");

// Eng begrenzter Lesepunkt für den öffentlichen BarmeniaGothaer-Glasrechner.
// Es werden ausschließlich index.html und statische Dateien aus dessen assets-Ordner ausgeliefert.
$webBase = 'https://www.gothaer.de/app/Bedarfsrechner/web/';
$apiBase = 'https://www.gothaer.de/app/Bedarfsrechner/api/public/';
$api = ltrim(isset($_GET['api']) ? (string) $_GET['api'] : '', '/');
$allowedApi = ['frontenddaten/materiale', 'frontenddaten/selectoptionen', 'frontenddaten/version_info', 'berechnen', 'drucken'];
$path = isset($_GET['path']) ? (string) $_GET['path'] : 'index.html';
if ($api !== '' && !in_array($api, $allowedApi, true)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Ungültiger Rechneraufruf.');
}
if ($api === '' && $path !== 'index.html' && !preg_match('~^assets/(?:modelle/)?[A-Za-z0-9._-]+$~', $path)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Ungültige Rechnerressource.');
}

$url = $api !== '' ? $apiBase . $api : $webBase . $path;
$body = false;
$status = 0;
$type = '';
if (function_exists('curl_init')) {
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'SV-Netzwerk Glasrechner/1.0',
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    if ($api !== '' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, file_get_contents('php://input'));
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'Accept: application/json, application/pdf']);
    }
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $type = (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
    curl_close($curl);
} else {
    $context = stream_context_create(['http' => ['timeout' => 20, 'user_agent' => 'SV-Netzwerk Glasrechner/1.0']]);
    $body = @file_get_contents($url, false, $context);
    $status = $body === false ? 502 : 200;
}

if ($body === false || $status < 200 || $status >= 300) {
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Der offizielle Glas-Erstattungspreisrechner ist momentan nicht erreichbar.');
}

$proxy = '/intern/api/glass-calculator-proxy.php?path=';
if ($api !== '') {
    // Antworttyp der offiziellen API (JSON bzw. PDF) unverändert weiterreichen.
} elseif ($path === 'index.html') {
    $body = preg_replace_callback(
        '~(?<=["\'])((?:https://www\.gothaer\.de)?/app/Bedarfsrechner/web/)?(assets/[A-Za-z0-9._-]+)(?=["\'])~',
        static fn(array $match): string => $proxy . rawurlencode($match[2]),
        (string) $body
    );
    $type = 'text/html; charset=utf-8';
} elseif (str_ends_with($path, '.js')) {
    $body = str_replace(
        ['/app/Bedarfsrechner/api/public', '/app/Bedarfsrechner/web/assets/modelle/'],
        ['/intern/api/glass-calculator-proxy.php?api=', '/intern/api/glass-calculator-proxy.php?path=assets%2Fmodelle%2F'],
        (string) $body
    );
    $type = 'application/javascript; charset=utf-8';
} elseif (str_ends_with($path, '.css')) {
    $body = preg_replace_callback(
        '~url\((?:["\']?)(?:\./)?([A-Za-z0-9._-]+)(?:["\']?)\)~',
        static fn(array $match): string => 'url("' . $proxy . rawurlencode('assets/' . $match[1]) . '")',
        (string) $body
    );
    $type = 'text/css; charset=utf-8';
}

header('Content-Type: ' . ($type ?: 'application/octet-stream'));
header('Cache-Control: public, max-age=900, stale-while-revalidate=3600');
header('X-Content-Type-Options: nosniff');
echo $body;
