<?php
declare(strict_types=1);

namespace SvIntern\Controllers;

use SvIntern\Models\Window;
use SvIntern\Models\AuditLog;
use SvIntern\Services\ExportService;

final class ExportController
{
    private const DEFAULT_PROJECT_ID = '11111111-1111-4111-8111-111111111111';

    /**
     * GET /intern-api/export/csv?export_id=...&delimiter=...
     * Verfuegbare export_id: all, defects, urgent, special, completed
     */
    public static function csv(array $session, \PDO $db): never
    {
        $exportId  = $_GET['export_id'] ?? 'all';
        $delimiter = ($_GET['delimiter'] ?? ',') === ';' ? ';' : ',';

        $model   = new Window($db);
        $records = $model->listSummaries(self::DEFAULT_PROJECT_ID);
        $records = self::applyFilter($records, (string) $exportId);

        $service  = new ExportService();
        $csv      = $service->buildCsv($records, $delimiter);
        $fileName = $service->exportFileName(self::filterLabel($exportId), 'csv');

        // Log Export
        $auditLog = new AuditLog($db);
        $auditLog->log(
            actorId:    $session['user_id'],
            actorName:  $session['user_name'],
            actionType: 'export',
            entityType: 'windows',
            entityId:   null,
            fieldName:  'export_id',
            newValue:   $exportId . ' (' . count($records) . ' Datensaetze)',
            ip:         \clientIp(),
        );

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $fileName . '"');
        header('Cache-Control: no-store');
        // BOM fuer Excel-Kompatibilitaet
        echo "\xEF\xBB\xBF" . $csv;
        exit;
    }

    /**
     * GET /intern-api/export/report?export_id=...
     * Druckbarer HTML-Report (kann im Browser als PDF gespeichert werden).
     */
    public static function report(array $session, \PDO $db): never
    {
        $exportId = $_GET['export_id'] ?? 'all';

        $model   = new Window($db);
        $records = $model->listSummaries(self::DEFAULT_PROJECT_ID);
        $records = self::applyFilter($records, (string) $exportId);

        $service = new ExportService();
        $title   = self::filterLabel((string) $exportId);
        $html    = $service->buildHtmlReport($records, $title);

        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        echo $html;
        exit;
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
