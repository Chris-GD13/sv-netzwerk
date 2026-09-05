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
    $columns = db()->query('SHOW COLUMNS FROM phonebook_contacts')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('phone_type', $columns, true)) {
        db()->exec("ALTER TABLE phonebook_contacts ADD COLUMN phone_type VARCHAR(20) NOT NULL DEFAULT 'other' AFTER phone_key");
    }
    if (!in_array('email', $columns, true)) {
        db()->exec("ALTER TABLE phonebook_contacts ADD COLUMN email VARCHAR(190) NULL AFTER phone_type");
    }
}

function phonebookUserLabel(array $user): string
{
    return phonebookText($user['email'] ?? $user['full_name'] ?? '', 190);
}

function phonebookList(string $query): array
{
    $query = phonebookText($query, 120);
    if ($query === '') {
        $stmt = db()->query('SELECT id, name, phone, phone_type, email, note, updated_at FROM phonebook_contacts ORDER BY name, phone');
    } else {
        $phoneKey = phonebookPhoneKey($query);
        $stmt = db()->prepare("SELECT id, name, phone, phone_type, email, note, updated_at FROM phonebook_contacts
            WHERE name LIKE :query_name OR phone LIKE :query_phone OR email LIKE :query_email OR note LIKE :query_note" . ($phoneKey !== '' ? ' OR phone_key LIKE :phone_key' : '') . "
            ORDER BY name, phone LIMIT 3000");
        $like = '%' . $query . '%';
        $params = [':query_name' => $like, ':query_phone' => $like, ':query_email' => $like, ':query_note' => $like];
        if ($phoneKey !== '') {
            $params[':phone_key'] = '%' . $phoneKey . '%';
        }
        $stmt->execute($params);
    }
    $groups = [];
    foreach ($stmt->fetchAll() as $row) {
        $key = phonebookNameKey($row['name'] ?? '');
        if (!isset($groups[$key])) {
            $groups[$key] = ['id'=>(int)$row['id'], 'ids'=>[], 'name'=>(string)$row['name'], 'email'=>(string)($row['email'] ?? ''), 'note'=>(string)($row['note'] ?? ''), 'phones'=>[], 'updated_at'=>(string)$row['updated_at']];
        }
        $groups[$key]['ids'][] = (int)$row['id'];
        if ($groups[$key]['email'] === '' && (string)($row['email'] ?? '') !== '') $groups[$key]['email'] = (string)$row['email'];
        if ($groups[$key]['note'] === '' && (string)($row['note'] ?? '') !== '') $groups[$key]['note'] = (string)$row['note'];
        if (trim((string)$row['phone']) !== '') $groups[$key]['phones'][] = ['id'=>(int)$row['id'], 'type'=>phonebookPhoneType($row['phone_type'] ?? 'other'), 'number'=>(string)$row['phone']];
    }
    return array_slice(array_values($groups), 0, 500);
}

$action = (string)($_GET['action'] ?? 'list');

try {
    phonebookEnsureSchema();
    if ($action === 'list') {
        apiJson(['ok' => true, 'contacts' => phonebookList((string)($_GET['q'] ?? ''))]);
    }

    if ($action === 'same_name_review') {
        $contacts = array_values(array_filter(phonebookList(''), static fn(array $contact): bool => count($contact['phones'] ?? []) > 1));
        $numberCount = array_sum(array_map(static fn(array $contact): int => count($contact['phones'] ?? []), $contacts));
        apiJson(['ok' => true, 'contacts' => $contacts, 'group_count' => count($contacts), 'total' => $numberCount]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        apiError(405, 'POST erforderlich.');
    }
    $body = requestBody();
    $actor = phonebookUserLabel($user);

    if ($action === 'save_group') {
        $name = phonebookText($body['name'] ?? '', 150);
        $email = phonebookEmail($body['email'] ?? '');
        $note = phonebookNote($body['note'] ?? '');
        $phones = phonebookTypedPhones($body['phones'] ?? []);
        if ($name === '' || ($phones === [] && $email === '')) apiError(400, 'Bitte Name sowie mindestens eine Rufnummer oder E-Mail-Adresse angeben.');
        $ids = array_values(array_unique(array_filter(array_map('intval', is_array($body['ids'] ?? null) ? $body['ids'] : []), static fn(int $id): bool => $id > 0)));
        $pdo = db(); $pdo->beginTransaction();
        try {
            if ($ids !== []) {
                $delete = $pdo->prepare('DELETE FROM phonebook_contacts WHERE id=:id');
                foreach ($ids as $id) { $delete->execute([':id'=>$id]); }
            }
            if ($phones === []) $phones[] = ['type'=>'other', 'number'=>'', 'phone_key'=>''];
            $insert = $pdo->prepare('INSERT INTO phonebook_contacts(name,phone,phone_key,phone_type,email,note,created_by,updated_by,created_at,updated_at) VALUES(:name,:phone,:phone_key,:phone_type,:email,:note,:actor,:actor,NOW(),NOW())');
            $newIds = [];
            foreach ($phones as $phone) {
                $insert->execute([':name'=>$name, ':phone'=>$phone['number'], ':phone_key'=>$phone['phone_key'], ':phone_type'=>$phone['type'], ':email'=>$email, ':note'=>$note, ':actor'=>$actor]);
                $newIds[] = (int)$pdo->lastInsertId();
            }
            $pdo->commit();
        } catch (Throwable $error) { $pdo->rollBack(); throw $error; }
        apiJson(['ok'=>true, 'ids'=>$newIds]);
    }

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

    if ($action === 'delete_many') {
        if (!in_array((string)($user['role'] ?? ''), ['administrator', 'projektleiter'], true)) {
            apiError(403, 'Die Sammellöschung ist nur für Administration und Projektleitung verfügbar.');
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', is_array($body['ids'] ?? null) ? $body['ids'] : []), static fn(int $id): bool => $id > 0)));
        if ($ids === [] || count($ids) > 500) {
            apiError(400, 'Bitte zwischen 1 und 500 Kontakte auswählen.');
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $delete = $pdo->prepare('DELETE FROM phonebook_contacts WHERE id=:id');
            $deleted = 0;
            foreach ($ids as $id) {
                $delete->execute([':id' => $id]);
                $deleted += $delete->rowCount();
            }
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
        apiJson(['ok' => true, 'deleted' => $deleted]);
    }

    if ($action === 'cleanup_unwanted') {
        if (!in_array((string)($user['role'] ?? ''), ['administrator', 'projektleiter'], true)) {
            apiError(403, 'Die Sammelbereinigung ist nur für Administration und Projektleitung verfügbar.');
        }
        $rows = db()->query('SELECT id, name FROM phonebook_contacts')->fetchAll();
        $ids = [];
        foreach ($rows as $row) {
            if (preg_match('/(?:andersson|\bab\b|per\s*mail)/iu', (string)($row['name'] ?? ''))) {
                $ids[] = (int)$row['id'];
            }
        }
        if ($ids === []) {
            apiJson(['ok' => true, 'deleted' => 0]);
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $delete = $pdo->prepare('DELETE FROM phonebook_contacts WHERE id=:id');
            $deleted = 0;
            foreach ($ids as $id) {
                $delete->execute([':id' => $id]);
                $deleted += $delete->rowCount();
            }
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
        apiJson(['ok' => true, 'deleted' => $deleted]);
    }

    if ($action === 'cleanup_duplicates') {
        if (!in_array((string)($user['role'] ?? ''), ['administrator', 'projektleiter'], true)) {
            apiError(403, 'Die Dublettenbereinigung ist nur für Administration und Projektleitung verfügbar.');
        }
        $rows = db()->query('SELECT id, name, phone FROM phonebook_contacts ORDER BY id')->fetchAll();
        $groups = [];
        foreach ($rows as $row) {
            $key = phonebookNameKey($row['name'] ?? '') . '|' . phonebookPhoneKey($row['phone'] ?? '');
            if (!str_ends_with($key, '|')) {
                $groups[$key][] = $row;
            }
        }
        $deleteIds = [];
        $keepers = [];
        foreach ($groups as $group) {
            usort($group, static function (array $left, array $right): int {
                $score = static fn(string $phone): int => preg_match('/^\s*(?:\+49|0049)/', $phone) ? 3 : (preg_match('/^\s*0/', $phone) ? 2 : 1);
                return $score((string)$right['phone']) <=> $score((string)$left['phone']);
            });
            $keepers[] = $group[0];
            foreach (array_slice($group, 1) as $duplicate) {
                $deleteIds[] = (int)$duplicate['id'];
            }
        }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $delete = $pdo->prepare('DELETE FROM phonebook_contacts WHERE id=:id');
            foreach ($deleteIds as $id) {
                $delete->execute([':id' => $id]);
            }
            $update = $pdo->prepare('UPDATE phonebook_contacts SET phone_key=:phone_key, updated_by=:actor, updated_at=NOW() WHERE id=:id');
            foreach ($keepers as $keeper) {
                $update->execute([':phone_key' => phonebookPhoneKey($keeper['phone']), ':actor' => $actor, ':id' => (int)$keeper['id']]);
            }
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
        apiJson(['ok' => true, 'deleted' => count($deleteIds)]);
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
            $find = $pdo->prepare('SELECT id, name, phone_type, email, note FROM phonebook_contacts WHERE phone_key=:phone_key AND name=:name ORDER BY id LIMIT 1');
            $insert = $pdo->prepare('INSERT INTO phonebook_contacts(name, phone, phone_key, phone_type, email, note, created_by, updated_by, created_at, updated_at) VALUES(:name,:phone,:phone_key,:phone_type,:email,:note,:actor,:actor,NOW(),NOW())');
            $update = $pdo->prepare('UPDATE phonebook_contacts SET name=:name, phone=:phone, phone_type=:phone_type, email=:email, note=:note, updated_by=:actor, updated_at=NOW() WHERE id=:id');
            $inserted = 0;
            $updated = 0;
            foreach ($contacts as $contact) {
                $find->execute([':phone_key' => $contact['phone_key'], ':name' => $contact['name']]);
                $existing = $find->fetch();
                if ($existing) {
                    $note = $contact['note'] !== '' ? $contact['note'] : (string)($existing['note'] ?? '');
                    $type = $contact['phone_type'] !== 'other' ? $contact['phone_type'] : (string)($existing['phone_type'] ?? 'other');
                    $email = $contact['email'] !== '' ? $contact['email'] : (string)($existing['email'] ?? '');
                    $update->execute([':name' => (string)$existing['name'], ':phone' => $contact['phone'], ':phone_type'=>$type, ':email'=>$email, ':note' => $note, ':actor' => $actor, ':id' => (int)$existing['id']]);
                    $updated++;
                    continue;
                }
                $insert->execute([
                    ':name' => $contact['name'],
                    ':phone' => $contact['phone'],
                    ':phone_key' => $contact['phone_key'],
                    ':phone_type' => $contact['phone_type'],
                    ':email' => $contact['email'],
                    ':note' => $contact['note'],
                    ':actor' => $actor,
                ]);
                $inserted++;
            }
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
        apiJson(['ok' => true, 'imported' => count($contacts), 'inserted' => $inserted, 'updated' => $updated, 'skipped' => max(0, count($raw) - count($contacts))]);
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
