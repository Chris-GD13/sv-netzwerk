<?php
declare(strict_types=1);

namespace SvIntern\Modules\Windows;

/**
 * Modul-spezifisches Modell: Fensterpruefungs-Datensaetze.
 *
 * Verwaltet die `window_records`-Tabelle.
 * Generische Inspektionsfelder (Status, Flags, Zuweisung) liegen
 * in `inspections` (→ SvIntern\Models\Inspection).
 */
final class WindowRecord
{
    public function __construct(private readonly \PDO $db) {}

    // ── Lesen ──────────────────────────────────────────────────────────────────

    /**
     * Zusammenfassung aller Fenster-Inspektionen fuer Listen-/Tabellenansichten.
     * Joined mit inspections und (falls vorhanden) record_locks.
     * @return list<array<string,mixed>>
     */
    public function listSummaries(string $moduleId, string $projectId): array
    {
        $stmt = $this->db->prepare(
            'SELECT
                i.id, i.record_id, i.status, i.overall_rating, i.priority,
                i.has_defect, i.danger_immediate, i.special_inspection_required,
                i.urgent_action_required, i.progress_percent,
                i.assigned_to, i.assigned_name, i.completed_at,
                i.version, i.created_at, i.updated_at,
                w.inspection_number, w.window_number, w.accessibility_status,
                w.building_label, w.section_label, w.floor_label,
                w.room_number, w.room_label,
                rl.user_name AS locked_by, rl.locked_until
             FROM inspections i
             JOIN window_records w ON w.inspection_id = i.id
             LEFT JOIN record_locks rl
                    ON rl.inspection_id = i.id AND rl.locked_until >= NOW()
             WHERE i.module_id = :module_id
               AND i.project_id = :project_id
               AND i.deleted_at IS NULL
             ORDER BY
                CAST(REGEXP_REPLACE(w.window_number, \'[^0-9]\', \'\') AS UNSIGNED),
                w.window_number'
        );
        $stmt->execute([':module_id' => $moduleId, ':project_id' => $projectId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Vollstaendiger Datensatz inklusive generischer Inspektionsfelder.
     * @return array<string,mixed>|null
     */
    public function findByInspectionId(string $inspectionId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT
                i.id, i.module_id, i.project_id, i.record_id, i.status,
                i.overall_rating, i.priority, i.has_defect, i.danger_immediate,
                i.special_inspection_required, i.urgent_action_required,
                i.progress_percent, i.assigned_to, i.assigned_name,
                i.completed_at, i.version, i.created_by, i.created_at, i.updated_at,
                w.id AS window_record_id, w.inspection_number, w.window_number,
                w.accessibility_status, w.building_label, w.section_label,
                w.floor_label, w.room_number, w.room_label,
                w.form_data, w.calculated_data
             FROM inspections i
             JOIN window_records w ON w.inspection_id = i.id
             WHERE i.id = :id AND i.deleted_at IS NULL
             LIMIT 1'
        );
        $stmt->execute([':id' => $inspectionId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        // JSON-Felder dekodieren
        $row['form_data']       = json_decode((string) ($row['form_data'] ?? 'null'), true) ?? [];
        $row['calculated_data'] = json_decode((string) ($row['calculated_data'] ?? 'null'), true) ?? [];

        return $row;
    }

    // ── Schreiben ──────────────────────────────────────────────────────────────

    /**
     * Erstellt den modulspezifischen Datensatz (nach Inspection::create).
     * @param array<string,mixed> $data
     */
    public function create(string $inspectionId, array $data): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO window_records (
                id, inspection_id, inspection_number, window_number,
                accessibility_status, building_label, section_label,
                floor_label, room_number, room_label,
                form_data, calculated_data
             ) VALUES (
                :id, :inspection_id, :inspection_number, :window_number,
                :accessibility_status, :building_label, :section_label,
                :floor_label, :room_number, :room_label,
                :form_data, :calculated_data
             )'
        );
        $stmt->execute([
            ':id'                   => \generateUuid(),
            ':inspection_id'        => $inspectionId,
            ':inspection_number'    => $data['inspection_number'] ?? null,
            ':window_number'        => $data['window_number'] ?? null,
            ':accessibility_status' => $data['accessibility_status'] ?? 'zugaenglich',
            ':building_label'       => $data['building_label'] ?? null,
            ':section_label'        => $data['section_label'] ?? null,
            ':floor_label'          => $data['floor_label'] ?? null,
            ':room_number'          => $data['room_number'] ?? null,
            ':room_label'           => $data['room_label'] ?? null,
            ':form_data'            => json_encode($data['form_data'] ?? [], JSON_UNESCAPED_UNICODE),
            ':calculated_data'      => json_encode($data['calculated_data'] ?? [], JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * Aktualisiert fenster-spezifische Felder und form_data.
     * @param array<string,mixed> $changes
     */
    public function save(string $inspectionId, array $changes): void
    {
        $allowed = [
            'inspection_number', 'window_number', 'accessibility_status',
            'building_label', 'section_label', 'floor_label', 'room_number', 'room_label',
        ];
        $fields = [];
        $params = [':inspection_id' => $inspectionId];

        foreach ($allowed as $col) {
            if (array_key_exists($col, $changes)) {
                $fields[] = "{$col} = :{$col}";
                $params[":{$col}"] = $changes[$col];
            }
        }

        if (array_key_exists('form_data', $changes)) {
            $fields[] = 'form_data = :form_data';
            $params[':form_data'] = json_encode($changes['form_data'], JSON_UNESCAPED_UNICODE);
        }

        if (array_key_exists('calculated_data', $changes)) {
            $fields[] = 'calculated_data = :calculated_data';
            $params[':calculated_data'] = json_encode($changes['calculated_data'], JSON_UNESCAPED_UNICODE);
        }

        if (empty($fields)) {
            return;
        }

        $sql = 'UPDATE window_records SET ' . implode(', ', $fields)
             . ' WHERE inspection_id = :inspection_id';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    // ── Berechnungsparameter ────────────────────────────────────────────────────

    /**
     * Gibt alle aktiven Berechnungsparameter fuer dieses Modul zurueck.
     * @return list<array<string,mixed>>
     */
    public function getCalculationParameters(): array
    {
        $stmt = $this->db->prepare(
            'SELECT param_key, param_value, unit, description
             FROM calculation_parameters
             WHERE module_id = :module_id AND is_active = 1
             ORDER BY param_key'
        );
        $stmt->execute([':module_id' => WindowModule::MODULE_ID]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
