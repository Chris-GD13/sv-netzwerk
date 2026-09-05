<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/phonebook-core.php';

commonHeaders();
$user = requireAuth();
if (!in_array((string)($user['role'] ?? ''), ['administrator', 'projektleiter', 'pruefer', 'sachverstaendiger'], true)) {
    apiError(403, 'Kein Zugriff auf das Telefonbuch.');
}

function phonebookEnsureSchema(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS phonebook_contacts (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        phone VARCHAR(80) NOT NULL,
        phone_key VARCHAR(40) NOT NULL,
        note VARCHAR(255) NULL,
        created_by VARCHAR(190) NULL,
        updated_by VARCHAR(190) NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY uniq_phonebook_contact (phone_key, name),
        INDEX idx_phonebook_name (name),
        INDEX idx_phonebook_phone (phone_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function phonebookUserLabel(array $user): string
{
    return phonebookText($user['email'] ?? $user['full_name'] ?? '', 190);
}

function phonebookList(string $query): array
{
    $query = phonebookText($query, 120);
    if ($query === '') {
        $stmt = db()->query('SELECT id, name, phone, note, updated_at FROM phonebook_contacts ORDER BY name, phone LIMIT 500');
    } else {
        $phoneKey = phonebookPhoneKey($query);
        $stmt = db()->prepare("SELECT id, name, phone, note, updated_at FROM phonebook_contacts
            WHERE name LIKE :query OR phone LIKE :query OR note LIKE :query" . ($phoneKey !== '' ? ' OR phone_key LIKE :phone_key' : '') . "
            ORDER BY name, phone LIMIT 500");
        $params = [':query' => '%' . $query . '%'];
        if ($phoneKey !== '') {
            $params[':phone_key'] = '%' . $phoneKey . '%';
        }
        $stmt->execute($params);
    }
    return array_map(static fn(array $row): array => [
        'id' => (int)$row['id'],
        'name' => (string)$row['name'],
        'phone' => (string)$row['phone'],
        'note' => (string)($row['note'] ?? ''),
        'updated_at' => (string)$row['updated_at'],
    ], $stmt->fetchAll());
}

$action = (string)($_GET['action'] ?? 'list');

try {
    phonebookEnsureSchema();
    if ($action === 'list') {
        apiJson(['ok' => true, 'contacts' => phonebookList((string)($_GET['q'] ?? ''))]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        apiError(405, 'POST erforderlich.');
    }
    $body = requestBody();
    $actor = phonebookUserLabel($user);

    if ($action === 'save') {
        $contact = phonebookContact($body);
        if ($contact === null) {
            apiError(400, 'Bitte Name und eine gültige Telefonnummer angeben.');
        }
        $id = max(0, (int)($body['id'] ?? 0));
        $params = [
            ':name' => $contact['name'],
            ':phone' => $contact['phone'],
            ':phone_key' => $contact['phone_key'],
            ':note' => $contact['note'],
            ':actor' => $actor,
        ];
        if ($id > 0) {
            $stmt = db()->prepare('UPDATE phonebook_contacts SET name=:name, phone=:phone, phone_key=:phone_key, note=:note, updated_by=:actor, updated_at=NOW() WHERE id=:id');
            $stmt->execute($params + [':id' => $id]);
            if ($stmt->rowCount() === 0) {
                $check = db()->prepare('SELECT id FROM phonebook_contacts WHERE id=:id');
                $check->execute([':id' => $id]);
                if (!$check->fetchColumn()) {
                    apiError(404, 'Kontakt nicht gefunden.');
                }
            }
        } else {
            $stmt = db()->prepare('INSERT INTO phonebook_contacts(name, phone, phone_key, note, created_by, updated_by, created_at, updated_at) VALUES(:name,:phone,:phone_key,:note,:actor,:actor,NOW(),NOW())');
            $stmt->execute($params);
            $id = (int)db()->lastInsertId();
        }
        apiJson(['ok' => true, 'id' => $id, 'contact' => $contact]);
    }

    if ($action === 'delete') {
        $id = max(0, (int)($body['id'] ?? 0));
        if ($id <= 0) {
            apiError(400, 'Kontakt-ID fehlt.');
        }
        $stmt = db()->prepare('DELETE FROM phonebook_contacts WHERE id=:id');
        $stmt->execute([':id' => $id]);
        apiJson(['ok' => true, 'deleted' => $stmt->rowCount()]);
    }

    if ($action === 'import') {
        $raw = is_array($body['contacts'] ?? null) ? $body['contacts'] : [];
        $contacts = phonebookContacts($raw);
        if ($contacts === []) {
            apiError(400, 'Die Datei enthält keine Kontakte mit Name und Telefonnummer.');
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('INSERT INTO phonebook_contacts(name, phone, phone_key, note, created_by, updated_by, created_at, updated_at) VALUES(:name,:phone,:phone_key,:note,:actor,:actor,NOW(),NOW()) ON DUPLICATE KEY UPDATE phone=VALUES(phone), note=IF(VALUES(note)<>\'\',VALUES(note),note), updated_by=VALUES(updated_by), updated_at=NOW()');
            foreach ($contacts as $contact) {
                $stmt->execute([
                    ':name' => $contact['name'],
                    ':phone' => $contact['phone'],
                    ':phone_key' => $contact['phone_key'],
                    ':note' => $contact['note'],
                    ':actor' => $actor,
                ]);
            }
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
        apiJson(['ok' => true, 'imported' => count($contacts), 'skipped' => max(0, count($raw) - count($contacts))]);
    }

    apiError(404, 'Unbekannte Telefonbuch-Aktion.');
} catch (PDOException $error) {
    if ((string)$error->getCode() === '23000') {
        apiError(409, 'Dieser Kontakt ist mit derselben Telefonnummer bereits vorhanden.');
    }
    apiError(500, 'Telefonbuch konnte nicht gespeichert werden.');
} catch (Throwable $error) {
    apiError(500, $error->getMessage());
}
