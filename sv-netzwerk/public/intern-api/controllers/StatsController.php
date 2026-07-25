<?php
declare(strict_types=1);

namespace SvIntern\Controllers;

use SvIntern\Models\Window;

final class StatsController
{
    private const DEFAULT_PROJECT_ID = '11111111-1111-4111-8111-111111111111';

    /** GET /intern-api/stats/dashboard */
    public static function dashboard(array $session, \PDO $db): never
    {
        $model   = new Window($db);
        $records = $model->listSummaries(self::DEFAULT_PROJECT_ID);
        $today   = date('Y-m-d');

        $byInspectorMap = [];
        foreach ($records as $r) {
            $key  = $r['assigned_to'] ?? $r['assigned_name'] ?? 'unassigned';
            $name = (string) ($r['assigned_name'] ?? 'Nicht zugewiesen');
            if (!isset($byInspectorMap[$key])) {
                $byInspectorMap[$key] = ['id' => $key, 'name' => $name, 'total' => 0, 'completed' => 0];
            }
            $byInspectorMap[$key]['total']++;
            if (in_array($r['status'] ?? '', ['Pruefung abgeschlossen', 'fachlich geprueft', 'freigegeben'], true)) {
                $byInspectorMap[$key]['completed']++;
            }
        }

        usort($byInspectorMap, fn($a, $b) => $b['total'] - $a['total']);

        $sorted = $records;
        usort($sorted, fn($a, $b) => strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? '')));
        $recent = array_slice($sorted, 0, 8);

        \jsonResponse(['data' => [
            'total'              => count($records),
            'notStarted'         => count(array_filter($records, fn($r) => $r['status'] === 'nicht begonnen')),
            'inProgress'         => count(array_filter($records, fn($r) => in_array($r['status'], ['in Bearbeitung', 'Pruefung unterbrochen'], true))),
            'completed'          => count(array_filter($records, fn($r) => in_array($r['status'], ['Pruefung abgeschlossen', 'fachlich geprueft', 'freigegeben'], true))),
            'withDefect'         => count(array_filter($records, fn($r) => (bool) $r['has_defect'])),
            'urgent'             => count(array_filter($records, fn($r) => (bool) $r['urgent_action_required'])),
            'specialInspection'  => count(array_filter($records, fn($r) => (bool) $r['special_inspection_required'])),
            'inaccessible'       => count(array_filter($records, fn($r) => $r['accessibility_status'] === 'nicht zugaenglich')),
            'touchedToday'       => count(array_filter($records, fn($r) => str_starts_with((string)($r['updated_at'] ?? ''), $today))),
            'byInspector'        => array_values($byInspectorMap),
            'recentChanges'      => array_map(fn($r) => [
                'id'        => $r['id'],
                'label'     => $r['window_number'] ?: $r['record_id'],
                'updatedAt' => $r['updated_at'],
                'user'      => $r['assigned_name'],
                'status'    => $r['status'],
            ], $recent),
        ]]);
    }
}
