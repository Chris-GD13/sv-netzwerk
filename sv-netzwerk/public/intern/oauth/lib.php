<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/config.php';

const SV_OAUTH_ISSUER = 'https://www.sv-netzwerk.eu';
const SV_MCP_RESOURCE = 'https://www.sv-netzwerk.eu/intern/mcp/';
const SV_OAUTH_SCOPES = ['cases:read', 'cases:drafts.write'];

function oauthSchema(): void
{
    static $ready = false;
    if ($ready) return;
    $ready = true;
    db()->exec("CREATE TABLE IF NOT EXISTS oauth_clients (
        client_id VARCHAR(190) PRIMARY KEY,
        client_name VARCHAR(255) NOT NULL,
        redirect_uris_json TEXT NOT NULL,
        created_at DATETIME NOT NULL,
        last_used_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS oauth_authorization_codes (
        code_hash CHAR(64) PRIMARY KEY,
        client_id VARCHAR(190) NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        redirect_uri VARCHAR(1000) NOT NULL,
        resource_uri VARCHAR(1000) NOT NULL,
        scope VARCHAR(500) NOT NULL,
        code_challenge VARCHAR(190) NOT NULL,
        expires_at DATETIME NOT NULL,
        used_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        KEY idx_oauth_code_client (client_id),
        KEY idx_oauth_code_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS oauth_access_tokens (
        token_hash CHAR(64) PRIMARY KEY,
        client_id VARCHAR(190) NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        resource_uri VARCHAR(1000) NOT NULL,
        scope VARCHAR(500) NOT NULL,
        expires_at DATETIME NOT NULL,
        revoked_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        last_used_at DATETIME NULL,
        KEY idx_oauth_token_user (user_id),
        KEY idx_oauth_token_expiry (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS oauth_refresh_tokens (
        token_hash CHAR(64) PRIMARY KEY,
        client_id VARCHAR(190) NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        resource_uri VARCHAR(1000) NOT NULL,
        scope VARCHAR(500) NOT NULL,
        expires_at DATETIME NOT NULL,
        revoked_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        last_used_at DATETIME NULL,
        KEY idx_oauth_refresh_user (user_id),
        KEY idx_oauth_refresh_expiry (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("DELETE FROM oauth_authorization_codes WHERE expires_at < UTC_TIMESTAMP() OR used_at IS NOT NULL");
}

function oauthJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('Pragma: no-cache');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function oauthError(string $error, string $description, int $status = 400): never
{
    oauthJson(['error' => $error, 'error_description' => $description], $status);
}

function oauthToken(int $bytes = 32): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

function oauthClient(string $clientId): ?array
{
    oauthSchema();
    $stmt = db()->prepare('SELECT * FROM oauth_clients WHERE client_id=:id LIMIT 1');
    $stmt->execute([':id' => $clientId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function oauthRedirectAllowed(array $client, string $uri): bool
{
    $allowed = json_decode((string)$client['redirect_uris_json'], true);
    return is_array($allowed) && in_array($uri, $allowed, true);
}

function oauthNormalizeScope(string $scope): string
{
    $requested = array_values(array_unique(array_filter(preg_split('/\s+/', trim($scope)) ?: [])));
    if (!$requested) $requested = ['cases:read'];
    foreach ($requested as $item) {
        if (!in_array($item, SV_OAUTH_SCOPES, true)) oauthError('invalid_scope', 'Nicht unterstützte Berechtigung.');
    }
    return implode(' ', $requested);
}

function oauthBearerUserOrNull(string $requiredScope = 'cases:read'): ?array
{
    oauthSchema();
    $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $match)) return null;
    $hash = hash('sha256', trim($match[1]));
    $stmt = db()->prepare(
        'SELECT t.user_id,t.scope,t.resource_uri,u.id,u.email,u.full_name,u.role,u.is_active
         FROM oauth_access_tokens t JOIN users u ON u.id=t.user_id
         WHERE t.token_hash=:hash AND t.revoked_at IS NULL AND t.expires_at>UTC_TIMESTAMP() LIMIT 1'
    );
    $stmt->execute([':hash' => $hash]);
    $user = $stmt->fetch();
    if (!$user || !(bool)$user['is_active'] || !hash_equals(SV_MCP_RESOURCE, (string)$user['resource_uri'])) return null;
    $scopes = preg_split('/\s+/', trim((string)$user['scope'])) ?: [];
    if (!in_array($requiredScope, $scopes, true)) return null;
    db()->prepare('UPDATE oauth_access_tokens SET last_used_at=UTC_TIMESTAMP() WHERE token_hash=:hash')->execute([':hash' => $hash]);
    return $user;
}

function oauthBearerUser(string $requiredScope = 'cases:read'): array
{
    $user = oauthBearerUserOrNull($requiredScope);
    if (!$user) oauthChallenge($requiredScope);
    return $user;
}

function oauthChallenge(string $scope): never
{
    http_response_code(401);
    header('WWW-Authenticate: Bearer resource_metadata="https://www.sv-netzwerk.eu/.well-known/oauth-protected-resource", scope="'.$scope.'"');
    oauthJson(['error' => 'unauthorized', 'error_description' => 'OAuth-Anmeldung am SV-Netzwerk erforderlich.'], 401);
}

function oauthSafeRedirect(string $uri, array $params): never
{
    $separator = str_contains($uri, '?') ? '&' : '?';
    header('Location: '.$uri.$separator.http_build_query($params));
    exit;
}
