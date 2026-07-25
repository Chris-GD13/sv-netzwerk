<?php
declare(strict_types=1);

namespace SvIntern\Models;

/**
 * Benutzer-Modell fuer MySQL 8.0.
 * Alle DB-Zugriffe verwenden ausschliesslich Prepared Statements.
 */
final class User
{
    public function __construct(private readonly \PDO $db) {}

    /**
     * Gibt einen aktiven Benutzer anhand der E-Mail zurueck.
     * @return array<string,mixed>|null
     */
    public function findByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, email, full_name, password_hash, role, is_active
             FROM users
             WHERE email = :email AND is_active = 1
             LIMIT 1'
        );
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Gibt einen Benutzer anhand der ID zurueck.
     * @return array<string,mixed>|null
     */
    public function findById(string $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, email, full_name, role, is_active, created_at, updated_at
             FROM users
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row !== false ? $row : null;
    }

    /**
     * Gibt alle aktiven Benutzer zurueck (kein password_hash).
     * @return list<array<string,mixed>>
     */
    public function listAll(): array
    {
        $stmt = $this->db->query(
            'SELECT id, email, full_name, role, is_active, created_at, updated_at
             FROM users
             ORDER BY full_name ASC'
        );
        return $stmt->fetchAll();
    }

    /**
     * Legt einen neuen Benutzer an.
     */
    public function create(
        string $id,
        string $email,
        string $fullName,
        string $role,
        string $passwordHash,
        string $createdBy,
    ): void {
        $stmt = $this->db->prepare(
            'INSERT INTO users (id, email, full_name, role, password_hash, is_active, created_by)
             VALUES (:id, :email, :full_name, :role, :password_hash, 1, :created_by)'
        );
        $stmt->execute([
            ':id'            => $id,
            ':email'         => $email,
            ':full_name'     => $fullName,
            ':role'          => $role,
            ':password_hash' => $passwordHash,
            ':created_by'    => $createdBy,
        ]);
    }

    /**
     * Aktualisiert Benutzerdaten (Passwort optional).
     */
    public function update(
        string $id,
        string $fullName,
        string $role,
        ?string $passwordHash = null,
    ): void {
        if ($passwordHash !== null) {
            $stmt = $this->db->prepare(
                'UPDATE users SET full_name = :full_name, role = :role, password_hash = :password_hash
                 WHERE id = :id'
            );
            $stmt->execute([
                ':full_name'     => $fullName,
                ':role'          => $role,
                ':password_hash' => $passwordHash,
                ':id'            => $id,
            ]);
        } else {
            $stmt = $this->db->prepare(
                'UPDATE users SET full_name = :full_name, role = :role WHERE id = :id'
            );
            $stmt->execute([':full_name' => $fullName, ':role' => $role, ':id' => $id]);
        }
    }

    /**
     * Deaktiviert einen Benutzer (Soft Delete).
     */
    public function deactivate(string $id): void
    {
        $stmt = $this->db->prepare('UPDATE users SET is_active = 0 WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    /**
     * Prueft ob eine E-Mail-Adresse bereits vergeben ist (ausser fuer einen bestimmten User).
     */
    public function emailExists(string $email, ?string $excludeId = null): bool
    {
        if ($excludeId !== null) {
            $stmt = $this->db->prepare(
                'SELECT 1 FROM users WHERE email = :email AND id != :id LIMIT 1'
            );
            $stmt->execute([':email' => $email, ':id' => $excludeId]);
        } else {
            $stmt = $this->db->prepare('SELECT 1 FROM users WHERE email = :email LIMIT 1');
            $stmt->execute([':email' => $email]);
        }
        return $stmt->fetchColumn() !== false;
    }

    /**
     * Protokolliert einen fehlgeschlagenen Login-Versuch.
     */
    public function recordLoginAttempt(string $email, string $ip, bool $success): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO login_attempts (email, ip_address, success)
             VALUES (:email, :ip, :success)'
        );
        $stmt->execute([':email' => $email, ':ip' => $ip, ':success' => $success ? 1 : 0]);
    }

    /**
     * Gibt die Anzahl fehlgeschlagener Login-Versuche in den letzten $minutes Minuten zurueck.
     */
    public function countRecentFailures(string $ip, int $minutes = 15): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE ip_address = :ip
               AND success = 0
               AND created_at >= DATE_SUB(NOW(), INTERVAL :minutes MINUTE)'
        );
        $stmt->execute([':ip' => $ip, ':minutes' => $minutes]);
        return (int) $stmt->fetchColumn();
    }
}
