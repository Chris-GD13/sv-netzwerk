<?php
declare(strict_types=1);

namespace SvIntern\Models;

/**
 * Foto-Modell fuer MySQL 8.0.
 * Fotos sind einem Fenster-Datensatz (window_id) zugeordnet.
 */
final class Photo
{
    public function __construct(private readonly \PDO $db) {}

    /**
     * @return list<array<string,mixed>>
     */
    public function listByWindow(string $windowId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, window_id, category, caption, file_name, storage_path,
                    taken_at, inspector_id, inspector_name, created_at
             FROM photos
             WHERE window_id = :window_id AND deleted_at IS NULL
             ORDER BY created_at DESC'
        );
        $stmt->execute([':window_id' => $windowId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findById(string $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, window_id, category, caption, file_name, storage_path,
                    taken_at, inspector_id, inspector_name, created_at
             FROM photos
             WHERE id = :id AND deleted_at IS NULL LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Legt einen neuen Foto-Datensatz an.
     */
    public function create(
        string $id,
        string $windowId,
        string $category,
        ?string $caption,
        string $fileName,
        string $storagePath,
        string $inspectorId,
        string $inspectorName,
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO photos (id, window_id, category, caption, file_name, storage_path,
                                 inspector_id, inspector_name, taken_at)
             VALUES (:id, :window_id, :category, :caption, :file_name, :storage_path,
                     :inspector_id, :inspector_name, NOW())'
        );
        $stmt->execute([
            ':id'             => $id,
            ':window_id'      => $windowId,
            ':category'       => $category,
            ':caption'        => $caption,
            ':file_name'      => $fileName,
            ':storage_path'   => $storagePath,
            ':inspector_id'   => $inspectorId,
            ':inspector_name' => $inspectorName,
        ]);
    }

    /**
     * Soft-Delete eines Fotos.
     */
    public function softDelete(string $id, string $userId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE photos SET deleted_at = NOW(), deleted_by = :user_id WHERE id = :id'
        );
        $stmt->execute([':id' => $id, ':user_id' => $userId]);
    }
}
