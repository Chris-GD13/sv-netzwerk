<?php
/**
 * Berechnungsparameter API – SV-Netzwerk Prüfportal
 *
 * GET /api/parameters.php – Globale Berechnungsparameter
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

commonHeaders();
requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    apiError(405, 'Methode nicht erlaubt.');
}

try {
    $rows = db()->query(
        'SELECT parameter_key, parameter_value FROM calculation_parameters WHERE project_id IS NULL'
    )->fetchAll();
} catch (Throwable) {
    // Tabelle noch nicht vorhanden → Standardwerte zurückgeben
    $rows = [];
}

$params = [
    'glassDensityKgPerM2Mm' => 2.5,
    'frameWeightFactor'     => 0.18,
    'safetyFactor'          => 1.1,
];
foreach ($rows as $row) {
    if (isset($params[$row['parameter_key']])) {
        $params[$row['parameter_key']] = (float) $row['parameter_value'];
    }
}

apiJson($params);
