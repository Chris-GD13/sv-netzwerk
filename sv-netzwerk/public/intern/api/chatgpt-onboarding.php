<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/oauth/lib.php';
commonHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') apiError(405, 'GET erforderlich.');
$user = requireAuth();
$email = mb_strtolower(trim((string)($user['email'] ?? '')), 'UTF-8');
$name = mb_strtolower(trim((string)($user['full_name'] ?? '')), 'UTF-8');
$isSusanne = $email === 'ws@sv-schuett.eu' || str_contains($name, 'susanne wächter') || str_contains($name, 'susanne waechter');
$configuredInstallUrl = trim(env('SV_CHATGPT_PLUGIN_INSTALL_URL', ''));
$installUrl = $configuredInstallUrl !== '' ? $configuredInstallUrl : 'https://chatgpt.com/plugins';
$launchUrl = trim(env('SV_CHATGPT_PLUGIN_LAUNCH_URL', '')) ?: ($configuredInstallUrl !== '' ? $configuredInstallUrl : 'https://chatgpt.com/');

if ($isSusanne) {
    apiJson([
        'ok'=>true,
        'required'=>false,
        'shared_account'=>true,
        'account_owner'=>'Christian Wächter',
        'installed'=>true,
        'connected'=>true,
        'install_url'=>$installUrl,
        'launch_url'=>$launchUrl,
        'direct_install'=>$configuredInstallUrl !== '',
    ]);
}

oauthSchema();
$stmt = db()->prepare('SELECT COUNT(*) FROM oauth_refresh_tokens WHERE user_id=:user AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP()');
$stmt->execute([':user'=>(int)$user['id']]);
$installed = (int)$stmt->fetchColumn() > 0;
$stmt = db()->prepare('SELECT COUNT(*) FROM oauth_access_tokens WHERE user_id=:user AND revoked_at IS NULL AND expires_at>UTC_TIMESTAMP()');
$stmt->execute([':user'=>(int)$user['id']]);
$connected = (int)$stmt->fetchColumn() > 0;
apiJson([
    'ok'=>true,
    'required'=>true,
    'shared_account'=>false,
    'plugin_name'=>'SV-Netzwerk Schadenberichte',
    'installed'=>$installed,
    'connected'=>$connected,
    'install_url'=>$installUrl,
    'launch_url'=>$launchUrl,
    'direct_install'=>$configuredInstallUrl !== '',
]);
