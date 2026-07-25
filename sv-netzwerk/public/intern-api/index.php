<?php
declare(strict_types=1);

/**
 * Fensterbeschlagspruefung BMVg Bonn – API-Front-Controller
 *
 * Alle HTTP-Anfragen an /intern-api/ werden hierher geleitet (.htaccess).
 *
 * URL-Schema:
 *   POST   /intern-api/auth/login
 *   GET    /intern-api/auth/session
 *   POST   /intern-api/auth/logout
 *
 *   GET    /intern-api/users
 *   POST   /intern-api/users
 *   PUT    /intern-api/users/{id}
 *   DELETE /intern-api/users/{id}
 *
 *   GET    /intern-api/stats/dashboard
 *
 *   GET    /intern-api/windows
 *   POST   /intern-api/windows
 *   GET    /intern-api/windows/{id}
 *   PUT    /intern-api/windows/{id}
 *   DELETE /intern-api/windows/{id}
 *   POST   /intern-api/windows/{id}/lock
 *   DELETE /intern-api/windows/{id}/lock
 *   GET    /intern-api/windows/{id}/photos
 *   POST   /intern-api/windows/{id}/photos
 *   GET    /intern-api/windows/{id}/audit
 *
 *   DELETE /intern-api/photos/{id}
 *   GET    /intern-api/photos/{id}/file
 *
 *   GET    /intern-api/calculation-parameters
 *
 *   GET    /intern-api/export/csv
 *   GET    /intern-api/export/report
 */

require_once __DIR__ . '/bootstrap.php';

use SvIntern\Config\Database;
use SvIntern\Middleware\Auth;
use SvIntern\Middleware\Csrf;
use SvIntern\Controllers\AuthController;
use SvIntern\Controllers\WindowController;
use SvIntern\Controllers\PhotoController;
use SvIntern\Controllers\ExportController;
use SvIntern\Controllers\UserController;
use SvIntern\Controllers\StatsController;
use SvIntern\Controllers\AuditController;

// ── Security-Header ──────────────────────────────────────────────────────────
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Content-Security-Policy: default-src \'none\'');

// ── Method + Path ────────────────────────────────────────────────────────────
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$uri      = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$uri      = urldecode($uri);
$base     = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
$path     = $base !== '' && str_starts_with($uri, $base) ? substr($uri, strlen($base)) : $uri;
$segments = array_values(array_filter(explode('/', trim($path, '/'))));

// ── Session ──────────────────────────────────────────────────────────────────
Auth::startSession();
$session = Auth::getSession();

// ── Oeffentlicher Endpunkt: Login (kein Auth, kein CSRF erforderlich) ────────
if ($segments === ['auth', 'login'] && $method === 'POST') {
    AuthController::login($session);
}

// ── Ab hier: Session erforderlich ────────────────────────────────────────────
if ($session === null) {
    jsonError('Nicht angemeldet.', 401);
}

// ── CSRF-Validierung bei state-aendernden Methoden ───────────────────────────
if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    Csrf::validate($session);
}

// ── Datenbankverbindung ──────────────────────────────────────────────────────
$db = Database::getInstance();

// ── Routing ──────────────────────────────────────────────────────────────────
$s0 = $segments[0] ?? '';
$s1 = $segments[1] ?? '';
$s2 = $segments[2] ?? '';
$s3 = $segments[3] ?? '';

match (true) {

    // Auth
    $s0 === 'auth' && $s1 === 'session' && $method === 'GET'
        => AuthController::session($session),

    $s0 === 'auth' && $s1 === 'logout' && $method === 'POST'
        => AuthController::logout($session),

    // Users
    $s0 === 'users' && $s1 === '' && $method === 'GET'
        => UserController::list($session, $db),

    $s0 === 'users' && $s1 === '' && $method === 'POST'
        => UserController::create($session, $db),

    $s0 === 'users' && $s1 !== '' && $s2 === '' && $method === 'PUT'
        => UserController::update($session, $db, $s1),

    $s0 === 'users' && $s1 !== '' && $s2 === '' && $method === 'DELETE'
        => UserController::delete($session, $db, $s1),

    // Stats
    $s0 === 'stats' && $s1 === 'dashboard' && $method === 'GET'
        => StatsController::dashboard($session, $db),

    // Windows – Liste / Anlegen
    $s0 === 'windows' && $s1 === '' && $method === 'GET'
        => WindowController::list($session, $db),

    $s0 === 'windows' && $s1 === '' && $method === 'POST'
        => WindowController::create($session, $db),

    // Calculation parameters (before {id} route to avoid collision)
    $s0 === 'calculation-parameters' && $method === 'GET'
        => WindowController::calculationParameters($session, $db),

    // Windows – Einzelzugriff
    $s0 === 'windows' && $s1 !== '' && $s2 === '' && $method === 'GET'
        => WindowController::get($session, $db, $s1),

    $s0 === 'windows' && $s1 !== '' && $s2 === '' && $method === 'PUT'
        => WindowController::update($session, $db, $s1),

    $s0 === 'windows' && $s1 !== '' && $s2 === '' && $method === 'DELETE'
        => WindowController::delete($session, $db, $s1),

    // Windows – Sperren
    $s0 === 'windows' && $s1 !== '' && $s2 === 'lock' && $method === 'POST'
        => WindowController::acquireLock($session, $db, $s1),

    $s0 === 'windows' && $s1 !== '' && $s2 === 'lock' && $method === 'DELETE'
        => WindowController::releaseLock($session, $db, $s1),

    // Windows – Fotos
    $s0 === 'windows' && $s1 !== '' && $s2 === 'photos' && $method === 'GET'
        => PhotoController::list($session, $db, $s1),

    $s0 === 'windows' && $s1 !== '' && $s2 === 'photos' && $method === 'POST'
        => PhotoController::upload($session, $db, $s1),

    // Windows – Audit-Log
    $s0 === 'windows' && $s1 !== '' && $s2 === 'audit' && $method === 'GET'
        => AuditController::list($session, $db, $s1),

    // Fotos – Einzelaktionen
    $s0 === 'photos' && $s1 !== '' && $s2 === 'file' && $method === 'GET'
        => PhotoController::serve($session, $db, $s1),

    $s0 === 'photos' && $s1 !== '' && $s2 === '' && $method === 'DELETE'
        => PhotoController::delete($session, $db, $s1),

    // Export
    $s0 === 'export' && $s1 === 'csv' && $method === 'GET'
        => ExportController::csv($session, $db),

    $s0 === 'export' && $s1 === 'report' && $method === 'GET'
        => ExportController::report($session, $db),

    // Fallback
    default => jsonError('Endpunkt nicht gefunden.', 404),
};
