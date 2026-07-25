<?php
declare(strict_types=1);

namespace SvIntern\Models;

/**
 * Audit-Log-Modell – schreibgeschuetzt nach Erstellung (keine Updates, kein Delete).
 *
 * Der inspection_id-Parameter ist optional, weil manche Aktionen
 * (Login, Logout, Benutzerverwaltung) nicht an eine Inspektion gebunden sind.
 */
final class AuditLog
{
    public function __construct(private readonly \PDO $db) {}

    /**
     * Schreibt einen neuen Audit-Eintrag.
     * Passwörter, Tokens oder Secrets duerfen NIE uebergeben werden.
     */
    public function log(
        string $actorId,
        string $actorName,
        string $actionType,
        string $entityType,
        ?string $entityId,
        ?string $fieldName    = null,
        ?string $oldValue     = null,
        ?string $newValue     = null,
        ?string $reason       = null,
        ?string $inspectionId = null,
        ?string $ip           = null,
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO audit_logs (
                id, actor_id, actor_name, action_type, entity_type, entity_id,
                field_name, old_value, new_value, reason, inspection_id, ip_address
             ) VALUES (
                :id, :actor_id, :actor_name, :action_type, :entity_type, :entity_id,
                :field_name, :old_value, :new_value, :reason, :inspection_id, :ip_address
             )'
        );
        $stmt->execute([
            ':id'            => \generateUuid(),
            ':actor_id'      => $actorId,
            ':actor_name'    => $actorName,
            ':action_type'   => $actionType,
            ':entity_type'   => $entityType,
            ':entity_id'     => $entityId,
            ':field_name'    => $fieldName,
            ':old_value'     => $oldValue,
            ':new_value'     => $newValue,
            ':reason'        => $reason,
            ':inspection_id' => $inspectionId,
            ':ip_address'    => $ip,
        ]);
    }

    /**
     * Gibt die letzten Audit-Eintraege fuer eine Inspektion zurueck.
     * @return list<array<string,mixed>>
     */
    public function listByInspection(string $inspectionId, int $limit = 20): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, actor_name, action_type, field_name, old_value, new_value, reason, created_at
             FROM audit_logs
             WHERE inspection_id = :inspection_id
             ORDER BY created_at DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':inspection_id', $inspectionId);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
