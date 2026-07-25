<?php
declare(strict_types=1);

namespace SvIntern\Models;

/**
 * Audit-Log-Modell – schreibgeschuetzt nach Erstellung (keine Updates, kein Delete).
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
        ?string $fieldName = null,
        ?string $oldValue  = null,
        ?string $newValue  = null,
        ?string $reason    = null,
        ?string $windowId  = null,
        ?string $ip        = null,
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO audit_logs (
                id, actor_id, actor_name, action_type, entity_type, entity_id,
                field_name, old_value, new_value, reason, window_id, ip_address
             ) VALUES (
                :id, :actor_id, :actor_name, :action_type, :entity_type, :entity_id,
                :field_name, :old_value, :new_value, :reason, :window_id, :ip_address
             )'
        );
        $stmt->execute([
            ':id'          => \generateUuid(),
            ':actor_id'    => $actorId,
            ':actor_name'  => $actorName,
            ':action_type' => $actionType,
            ':entity_type' => $entityType,
            ':entity_id'   => $entityId,
            ':field_name'  => $fieldName,
            ':old_value'   => $oldValue,
            ':new_value'   => $newValue,
            ':reason'      => $reason,
            ':window_id'   => $windowId,
            ':ip_address'  => $ip,
        ]);
    }

    /**
     * Gibt die letzten Audit-Eintraege fuer ein Fenster zurueck.
     * @return list<array<string,mixed>>
     */
    public function listByWindow(string $windowId, int $limit = 20): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, actor_name, action_type, field_name, old_value, new_value, reason, created_at
             FROM audit_logs
             WHERE window_id = :window_id
             ORDER BY created_at DESC
             LIMIT :limit'
        );
        $stmt->bindValue(':window_id', $windowId);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
