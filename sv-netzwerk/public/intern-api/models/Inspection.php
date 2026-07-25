<?php
declare(strict_types=1);

namespace SvIntern\Models;

/**
 * Generisches Inspektions-Modell.
 *
 * Verwaltet die `inspections`-Kerntabelle, die von allen
 * Inspektionsmodulen gemeinsam genutzt wird.
 * Modul-spezifische Felder liegen in eigenen Tabellen
 * (z. B. `window_records`) und werden per JOIN angereichert.
 */
final class Inspection
{
    public function __construct(private readonly \PDO $db) {}

    // ── Lesen ──────────────────────────────────────────────────────────────────

    /**
     * Alle Inspektionen eines Moduls in einem Projekt.
     * @return list<array<string,mixed>>
     */
    public function listByModule(string $moduleId, string $projectId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, module_id, project_id, record_id, status, overall_rating,
                    priority, has_defect, danger_immediate, special_inspection_required,
                    urgent_action_required, progress_percent, assigned_to, assigned_name,
                    completed_at, version, created_by, created_at, updated_at
             FROM inspections
             WHERE module_id = :module_id
               AND project_id = :project_id
               AND deleted_at IS NULL
             ORDER BY created_at ASC'
        );
        $stmt->execute([':module_id' => $moduleId, ':project_id' => $projectId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findById(string $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, module_id, project_id, record_id, status, overall_rating,
                    priority, has_defect, danger_immediate, special_inspection_required,
                    urgent_action_required, progress_percent, assigned_to, assigned_name,
                    completed_at, version, created_by, created_at, updated_at
             FROM inspections
             WHERE id = :id AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    // ── Schreiben ──────────────────────────────────────────────────────────────

    /**
     * Erstellt eine neue Inspektion.
     * @param array<string,mixed> $data
     */
    public function create(array $data): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO inspections (
                id, module_id, project_id, record_id, status,
                overall_rating, priority, has_defect, danger_immediate,
                special_inspection_required, urgent_action_required,
                progress_percent, assigned_to, assigned_name, created_by
             ) VALUES (
                :id, :module_id, :project_id, :record_id, :status,
                :overall_rating, :priority, :has_defect, :danger_immediate,
                :special_inspection_required, :urgent_action_required,
                :progress_percent, :assigned_to, :assigned_name, :created_by
             )'
        );
        $stmt->execute([
            ':id'                          => $data['id'],
            ':module_id'                   => $data['module_id'],
            ':project_id'                  => $data['project_id'],
            ':record_id'                   => $data['record_id'],
            ':status'                      => $data['status'] ?? 'nicht begonnen',
            ':overall_rating'              => $data['overall_rating'] ?? null,
            ':priority'                    => $data['priority'] ?? null,
            ':has_defect'                  => (int) ($data['has_defect'] ?? 0),
            ':danger_immediate'            => (int) ($data['danger_immediate'] ?? 0),
            ':special_inspection_required' => (int) ($data['special_inspection_required'] ?? 0),
            ':urgent_action_required'      => (int) ($data['urgent_action_required'] ?? 0),
            ':progress_percent'            => (int) ($data['progress_percent'] ?? 0),
            ':assigned_to'                 => $data['assigned_to'] ?? null,
            ':assigned_name'               => $data['assigned_name'] ?? null,
            ':created_by'                  => $data['created_by'] ?? null,
        ]);
    }

    /**
     * Aktualisiert Felder der Kern-Inspektion (optimistisches Locking via version).
     * @param array<string,mixed> $changes
     */
    public function save(string $id, array $changes, string $userId): void
    {
        $fields = [];
        $params = [':id' => $id, ':updated_by' => $userId];

        $allowed = [
            'status', 'overall_rating', 'priority', 'has_defect',
            'danger_immediate', 'special_inspection_required',
            'urgent_action_required', 'progress_percent',
            'assigned_to', 'assigned_name', 'completed_at',
        ];

        foreach ($allowed as $col) {
            if (array_key_exists($col, $changes)) {
                $fields[] = "{$col} = :{$col}";
                $params[":{$col}"] = $changes[$col];
            }
        }

        if (empty($fields)) {
            return;
        }

        $fields[] = 'version = version + 1';
        $sql = 'UPDATE inspections SET ' . implode(', ', $fields)
             . ' WHERE id = :id AND deleted_at IS NULL';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Soft-Delete einer Inspektion.
     */
    public function softDelete(string $id): void
    {
        $stmt = $this->db->prepare(
            'UPDATE inspections SET deleted_at = NOW() WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
    }

    // ── Datensatz-Sperren ──────────────────────────────────────────────────────

    /**
     * Versucht eine exklusive Sperre fuer eine Inspektion zu erwerben.
     * @return array{ok: bool, locked_by?: string, locked_at?: string}
     */
    public function acquireLock(string $inspectionId, string $userId, string $userName, int $ttlMinutes = 15): array
    {
        try {
            $this->db->beginTransaction();

            // Bestehende abgelaufene Sperren bereinigen
            $clean = $this->db->prepare(
                'DELETE FROM record_locks
                 WHERE inspection_id = :id
                   AND locked_until < NOW()'
            );
            $clean->execute([':id' => $inspectionId]);

            // Pruefen ob aktive Sperre vorhanden
            $check = $this->db->prepare(
                'SELECT user_name, locked_until FROM record_locks
                 WHERE inspection_id = :id
                   AND locked_until >= NOW()
                 LIMIT 1
                 FOR UPDATE'
            );
            $check->execute([':id' => $inspectionId]);
            $existing = $check->fetch(\PDO::FETCH_ASSOC);

            if ($existing !== false && $existing['user_name'] !== $userName) {
                $this->db->commit();
                return [
                    'ok'         => false,
                    'locked_by'  => $existing['user_name'],
                    'locked_at'  => $existing['locked_until'],
                ];
            }

            // Sperre setzen (REPLACE INTO oder DELETE+INSERT)
            $del = $this->db->prepare(
                'DELETE FROM record_locks WHERE inspection_id = :id'
            );
            $del->execute([':id' => $inspectionId]);

            $ins = $this->db->prepare(
                'INSERT INTO record_locks (inspection_id, user_id, user_name, locked_until)
                 VALUES (:id, :user_id, :user_name, DATE_ADD(NOW(), INTERVAL :ttl MINUTE))'
            );
            $ins->execute([
                ':id'        => $inspectionId,
                ':user_id'   => $userId,
                ':user_name' => $userName,
                ':ttl'       => $ttlMinutes,
            ]);

            $this->db->commit();
            return ['ok' => true];
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Gibt eine Sperre frei (nur eigene oder Admin).
     */
    public function releaseLock(string $inspectionId, string $userId, string $role): void
    {
        if ($role === 'administrator') {
            $stmt = $this->db->prepare(
                'DELETE FROM record_locks WHERE inspection_id = :id'
            );
            $stmt->execute([':id' => $inspectionId]);
        } else {
            $stmt = $this->db->prepare(
                'DELETE FROM record_locks WHERE inspection_id = :id AND user_id = :user_id'
            );
            $stmt->execute([':id' => $inspectionId, ':user_id' => $userId]);
        }
    }
}
