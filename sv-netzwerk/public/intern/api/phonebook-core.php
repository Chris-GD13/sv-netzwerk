<?php
declare(strict_types=1);

function phonebookText(mixed $value, int $limit): string
{
    $text = trim((string)$value);
    $text = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text) ?? $text;
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return mb_substr($text, 0, $limit, 'UTF-8');
}

function phonebookPhoneKey(mixed $value): string
{
    return preg_replace('/\D+/', '', (string)$value) ?? '';
}

function phonebookContact(mixed $value): ?array
{
    if (!is_array($value)) {
        return null;
    }
    $name = phonebookText($value['name'] ?? '', 150);
    $phone = phonebookText($value['phone'] ?? $value['telefon'] ?? '', 80);
    $phoneKey = phonebookPhoneKey($phone);
    if ($name === '' || strlen($phoneKey) < 3) {
        return null;
    }
    return [
        'name' => $name,
        'phone' => $phone,
        'phone_key' => mb_substr($phoneKey, 0, 40, 'UTF-8'),
        'note' => phonebookText($value['note'] ?? $value['notiz'] ?? '', 255),
    ];
}

function phonebookContacts(mixed $values, int $limit = 2000): array
{
    if (!is_array($values)) {
        return [];
    }
    $contacts = [];
    $seen = [];
    foreach (array_slice($values, 0, $limit) as $value) {
        $contact = phonebookContact($value);
        if ($contact === null) {
            continue;
        }
        $key = mb_strtolower($contact['name'], 'UTF-8') . '|' . $contact['phone_key'];
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $contacts[] = $contact;
    }
    return $contacts;
}

