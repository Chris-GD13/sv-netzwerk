<?php
declare(strict_types=1);

namespace SvIntern\Controllers;

use SvIntern\Models\AuditLog;
use SvIntern\Modules\Windows\WindowModule;
use SvIntern\Modules\Windows\WindowRecord;
use SvIntern\Services\ExportService;

final class ExportController
{
    /**
     * GET /intern-api/export/csv?module=windows&export_id=...&delimiter=...
     */
    public static function csv(array $session, \PDO $db): never
    {
        $module    = $_GET['module']    ?? 'windows';
        $exportId  = $_GET['export_id'] ?? 'all';
        $delimiter = ($_GET['delimiter'] ?? ',') === ';' ? ';' : ',';

        [$records, $projectId] = self::loadRecords($module, $exportId, $db);

        $service  = new ExportService();
        $csv      = $service->buildCsv($records, $delimiter);
        $fileName = $service->exportFileName(self::filterLabel((string) $exportId), 'csv');

        $auditLog = new AuditLog($db);
        $auditLog->log(
            actorId:    $session['user_id'],
            actorName:  $session['user_name'],
            actionType: 'export',
            entityType: 'inspections',
            entityId:   null,
            fieldName:  'export_id',
            newValue:   $module . '/' . $exportId . ' (' . count($records) . ' Datensaetze)',
            ip:         \clientIp(),
        );

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Cache-Control: no-store');
        echo "\xEF\xBB\xBF" . $csv;
        exit;
    }

    /**
     * GET /intern-api/export/report?module=windows&export_id=...
     */
    public static function report(array $session, \PDO $db): never
    {
        $module   = $_GET['module']    ?? 'windows';
        $exportId = $_GET['export_id'] ?? 'all';

        [$records] = self::loadRecords($module, $exportId, $db);

        $service = new ExportService();
        $title   = self::filterLabel((string) $exportId);
        $html    = $service->buildHtmlReport($records, $title);

        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        echo $html;
        exit;
    }

    /**
     * @return array{list<array<string,mixed>>, string}  [records, projectId]
     */
    private static function loadRecords(string $module, string $exportId, \PDO $db): array
    {
        // Currently only the windows module is implemented.
        // Future modules register themselves and are loaded here via ModuleRegistry.
        if ($module !== 'windows') {
            \jsonError('Unbekanntes Modul: ' . $module, 400);
        }

        $record  = new WindowRecord($db);
        $records = $record->listSummaries(WindowModule::MODULE_ID, WindowModule::DEFAULT_PROJECT_ID);
        $records = self::applyFilter($records, (string) $exportId);

        return [$records, WindowModule::DEFAULT_PROJECT_ID];
    }

    /**
     * @param list<array<string,mixed>> $records
     * @return list<array<string,mixed>>
     */
    private static function applyFilter(array $records, string $exportId): array
    {
        return match ($exportId) {
            'defects'   => array_values(array_filter($records, fn($r) => (bool) $r['has_defect'])),
            'urgent'    => array_values(array_filter($records, fn($r) => (bool) $r['urgent_action_required'])),
            'special'   => array_values(array_filter($records, fn($r) => (bool) $r['special_inspection_required'])),
            'completed' => array_values(array_filter($records, fn($r) => in_array(
                $r['status'] ?? '', ['Pruefung abgeschlossen', 'fachlich geprueft', 'freigegeben'], true
            ))),
            default     => $records,
        };
    }

    private static function filterLabel(string $exportId): string
    {
        return match ($exportId) {
            'defects'   => 'Maengelliste',
            'urgent'    => 'Sofortmassnahmen',
            'special'   => 'Spezialpruefungen',
            'completed' => 'Abgeschlossene_Pruefungen',
            default     => 'Gesamtliste',
        };
    }
}
