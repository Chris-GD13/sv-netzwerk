<?php
declare(strict_types=1);

namespace SvIntern\Controllers;

use SvIntern\Middleware\Role;
use SvIntern\Models\User;
use SvIntern\Models\AuditLog;
use SvIntern\Services\AuthService;

final class UserController
{
    /** GET /intern-api/users — nur Administrator */
    public static function list(array $session, \PDO $db): never
    {
        Role::require($session, Role::ADMINISTRATOR);
        $model = new User($db);
        \jsonResponse(['data' => $model->listAll()]);
    }

    /** POST /intern-api/users — nur Administrator */
    public static function create(array $session, \PDO $db): never
    {
        Role::require($session, Role::ADMINISTRATOR);

        $body     = \readJsonBody();
        $email    = strtolower(trim((string) ($body['email'] ?? '')));
        $fullName = trim((string) ($body['full_name'] ?? ''));
        $role     = trim((string) ($body['role'] ?? 'pruefer'));
        $password = (string) ($body['password'] ?? '');

        // Validierung
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            \jsonError('Ungueltige E-Mail-Adresse.');
        }
        if ($fullName === '') {
            \jsonError('Name darf nicht leer sein.');
        }
        if (!in_array($role, ['administrator', 'pruefer', 'auswertung'], true)) {
            \jsonError('Unbekannte Rolle.');
        }

        $pwErrors = AuthService::validatePasswordStrength($password);
        if ($pwErrors) {
            \jsonError(implode(' ', $pwErrors));
        }

        $model = new User($db);
        if ($model->emailExists($email)) {
            \jsonError('E-Mail-Adresse bereits vergeben.', 409);
        }

        $id   = \generateUuid();
        $hash = AuthService::hashPassword($password);
        $model->create($id, $email, $fullName, $role, $hash, $session['user_id']);

        $auditLog = new AuditLog($db);
        $auditLog->log(
            actorId:    $session['user_id'],
            actorName:  $session['user_name'],
            actionType: 'create_user',
            entityType: 'user',
            entityId:   $id,
            newValue:   $email . ' (' . $role . ')',
            ip:         \clientIp(),
        );

        \jsonResponse(['data' => ['id' => $id]], 201);
    }

    /** PUT /intern-api/users/{id} — nur Administrator */
    public static function update(array $session, \PDO $db, string $id): never
    {
        Role::require($session, Role::ADMINISTRATOR);

        $model = new User($db);
        if ($model->findById($id) === null) {
            \jsonError('Benutzer nicht gefunden.', 404);
        }

        $body     = \readJsonBody();
        $fullName = trim((string) ($body['full_name'] ?? ''));
        $role     = trim((string) ($body['role'] ?? ''));
        $password = \strOrNull($body['password'] ?? null);

        if ($fullName === '') {
            \jsonError('Name darf nicht leer sein.');
        }
        if (!in_array($role, ['administrator', 'pruefer', 'auswertung'], true)) {
            \jsonError('Unbekannte Rolle.');
        }

        $hash = null;
        if ($password !== null) {
            $pwErrors = AuthService::validatePasswordStrength($password);
            if ($pwErrors) {
                \jsonError(implode(' ', $pwErrors));
            }
            $hash = AuthService::hashPassword($password);
        }

        $model->update($id, $fullName, $role, $hash);

        $auditLog = new AuditLog($db);
        $auditLog->log(
            actorId:    $session['user_id'],
            actorName:  $session['user_name'],
            actionType: 'update_user',
            entityType: 'user',
            entityId:   $id,
            newValue:   $fullName . ' (' . $role . ')',
            ip:         \clientIp(),
        );

        \jsonResponse(['ok' => true]);
    }

    /** DELETE /intern-api/users/{id} — nur Administrator, kein Selbst-Loeschen */
    public static function delete(array $session, \PDO $db, string $id): never
    {
        Role::require($session, Role::ADMINISTRATOR);

        if ($id === $session['user_id']) {
            \jsonError('Sie koennen sich nicht selbst deaktivieren.', 400);
        }

        $model = new User($db);
        if ($model->findById($id) === null) {
            \jsonError('Benutzer nicht gefunden.', 404);
        }

        $model->deactivate($id);

        $auditLog = new AuditLog($db);
        $auditLog->log(
            actorId:    $session['user_id'],
            actorName:  $session['user_name'],
            actionType: 'deactivate_user',
            entityType: 'user',
            entityId:   $id,
            ip:         \clientIp(),
        );

        \jsonResponse(['ok' => true]);
    }
}
