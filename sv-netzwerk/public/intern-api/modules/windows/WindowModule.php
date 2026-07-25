<?php
declare(strict_types=1);

namespace SvIntern\Modules\Windows;

use SvIntern\Contracts\InspectionModuleInterface;
use SvIntern\Models\Inspection;
use SvIntern\Models\AuditLog;
use SvIntern\Middleware\Role;

/**
 * Modul: Fensterbeschlagspruefung (BMVg Bonn).
 *
 * Erstes Inspektionsmodul der SVOS Inspection Platform.
 * Implementiert InspectionModuleInterface und delegiert an WindowController
 * (nun als module-lokale Klasse, nicht in /controllers/).
 */
final class WindowModule implements InspectionModuleInterface
{
    public const MODULE_ID      = '00000000-0000-4000-8000-000000000001';
    public const MODULE_SLUG    = 'windows';
    public const MODULE_NAME    = 'Fensterbeschlagspruefung';
    public const MODULE_VERSION = '1.0.0';

    public const DEFAULT_PROJECT_ID = '11111111-1111-4111-8111-111111111111';

    public function getSlug(): string    { return self::MODULE_SLUG; }
    public function getName(): string    { return self::MODULE_NAME; }
    public function getId(): string      { return self::MODULE_ID; }
    public function getVersion(): string { return self::MODULE_VERSION; }

    /**
     * Routet /intern-api/modules/windows/{...} an den passenden Handler.
     *
     * Segmente nach dem Slug:
     *   inspections              → list / create
     *   inspections/{id}         → get / update / delete
     *   inspections/{id}/lock    → acquire / release
     *   inspections/{id}/audit   → audit log
     *   calculation-parameters   → parameter table
     */
    public function route(string $method, array $segments, array $session, \PDO $db): ?callable
    {
        $record = new WindowRecord($db);
        $insp   = new Inspection($db);
        $audit  = new AuditLog($db);
        $ip     = \clientIp();

        // inspections
        if ($segments === ['inspections']) {
            return match ($method) {
                'GET'  => fn() => $this->listInspections($session, $db, $record),
                'POST' => fn() => $this->createInspection($session, $db, $record, $insp, $audit, $ip),
                default => null,
            };
        }

        // inspections/{id}
        if (count($segments) === 2 && $segments[0] === 'inspections') {
            $id = $segments[1];
            return match ($method) {
                'GET'    => fn() => $this->getInspection($session, $db, $record, $id),
                'PUT'    => fn() => $this->updateInspection($session, $db, $record, $insp, $audit, $ip, $id),
                'DELETE' => fn() => $this->deleteInspection($session, $db, $insp, $audit, $ip, $id),
                default  => null,
            };
        }

        // inspections/{id}/lock
        if (count($segments) === 3 && $segments[0] === 'inspections' && $segments[2] === 'lock') {
            $id = $segments[1];
            return match ($method) {
                'POST'   => fn() => $this->acquireLock($session, $insp, $id, $ip),
                'DELETE' => fn() => $this->releaseLock($session, $insp, $id),
                default  => null,
            };
        }

        // inspections/{id}/audit
        if (count($segments) === 3 && $segments[0] === 'inspections' && $segments[2] === 'audit') {
            $id = $segments[1];
            return ($method === 'GET')
                ? fn() => \jsonResponse(['data' => $audit->listByInspection($id)])
                : null;
        }

        // calculation-parameters
        if ($segments === ['calculation-parameters'] && $method === 'GET') {
            return fn() => \jsonResponse(['data' => $record->getCalculationParameters()]);
        }

        return null;
    }

    public function getDashboardStats(string $projectId, \PDO $db): array
    {
        $record  = new WindowRecord($db);
        $records = $record->listSummaries(self::MODULE_ID, $projectId);
        $today   = date('Y-m-d');

        return [
            'total'             => count($records),
            'notStarted'        => count(array_filter($records, fn($r) => $r['status'] === 'nicht begonnen')),
            'inProgress'        => count(array_filter($records, fn($r) => in_array($r['status'], ['in Bearbeitung', 'Pruefung unterbrochen'], true))),
            'completed'         => count(array_filter($records, fn($r) => in_array($r['status'], ['Pruefung abgeschlossen', 'fachlich geprueft', 'freigegeben'], true))),
            'withDefect'        => count(array_filter($records, fn($r) => (bool) $r['has_defect'])),
            'urgent'            => count(array_filter($records, fn($r) => (bool) $r['urgent_action_required'])),
            'specialInspection' => count(array_filter($records, fn($r) => (bool) $r['special_inspection_required'])),
            'inaccessible'      => count(array_filter($records, fn($r) => $r['accessibility_status'] === 'nicht zugaenglich')),
            'touchedToday'      => count(array_filter($records, fn($r) => str_starts_with((string) ($r['updated_at'] ?? ''), $today))),
        ];
    }

    // ── Handler ────────────────────────────────────────────────────────────────

    private function listInspections(array $session, \PDO $db, WindowRecord $record): never
    {
        $rows = $record->listSummaries(self::MODULE_ID, self::DEFAULT_PROJECT_ID);
        \jsonResponse(['data' => $rows]);
    }

