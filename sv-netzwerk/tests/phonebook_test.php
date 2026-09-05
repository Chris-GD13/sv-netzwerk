<?php
declare(strict_types=1);
require_once __DIR__ . '/../public/intern/api/phonebook-core.php';

function pbAssert(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

$contact = phonebookContact(['name' => "  Christian\nHandy ", 'phone' => '+49 (160) 4092134', 'note' => ' intern ']);
pbAssert($contact !== null, 'Kontakt wird normalisiert.');
pbAssert($contact['name'] === 'Christian Handy', 'Name wird bereinigt.');
pbAssert($contact['phone_key'] === '491604092134', 'Rufnummernschlüssel ist falsch.');
pbAssert(phonebookContact(['name' => 'Ohne Nummer', 'phone' => 'x']) === null, 'Ungültige Nummer wurde angenommen.');
$batch = phonebookContacts([$contact, $contact, ['name' => 'Zentrale', 'phone' => '12345']]);
pbAssert(count($batch) === 2, 'Dubletten im Import wurden nicht entfernt.');
echo "phonebook_test: ok\n";

