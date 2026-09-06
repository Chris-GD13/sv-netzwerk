<?php

declare(strict_types=1);

require_once __DIR__ . '/api/config.php';

commonHeaders();
startSession();

$user = currentUser();
if ($user === null) {
    $_SESSION = [];

    if (session_status() === PHP_SESSION_ACTIVE) {
        $cookie = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $cookie['path'],
            'domain'   => $cookie['domain'],
            'secure'   => $cookie['secure'],
            'httponly' => $cookie['httponly'],
            'samesite' => $cookie['samesite'] ?? 'Strict',
        ]);
        session_destroy();
    }

    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/intern/');
    $requestPath = parse_url($requestUri, PHP_URL_PATH);
    if (!is_string($requestPath) || !str_starts_with($requestPath, '/intern/')) {
        $requestPath = '/intern/';
    }

    header('Location: /intern/login/?next=' . rawurlencode($requestPath), true, 302);
    exit();
}