    private function getInspection(array $session, \PDO $db, WindowRecord $record, string $id): never
    {
        $row = $record->findByInspectionId($id);
        if ($row === null) {
            \jsonError('Inspektion nicht gefunden.', 404);
        }
        \jsonResponse(['data' => $row]);
    }

    private function createInspection(
        array $session, \PDO $db,
        WindowRecord $record, Inspection $insp, AuditLog $audit,
        string $ip
    ): never {
        Role::require($session, Role::PRUEFER);
        $body = \readJsonBody();
        $id   = \generateUuid();

        $insp->create([
            'id'           => $id,
            'module_id'    => self::MODULE_ID,
            'project_id'   => self::DEFAULT_PROJECT_ID,
            'record_id'    => 'BMVG-' . strtoupper(substr($id, 0, 8)),
            'status'       => 'nicht begonnen',
            'assigned_to'  => $session['user_id'],
            'assigned_name' => $session['user_name'],
            'created_by'   => $session['user_id'],
        ]);

        $record->create($id, [
            'window_number'        => (string) ($body['window_number'] ?? ''),
            'inspection_number'    => \strOrNull($body['inspection_number'] ?? null),
            'accessibility_status' => (string) ($body['accessibility_status'] ?? 'zugaenglich'),
            'building_label'       => \strOrNull($body['building_label'] ?? null),
            'section_label'        => \strOrNull($body['section_label'] ?? null),
            'floor_label'          => \strOrNull($body['floor_label'] ?? null),
            'room_number'          => \strOrNull($body['room_number'] ?? null),
            'room_label'           => \strOrNull($body['room_label'] ?? null),
            'form_data'            => $body['form_data'] ?? [],
            'calculated_data'      => [],
        ]);

        $audit->log(
            actorId:    $session['user_id'],
            actorName:  $session['user_name'],
            actionType: 'create',
            entityType: 'inspection',
            entityId:   $id,
            inspectionId: $id,
            ip:         $ip,
        );

        \jsonResponse(['data' => ['id' => $id]], 201);
    }

    private function updateInspection(
        array $session, \PDO $db,
        WindowRecord $record, Inspection $insp, AuditLog $audit,
        string $ip, string $id
    ): never {
        if (!Role::canEdit($session)) {
            \jsonError('Nicht autorisiert.', 403);
        }

        $row = $record->findByInspectionId($id);
        if ($row === null) {
            \jsonError('Inspektion nicht gefunden.', 404);
        }

        $body      = \readJsonBody();
        $newStatus = \strOrNull($body['status'] ?? null) ?? $row['status'];
        $oldStatus = $row['status'];

        // Freigabe nur fuer Admin
        if (in_array($newStatus, ['freigegeben', 'fachlich geprueft'], true) && !Role::isAdmin($session)) {
            \jsonError('Freigabe erfordert Administrator-Rolle.', 403);
        }

        // Kern-Felder
        $coreChanges = array_intersect_key($body, array_flip([
            'status', 'overall_rating', 'priority', 'has_defect',
            'danger_immediate', 'special_inspection_required',
            'urgent_action_required', 'progress_percent',
            'assigned_to', 'assigned_name', 'completed_at',
        ]));
        if (!empty($coreChanges)) {
            $insp->save($id, $coreChanges, $session['user_id']);
        }

        // Fenster-spezifische Felder
        $windowChanges = array_intersect_key($body, array_flip([
            'inspection_number', 'window_number', 'accessibility_status',
            'building_label', 'section_label', 'floor_label',
            'room_number', 'room_label', 'form_data', 'calculated_data',
        ]));
        if (!empty($windowChanges)) {
            $record->save($id, $windowChanges);
        }

        if ($newStatus !== $oldStatus) {
            $audit->log(
                actorId:    $session['user_id'],
                actorName:  $session['user_name'],
                actionType: 'update',
                entityType: 'inspection',
                entityId:   $id,
                fieldName:  'status',
                oldValue:   $oldStatus,
                newValue:   $newStatus,
                inspectionId: $id,
                ip:         $ip,
            );
        }

        \jsonResponse(['ok' => true]);
    }

    private function deleteInspection(
        array $session, \PDO $db,
        Inspection $insp, AuditLog $audit,
        string $ip, string $id
    ): never {
        Role::require($session, Role::ADMINISTRATOR);
        if ($insp->findById($id) === null) {
            \jsonError('Inspektion nicht gefunden.', 404);
        }
        $insp->softDelete($id);
        $audit->log(
            actorId:    $session['user_id'],
            actorName:  $session['user_name'],
            actionType: 'delete',
            entityType: 'inspection',
            entityId:   $id,
            inspectionId: $id,
            ip:         $ip,
        );
        \jsonResponse(['ok' => true]);
    }

    private function acquireLock(array $session, Inspection $insp, string $id, string $ip): never
    {
        $result = $insp->acquireLock($id, $session['user_id'], $session['user_name'], 15);
        \jsonResponse($result, $result['ok'] ? 200 : 409);
    }

    private function releaseLock(array $session, Inspection $insp, string $id): never
    {
        $insp->releaseLock($id, $session['user_id'], $session['user_role']);
        \jsonResponse(['ok' => true]);
    }
}
