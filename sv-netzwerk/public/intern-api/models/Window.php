<?php
declare(strict_types=1);

namespace SvIntern\Models;

/**
 * Fenster-Datensatz-Modell fuer MySQL 8.0.
 * Alle DB-Zugriffe verwenden ausschliesslich Prepared Statements.
 */
final class Window
{
    public function __construct(private readonly \PDO $db) {}

    /**
     * Gibt alle sichtbaren Fenster-Zusammenfassungen zurueck (kein deleted_at).
     * @return list<array<string,mixed>>
     */
    public function listSummaries(string $projectId): array
    {
        $stmt = $this->db->prepare(
            'SELECT w.id, w.record_id, w.inspection_number, w.window_number,
                    w.room_number, w.room_label, w.building_label, w.section_label,
                    w.floor_label, w.status, w.overall_rating, w.priority,
                    w.accessibility_status, w.assigned_to, w.assigned_name,
                    w.special_inspection_required, w.urgent_action_required,
                    w.has_defect, w.danger_immediate, w.last_edited_at,
                    w.updated_at, w.progress_percent,
                    rl.owner_id AS lock_owner_id,
                    rl.owner_name AS lock_owner_name,
                    rl.expires_at AS lock_expires_at
             FROM windows w
             LEFT JOIN record_locks rl
               ON rl.window_id = w.id AND rl.expires_at > NOW() AND rl.released_at IS NULL
             WHERE w.project_id = :project_id
               AND w.deleted_at IS NULL
             ORDER BY w.inspection_number ASC, w.window_number ASC'
        );
        $stmt->execute([':project_id' => $projectId]);
        return $stmt->fetchAll();
    }

    /**
     * Gibt einen vollstaendigen Fenster-Datensatz zurueck.
     * @return array<string,mixed>|null
     */
    public function findById(string $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM windows WHERE id = :id AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        // JSON-Felder dekodieren
        $row['form_data']       = json_decode((string) ($row['form_data'] ?? '{}'), true) ?? [];
        $row['calculated_data'] = json_decode((string) ($row['calculated_data'] ?? '{}'), true) ?? [];
        return $row;
    }

