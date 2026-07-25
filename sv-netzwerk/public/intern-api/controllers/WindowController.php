<?php
declare(strict_types=1);

namespace SvIntern\Controllers;

use SvIntern\Middleware\Role;
use SvIntern\Models\Window;
use SvIntern\Models\AuditLog;

final class WindowController
{
    private const DEFAULT_PROJECT_ID = '11111111-1111-4111-8111-111111111111';

    /** GET /intern-api/windows */
    public static function list(array $session, \PDO $db): never
    {
        $model   = new Window($db);
        $records = $model->listSummaries(self::DEFAULT_PROJECT_ID);
        \jsonResponse(['data' => $records]);
    }

    /** GET /intern-api/windows/{id} */
    public static function get(array $session, \PDO $db, string $id): never
    {
        $model  = new Window($db);
        $record = $model->findById($id);
        if ($record === null) {
            \jsonError('Fenster nicht gefunden.', 404);
        }
        \jsonResponse(['data' => $record]);
    }

    /** POST /intern-api/windows */
    public static function create(array $session, \PDO $db): never
    {
        Role::require($session, Role::PRUEFER);
        $body = \readJsonBody();
        $id   = \generateUuid();
        $sourceId = \strOrNull($body['source_id'] ?? null);

        $formData = [
            'status'         => 'nicht begonnen',
            'inspection_date' => date('Y-m-d'),
            'inspector_name'  => $session['user_name'],
        ];

        if ($sourceId !== null) {
            $model  = new Window($db);
            $source = $model->findById($sourceId);
            if ($source !== null) {
                $formData = array_merge(
                    $source['form_data'] ?? [],
                    ['status' => 'vorbereitet', 'completion_confirmed' => false]
                );
                unset($formData['release_reason']);
            }
        }

        $data = [
            'id'            => $id,
            'project_id'    => self::DEFAULT_PROJECT_ID,
            'record_id'     => 'BMVG-' . strtoupper(substr($id, 0, 8)),
            'window_number' => (string) ($body['window_number'] ?? ''),
            'status'        => (string) ($formData['status'] ?? 'nicht begonnen'),
            'assigned_to'   => $session['user_id'],
            'assigned_name' => $session['user_name'],
            'form_data'     => $formData,
            'calculated_data' => [],
            'has_defect'    => false,
            'danger_immediate' => false,
            'special_inspection_required' => false,
            'urgent_action_required' => false,
            'progress_percent' => 0,
        ];

        $model = new Window($db);
        $model->create($data);

        $auditLog = new AuditLog($db);
        $auditLog->log(
            actorId:    $session['user_id'],
            actorName:  $session['user_name'],
            actionType: 'create',
            entityType: 'window',
            entityId:   $id,
            windowId:   $id,
            ip:         \clientIp(),
        );

        \jsonResponse(['data' => ['id' => $id, 'record_id' => $data['record_id']]], 201);
    }

    /** PUT /intern-api/windows/{id} */
    public static function update(array $session, \PDO $db, string $id): never
    {
        $model  = new Window($db);
        $record = $model->findById($id);
        if ($record === null) {
            \jsonError('Fenster nicht gefunden.', 404);
        }

        // Auswertungs-Rolle darf nur bestimmte Felder aendern
        $role = $session['user_role'];
        if (!Role::canEdit($session) && $role !== Role::AUSWERTUNG) {
            \jsonError('Nicht autorisiert.', 403);
        }

        $body = \readJsonBody();

        // Pruefe ob Freigabe-Status nur von Admin geaendert werden darf
        $newStatus = \strOrNull($body['status'] ?? null) ?? $record['status'];
        $isReleaseTransition = in_array($newStatus, ['freigegeben', 'fachlich geprueft'], true);
        if ($isReleaseTransition && !Role::isAdmin($session)) {
            \jsonError('Freigabe erfordert Administrator-Rolle.', 403);
        }

        $oldStatus = $record['status'];
        $model->save($id, $body, $session['user_id']);

        // Audit-Log bei Status-Aenderung
        if ($newStatus !== $oldStatus) {
            $auditLog = new AuditLog($db);
            $auditLog->log(
                actorId:    $session['user_id'],
                actorName:  $session['user_name'],
                actionType: 'update',
                entityType: 'window',
                entityId:   $id,
                fieldName:  'status',
                oldValue:   $oldStatus,
                newValue:   $newStatus,
                windowId:   $id,
                ip:         \clientIp(),
            );
        }

        \jsonResponse(['ok' => true]);
    }

    /** DELETE /intern-api/windows/{id} */
    public static function delete(array $session, \PDO $db, string $id): never
    {
        Role::require($session, Role::ADMINISTRATOR);
        $model = new Window($db);
        if ($model->findById($id) === null) {
            \jsonError('Fenster nicht gefunden.', 404);
        }
        $model->softDelete($id);

        $auditLog = new AuditLog($db);
        $auditLog->log(
            actorId:    $session['user_id'],
            actorName:  $session['user_name'],
            actionType: 'delete',
            entityType: 'window',
            entityId:   $id,
            windowId:   $id,
            ip:         \clientIp(),
        );

        \jsonResponse(['ok' => true]);
    }

    /** POST /intern-api/windows/{id}/lock */
    public static function acquireLock(array $session, \PDO $db, string $id): never
    {
        $model  = new Window($db);
        $result = $model->acquireLock($id, $session['user_id'], $session['user_name'], 15);
        \jsonResponse($result, $result['ok'] ? 200 : 409);
    }

    /** DELETE /intern-api/windows/{id}/lock */
    public static function releaseLock(array $session, \PDO $db, string $id): never
    {
        $model = new Window($db);
        $model->releaseLock($id, $session['user_id'], $session['user_role']);
        \jsonResponse(['ok' => true]);
    }

    /** GET /intern-api/calculation-parameters */
    public static function calculationParameters(array $session, \PDO $db): never
    {
        $model  = new Window($db);
        $params = $model->getCalculationParameters();
        \jsonResponse(['data' => $params]);
    }
}
