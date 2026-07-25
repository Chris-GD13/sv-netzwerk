<?php
declare(strict_types=1);

/**
 * Bootstrap: Autoloader, Fehlerbehandlung, globale Helfer.
 * Wird von index.php als erstes eingebunden.
 */

// ── Autoloader (PSR-4-aehlich, keine externen Abhaengigkeiten) ──────────────
spl_autoload_register(static function (string $class): void {
    $baseDir  = __DIR__ . '/';
    $classMap = [
        // Config
        'SvIntern\\Config\\Config'                       => 'config/config.php',
        'SvIntern\\Config\\Database'                     => 'config/database.php',
        // Contracts
        'SvIntern\\Contracts\\InspectionModuleInterface' => 'contracts/InspectionModuleInterface.php',
        // Registry
        'SvIntern\\Registry\\ModuleRegistry'             => 'registry/ModuleRegistry.php',
        // Middleware
        'SvIntern\\Middleware\\Auth'                     => 'middleware/Auth.php',
        'SvIntern\\Middleware\\Role'                     => 'middleware/Role.php',
        'SvIntern\\Middleware\\Csrf'                     => 'middleware/Csrf.php',
        // Core models
        'SvIntern\\Models\\User'                         => 'models/User.php',
        'SvIntern\\Models\\Inspection'                   => 'models/Inspection.php',
        'SvIntern\\Models\\Photo'                        => 'models/Photo.php',
        'SvIntern\\Models\\AuditLog'                     => 'models/AuditLog.php',
        // Legacy window model (kept for migration compatibility)
        'SvIntern\\Models\\Window'                       => 'models/Window.php',
        // Services
        'SvIntern\\Services\\AuthService'                => 'services/AuthService.php',
        'SvIntern\\Services\\UploadService'              => 'services/UploadService.php',
        'SvIntern\\Services\\ExportService'              => 'services/ExportService.php',
        // Core controllers
        'SvIntern\\Controllers\\AuthController'          => 'controllers/AuthController.php',
        'SvIntern\\Controllers\\PhotoController'         => 'controllers/PhotoController.php',
        'SvIntern\\Controllers\\ExportController'        => 'controllers/ExportController.php',
        'SvIntern\\Controllers\\UserController'          => 'controllers/UserController.php',
        'SvIntern\\Controllers\\StatsController'         => 'controllers/StatsController.php',
        'SvIntern\\Controllers\\AuditController'         => 'controllers/AuditController.php',
        // Module: windows
        'SvIntern\\Modules\\Windows\\WindowModule'       => 'modules/windows/WindowModule.php',
        'SvIntern\\Modules\\Windows\\WindowRecord'       => 'modules/windows/WindowRecord.php',
    ];

    if (isset($classMap[$class])) {
        require_once $baseDir . $classMap[$class];
    }
});

// ── Fehlerbehandlung ─────────────────────────────────────────────────────────
use SvIntern\Config\Config;

set_exception_handler(static function (\Throwable $e): void {
    $isProduction = Config::isProduction();
    $message = $isProduction ? 'Interner Serverfehler.' : $e->getMessage();

    if ($isProduction) {
        error_log('[sv-intern] ' . $e::class . ': ' . $e->getMessage()
            . ' in ' . $e->getFile() . ':' . $e->getLine());
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
});

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

// ── Globale JSON-Helfer ──────────────────────────────────────────────────────

/**
 * Sendet eine JSON-Antwort und beendet die Ausfuehrung.
 */
function jsonResponse(mixed $data, int $status = 200): never
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

/**
 * Sendet eine standardisierte Fehlerantwort.
 */
function jsonError(string $message, int $status = 400): never
{
    jsonResponse(['error' => $message], $status);
}

/**
 * Liest und validiert den JSON-Request-Body.
 * @return array<string, mixed>
 */
function readJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) {
        return [];
    }
    try {
        $data = json_decode($raw, true, 10, JSON_THROW_ON_ERROR);
        return is_array($data) ? $data : [];
    } catch (\JsonException) {
        return [];
    }
}

/**
 * Erzeugt eine UUID v4 ohne externe Abhaengigkeiten.
 */
function generateUuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40);
    $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

/**
 * Gibt einen sicheren String zurueck oder null wenn leer.
 */
function strOrNull(mixed $value): ?string
{
    $s = trim((string) ($value ?? ''));
    return $s !== '' ? $s : null;
}

/**
 * Gibt einen Integer-Wert zurueck oder null.
 */
function intOrNull(mixed $value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    return filter_var($value, FILTER_VALIDATE_INT) !== false ? (int) $value : null;
}

/**
 * Gibt einen Float-Wert zurueck oder null.
 */
function floatOrNull(mixed $value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }
    $normalized = str_replace(',', '.', (string) $value);
    return is_numeric($normalized) ? (float) $normalized : null;
}

/**
 * Escaped einen String fuer sichere HTML-Ausgabe.
 */
function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Liefert die Client-IP-Adresse (best-effort, nicht vertrauenswuerdig).
 */
function clientIp(): string
{
    return $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['HTTP_X_REAL_IP']
        ?? $_SERVER['REMOTE_ADDR']
        ?? 'unknown';
}
