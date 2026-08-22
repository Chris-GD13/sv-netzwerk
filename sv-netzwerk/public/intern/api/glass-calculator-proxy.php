<?php
declare(strict_types=1);

// Der Rechner wird ausschließlich innerhalb des eigenen Portals gerahmt.
header_remove('X-Frame-Options');
header('X-Frame-Options: SAMEORIGIN');
header("Content-Security-Policy: frame-ancestors 'self'");

// Eng begrenzter Lesepunkt für den öffentlichen BarmeniaGothaer-Glasrechner.
// Es werden ausschließlich index.html und statische Dateien aus dessen assets-Ordner ausgeliefert.
$base = 'https://www.gothaer.de/app/Bedarfsrechner/web/';
$path = isset($_GET['path']) ? (string) $_GET['path'] : 'index.html';
if ($path !== 'index.html' && !preg_match('~^assets/[A-Za-z0-9._-]+$~', $path)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Ungültige Rechnerressource.');
}

$url = $base . $path;
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
if ($path === 'index.html') {
    $body = preg_replace_callback(
        '~(?<=["\'])((?:https://www\.gothaer\.de)?/app/Bedarfsrechner/web/)?(assets/[A-Za-z0-9._-]+)(?=["\'])~',
        static fn(array $match): string => $proxy . rawurlencode($match[2]),
        (string) $body
    );
    $type = 'text/html; charset=utf-8';
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
