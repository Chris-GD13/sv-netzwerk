<?php
declare(strict_types=1);

namespace SvIntern\Controllers;

use SvIntern\Registry\ModuleRegistry;

final class StatsController
{
    private const DEFAULT_PROJECT_ID = '11111111-1111-4111-8111-111111111111';

    /**
     * GET /intern-api/stats/dashboard
     * Aggregiert Statistiken aller registrierten Module.
     */
    public static function dashboard(array $session, \PDO $db): never
    {
        $registry = ModuleRegistry::getInstance();
        $modules  = $registry->all();

        $aggregated = [
            'total'             => 0,
            'notStarted'        => 0,
            'inProgress'        => 0,
            'completed'         => 0,
            'withDefect'        => 0,
            'urgent'            => 0,
            'specialInspection' => 0,
            'inaccessible'      => 0,
            'touchedToday'      => 0,
        ];

        $byModule = [];

        foreach ($modules as $module) {
            $stats = $module->getDashboardStats(self::DEFAULT_PROJECT_ID, $db);
            $byModule[$module->getSlug()] = array_merge(['name' => $module->getName()], $stats);

            foreach (array_keys($aggregated) as $key) {
                $aggregated[$key] += (int) ($stats[$key] ?? 0);
            }
        }

        \jsonResponse(['data' => array_merge($aggregated, ['byModule' => $byModule])]);
    }
}
