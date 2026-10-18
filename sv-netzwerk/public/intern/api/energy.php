<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
require_once __DIR__.'/energy-access.php';
commonHeaders();
$user = requireAuth();
header('Cache-Control: no-store, private');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') apiError(405, 'Nur GET ist zulässig.');
if (($_GET['action'] ?? '') === 'access') {
    apiJson(['ok'=>true, 'visible'=>svnetCanAccessEnergy($user)]);
}
if (!svnetCanAccessEnergy($user)) apiError(403, 'Die Energiesteuerung ist für dieses Konto nicht freigegeben.');
if (isset($_GET['download'])) {
    $profiles = ['handy'=>'handy.conf', 'surface'=>'surface.conf', 'laptop'=>'laptop.conf'];
    $file = $profiles[(string)$_GET['download']] ?? null;
    if ($file === null) apiError(404, 'Unbekanntes Gerät.');
    $path = '/opt/evcc-energy/client-profiles/'.$file;
    if (!is_readable($path)) apiError(503, 'Die VPN-Konfiguration ist noch nicht verfügbar.');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="energie-'.$file.'"');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    readfile($path);
    exit();
}
$destination = svnetEnergyDestination((string)($_GET['service'] ?? ''));
if ($destination === null) apiError(404, 'Unbekannter Dienst.');
header('Referrer-Policy: no-referrer');
header('Location: '.$destination, true, 302);
exit();
