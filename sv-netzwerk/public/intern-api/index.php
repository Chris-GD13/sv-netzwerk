<?php
declare(strict_types=1);

/**
 * SVOS Inspection Platform – Front-Controller / Router
 *
 * Alle HTTP-Anfragen an intern-api/ werden hierher geleitet (.htaccess).
 * Dieser Router:
 *   1. Laedt Bootstrap und Config
 *   2. Setzt Security-Header
 *   3. Laedt die aktive Session
 *   4. Validiert CSRF bei state-aendernden Anfragen
 *   5. Leitet an den zustaendigen Controller oder ein Modul weiter
 *   6. Gibt 404 zurueck wenn kein Handler passt
 *
 * URL-Schema:
 *   /intern-api/auth/{action}
 *   /intern-api/users/{id?}
 *   /intern-api/stats/dashboard
 *   /intern-api/modules/{slug}/{...}    ← von Modul-Instanz geroutet
 *   /intern-api/inspections/{id}/photos
 *   /intern-api/inspections/{id}/audit
 *   /intern-api/photos/{id}
 *   /intern-api/photos/{id}/file
 *   /intern-api/export/{type}
 */

require_once __DIR__ . '/bootstrap.php';

use SvIntern\Config\Config;
use SvIntern\Config\Database;
use SvIntern\Middleware\Auth;
use SvIntern\Middleware\Csrf;
use SvIntern\Registry\ModuleRegistry;
use SvIntern\Modules\Windows\WindowModule;
use SvIntern\Controllers\AuthController;
use SvIntern\Controllers\UserController;
use SvIntern\Controllers\PhotoController;
use SvIntern\Controllers\ExportController;
use SvIntern\Controllers\StatsController;
use SvIntern\Controllers\AuditController;

// ── Security-Header ──────────────────────────────────────────────────────────
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Content-Security-Policy: default-src \'none\'');

// ── CORS: nur Same-Origin (kein CORS-Wildcard) ───────────────────────────────
// Keine Access-Control-Allow-Origin Header → Browser akzeptiert nur same-origin

// ── Method + Path ────────────────────────────────────────────────────────────
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// OPTIONS-Preflight (kein CORS, aber Browser sendet es trotzdem manchmal)
if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Pfad aus REQUEST_URI extrahieren, Query-String entfernen
$requestUri  = $_SERVER['REQUEST_URI'] ?? '/';
$scriptPath  = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/');
$path        = urldecode(parse_url($requestUri, PHP_URL_PATH) ?? '/');

// Basis-Pfad entfernen (falls in Unterverzeichnis installiert)
if ($scriptPath !== '' && str_starts_with($path, $scriptPath)) {
    $path = substr($path, strlen($scriptPath));
}

$path     = '/' . ltrim($path, '/');
$segments = array_values(array_filter(explode('/', trim($path, '/'))));

// ── Module registrieren ──────────────────────────────────────────────────────
$registry = ModuleRegistry::getInstance();
$registry->register(new WindowModule());

// ── Session laden ────────────────────────────────────────────────────────────
Auth::startSession();
$session = Auth::getSession();

// ── Oeffentliche Endpunkte (kein Auth erforderlich) ──────────────────────────
// POST /auth/login – CSRF nicht erforderlich (kein Session-Kontext)
if ($segments === ['auth', 'login'] && $method === 'POST') {
    AuthController::login($session);
}

// Alle weiteren Endpunkte erfordern eine gueltigue Session
if ($session === null) {
    jsonError('Nicht angemeldet.', 401);
}

// ── CSRF-Validierung fuer state-aendernde Methoden ───────────────────────────
if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
    Csrf::validate($session);
}

// ── Datenbankverbindung ──────────────────────────────────────────────────────
$db = Database::getInstance();

// ─────────────────────────────────────────────────────────────────────────────
// ROUTING
// ─────────────────────────────────────────────────────────────────────────────