    /**
     * Legt einen neuen Fenster-Datensatz an.
     */
    public function create(array $data): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO windows (
                id, project_id, record_id, inspection_number, window_number,
                room_number, room_label, building_label, section_label, floor_label,
                accessibility_status, status, overall_rating, priority,
                assigned_to, assigned_name,
                special_inspection_required, urgent_action_required,
                has_defect, danger_immediate, progress_percent,
                form_data, calculated_data, last_edited_at
            ) VALUES (
                :id, :project_id, :record_id, :inspection_number, :window_number,
                :room_number, :room_label, :building_label, :section_label, :floor_label,
                :accessibility_status, :status, :overall_rating, :priority,
                :assigned_to, :assigned_name,
                :special_inspection_required, :urgent_action_required,
                :has_defect, :danger_immediate, :progress_percent,
                :form_data, :calculated_data, NOW()
            )'
        );
        $stmt->execute([
            ':id'                         => $data['id'],
            ':project_id'                 => $data['project_id'],
            ':record_id'                  => $data['record_id'],
            ':inspection_number'          => $data['inspection_number'] ?? null,
            ':window_number'              => $data['window_number'] ?? '',
            ':room_number'                => $data['room_number'] ?? null,
            ':room_label'                 => $data['room_label'] ?? null,
            ':building_label'             => $data['building_label'] ?? null,
            ':section_label'              => $data['section_label'] ?? null,
            ':floor_label'                => $data['floor_label'] ?? null,
            ':accessibility_status'       => $data['accessibility_status'] ?? null,
            ':status'                     => $data['status'] ?? 'nicht begonnen',
            ':overall_rating'             => $data['overall_rating'] ?? null,
            ':priority'                   => $data['priority'] ?? null,
            ':assigned_to'                => $data['assigned_to'] ?? null,
            ':assigned_name'              => $data['assigned_name'] ?? null,
            ':special_inspection_required' => $data['special_inspection_required'] ? 1 : 0,
            ':urgent_action_required'     => $data['urgent_action_required'] ? 1 : 0,
            ':has_defect'                 => $data['has_defect'] ? 1 : 0,
            ':danger_immediate'           => $data['danger_immediate'] ? 1 : 0,
            ':progress_percent'           => $data['progress_percent'] ?? 0,
            ':form_data'                  => json_encode($data['form_data'] ?? []),
            ':calculated_data'            => json_encode($data['calculated_data'] ?? []),
        ]);
    }

    /**
     * Speichert einen Fenster-Datensatz (Upsert via UPDATE).
     */
    public function save(string $id, array $data, string $userId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE windows SET
                inspection_number          = :inspection_number,
                window_number              = :window_number,
                room_number                = :room_number,
                room_label                 = :room_label,
                building_label             = :building_label,
                section_label              = :section_label,
                floor_label                = :floor_label,
                accessibility_status       = :accessibility_status,
                status                     = :status,
                overall_rating             = :overall_rating,
                priority                   = :priority,
                assigned_to                = :assigned_to,
                assigned_name              = :assigned_name,
                special_inspection_required = :special_inspection_required,
                urgent_action_required     = :urgent_action_required,
                has_defect                 = :has_defect,
                danger_immediate           = :danger_immediate,
                progress_percent           = :progress_percent,
                form_data                  = :form_data,
                calculated_data            = :calculated_data,
                last_edited_at             = NOW(),
                completed_at               = :completed_at,
                release_reason             = :release_reason,
                version                    = version + 1
             WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute([
            ':id'                          => $id,
            ':inspection_number'           => \intOrNull($data['inspection_number'] ?? null),
            ':window_number'               => (string) ($data['window_number'] ?? ''),
            ':room_number'                 => \strOrNull($data['room_number'] ?? null),
            ':room_label'                  => \strOrNull($data['room_label'] ?? null),
            ':building_label'              => \strOrNull($data['building_label'] ?? null),
            ':section_label'               => \strOrNull($data['section_label'] ?? null),
            ':floor_label'                 => \strOrNull($data['floor_label'] ?? null),
            ':accessibility_status'        => \strOrNull($data['accessibility_status'] ?? null),
            ':status'                      => (string) ($data['status'] ?? 'in Bearbeitung'),
            ':overall_rating'              => \strOrNull($data['overall_rating'] ?? null),
            ':priority'                    => \strOrNull($data['priority'] ?? null),
            ':assigned_to'                 => $userId,
            ':assigned_name'               => \strOrNull($data['assigned_name'] ?? null),
            ':special_inspection_required' => empty($data['special_inspection_required']) ? 0 : 1,
            ':urgent_action_required'      => empty($data['urgent_action_required']) ? 0 : 1,
            ':has_defect'                  => empty($data['has_defect']) ? 0 : 1,
            ':danger_immediate'            => empty($data['danger_immediate']) ? 0 : 1,
            ':progress_percent'            => \floatOrNull($data['progress_percent'] ?? null) ?? 0,
            ':form_data'                   => json_encode($data['form_data'] ?? []),
            ':calculated_data'             => json_encode($data['calculated_data'] ?? []),
            ':completed_at'                => ($data['status'] ?? '') === 'Pruefung abgeschlossen'
                ? date('Y-m-d H:i:s')
                : ($data['completed_at'] ?? null),
            ':release_reason'              => \strOrNull($data['release_reason'] ?? null),
        ]);
    }

    /**
     * Soft-Delete eines Fensters.
     */
    public function softDelete(string $id): void
    {
        $stmt = $this->db->prepare(
            'UPDATE windows SET deleted_at = NOW() WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
    }

    /**
     * Setzt eine Datensatzsperre (atomische Transaktion).
     * @return array{ok:bool,lock_id?:string,owner_id?:string,owner_name?:string,expires_at?:string,message:string}
     */
    public function acquireLock(string $windowId, string $userId, string $userName, int $timeoutMinutes): array
    {
        $this->db->beginTransaction();
        try {
            // Pruefe ob Fenster existiert
            $stmt = $this->db->prepare('SELECT id FROM windows WHERE id = :id AND deleted_at IS NULL FOR UPDATE');
            $stmt->execute([':id' => $windowId]);
            if (!$stmt->fetch()) {
                $this->db->rollBack();
                return ['ok' => false, 'message' => 'Fenster nicht gefunden.'];
            }

            // Pruefe aktive fremde Sperre
            $stmt = $this->db->prepare(
                'SELECT id, owner_id, owner_name, expires_at
                 FROM record_locks
                 WHERE window_id = :window_id
                   AND expires_at > NOW()
                   AND released_at IS NULL
                   AND owner_id != :user_id
                 LIMIT 1'
            );
            $stmt->execute([':window_id' => $windowId, ':user_id' => $userId]);
            $existing = $stmt->fetch();
            if ($existing) {
                $this->db->rollBack();
                return [
                    'ok'         => false,
                    'owner_id'   => $existing['owner_id'],
                    'owner_name' => $existing['owner_name'],
                    'expires_at' => $existing['expires_at'],
                    'message'    => 'Datensatz ist von ' . $existing['owner_name'] . ' gesperrt.',
                ];
            }

            // Sperre setzen (INSERT … ON DUPLICATE KEY UPDATE)
            $lockId  = \generateUuid();
            $expires = date('Y-m-d H:i:s', time() + $timeoutMinutes * 60);
            $stmt = $this->db->prepare(
                'INSERT INTO record_locks (id, window_id, owner_id, owner_name, expires_at)
                 VALUES (:id, :window_id, :owner_id, :owner_name, :expires_at)
                 ON DUPLICATE KEY UPDATE
                   id         = VALUES(id),
                   owner_id   = VALUES(owner_id),
                   owner_name = VALUES(owner_name),
                   expires_at = VALUES(expires_at),
                   released_at = NULL,
                   released_by = NULL'
            );
            $stmt->execute([
                ':id'         => $lockId,
                ':window_id'  => $windowId,
                ':owner_id'   => $userId,
                ':owner_name' => $userName,
                ':expires_at' => $expires,
            ]);

            $this->db->commit();
            return [
                'ok'         => true,
                'lock_id'    => $lockId,
                'owner_id'   => $userId,
                'owner_name' => $userName,
                'expires_at' => $expires,
                'message'    => 'Sperre aktiv.',
            ];
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Hebt eine Datensatzsperre auf.
     */
    public function releaseLock(string $windowId, string $userId, string $role): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE record_locks SET released_at = NOW(), released_by = :user_id
             WHERE window_id = :window_id
               AND (owner_id = :owner_id OR :is_admin = 1)'
        );
        $stmt->execute([
            ':window_id' => $windowId,
            ':user_id'   => $userId,
            ':owner_id'  => $userId,
            ':is_admin'  => $role === 'administrator' ? 1 : 0,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Gibt die Berechnungsparameter zurueck.
     * @return array<string,float>
     */
    public function getCalculationParameters(): array
    {
        $stmt = $this->db->query(
            "SELECT parameter_key, parameter_value FROM calculation_parameters WHERE project_id IS NULL"
        );
        $params = [];
        foreach ($stmt->fetchAll() as $row) {
            $params[(string) $row['parameter_key']] = (float) $row['parameter_value'];
        }
        return $params;
    }
}
