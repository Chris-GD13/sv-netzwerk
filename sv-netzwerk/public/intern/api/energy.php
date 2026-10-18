<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';
require_once __DIR__.'/energy-access.php';
commonHeaders();
$user = requireAuth();
header('Cache-Control: no-store, private');
$method = $_SERVER['REQUEST_METHOD'] ?? '';
if (!in_array($method, ['GET', 'POST'], true)) apiError(405, 'Methode nicht zulässig.');
if (($_GET['action'] ?? '') === 'access') {
    if ($method !== 'GET') apiError(405, 'Nur GET ist zulässig.');
    apiJson(['ok'=>true, 'visible'=>svnetCanAccessEnergy($user)]);
}
if (!svnetCanAccessEnergy($user)) apiError(403, 'Die Energiesteuerung ist für dieses Konto nicht freigegeben.');
if (isset($_POST['download'])) {
    if ($method !== 'POST') apiError(405, 'Nur POST ist zulässig.');
    if (($_SERVER['HTTP_ORIGIN'] ?? '') !== 'https://www.sv-netzwerk.eu') apiError(403, 'Download nur aus dem Portal zulässig.');
    $profiles = ['handy'=>'handy.conf', 'surface'=>'surface.conf', 'laptop'=>'laptop.conf'];
    $file = $profiles[(string)$_POST['download']] ?? null;
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
if ($method !== 'GET') apiError(405, 'Nur GET ist zulässig.');
$destination = svnetEnergyDestination((string)($_GET['service'] ?? ''));
if ($destination === null) apiError(404, 'Unbekannter Dienst.');
header('Referrer-Policy: no-referrer');
header('Location: '.$destination, true, 302);
exit();