$first  = $segments[0] ?? '';
$second = $segments[1] ?? '';
$third  = $segments[2] ?? '';
$fourth = $segments[3] ?? '';

switch ($first) {

    // ── Auth ──────────────────────────────────────────────────────────────────
    case 'auth':
        match ([$method, $second]) {
            ['GET',  'session'] => AuthController::session($session),
            ['POST', 'logout']  => AuthController::logout($session),
            default => jsonError('Endpunkt nicht gefunden.', 404),
        };
        break;

    // ── Users ─────────────────────────────────────────────────────────────────
    case 'users':
        match ([$method, $second]) {
            ['GET',    '']  => UserController::list($session, $db),
            ['POST',   '']  => UserController::create($session, $db),
            ['PUT',   $second]  when $second !== '' => UserController::update($session, $db, $second),
            ['DELETE', $second] when $second !== '' => UserController::delete($session, $db, $second),
            default => jsonError('Endpunkt nicht gefunden.', 404),
        };
        break;

    // ── Stats ─────────────────────────────────────────────────────────────────
    case 'stats':
        if ($second === 'dashboard' && $method === 'GET') {
            StatsController::dashboard($session, $db);
        }
        jsonError('Endpunkt nicht gefunden.', 404);

    // ── Module-Routing ────────────────────────────────────────────────────────
    // /intern-api/modules/{slug}/...
    case 'modules':
        $slug = $second;
        if ($slug === '') {
            // GET /modules → Modulliste
            if ($method === 'GET') {
                jsonResponse(['data' => $registry->toApiList()]);
            }
            jsonError('Endpunkt nicht gefunden.', 404);
        }

        $module = $registry->findBySlug($slug);
        if ($module === null) {
            jsonError('Modul nicht gefunden: ' . $slug, 404);
        }

        $moduleSegments = array_slice($segments, 2); // Segmente nach /modules/{slug}/
        $handler = $module->route($method, $moduleSegments, $session, $db);
        if ($handler === null) {
            jsonError('Endpunkt nicht gefunden.', 404);
        }
        $handler();
        exit;

    // ── Generische Inspektions-Unterressourcen ────────────────────────────────
    // /intern-api/inspections/{id}/photos
    // /intern-api/inspections/{id}/audit
    case 'inspections':
        $inspectionId = $second;
        if ($inspectionId === '') {
            jsonError('Endpunkt nicht gefunden.', 404);
        }

        match ([$method, $third]) {
            ['GET',  'photos'] => PhotoController::list($session, $db, $inspectionId),
            ['POST', 'photos'] => PhotoController::upload($session, $db, $inspectionId),
            ['GET',  'audit']  => AuditController::list($session, $db, $inspectionId),
            default => jsonError('Endpunkt nicht gefunden.', 404),
        };
        break;

    // ── Fotos ─────────────────────────────────────────────────────────────────
    // /intern-api/photos/{id}
    // /intern-api/photos/{id}/file
    case 'photos':
        $photoId = $second;
        if ($photoId === '') {
            jsonError('Endpunkt nicht gefunden.', 404);
        }

        if ($third === 'file' && $method === 'GET') {
            PhotoController::serve($session, $db, $photoId);
        }
        if ($third === '' && $method === 'DELETE') {
            PhotoController::delete($session, $db, $photoId);
        }
        jsonError('Endpunkt nicht gefunden.', 404);

    // ── Export ────────────────────────────────────────────────────────────────
    // /intern-api/export/csv
    // /intern-api/export/report
    case 'export':
        match ([$method, $second]) {
            ['GET', 'csv']    => ExportController::csv($session, $db),
            ['GET', 'report'] => ExportController::report($session, $db),
            default => jsonError('Endpunkt nicht gefunden.', 404),
        };
        break;

    // ── Fallback ──────────────────────────────────────────────────────────────
    default:
        jsonError('Endpunkt nicht gefunden.', 404);
}
