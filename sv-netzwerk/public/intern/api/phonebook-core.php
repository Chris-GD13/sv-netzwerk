<?php
declare(strict_types=1);

function phonebookText(mixed $value, int $limit): string
{
    $text = trim((string)$value);
    $text = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $text) ?? $text;
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return mb_substr($text, 0, $limit, 'UTF-8');
}

function phonebookNote(mixed $value, int $limit = 255): string
{
    $text = phonebookText($value, 2000);
    $text = preg_replace('~\bms-outlook://\S+~iu', ' ', $text) ?? $text;
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return mb_substr(trim($text), 0, $limit, 'UTF-8');
}

function phonebookPhoneKey(mixed $value): string
{
    $digits = preg_replace('/\D+/', '', (string)$value) ?? '';
    $digits = preg_replace('/^0049(?=\d{5,})/', '', $digits) ?? $digits;
    $digits = preg_replace('/^49(?=\d{5,})/', '', $digits) ?? $digits;
    return preg_replace('/^0+/', '', $digits) ?? $digits;
}

function phonebookNameKey(mixed $value): string
{
    return mb_strtolower(phonebookText($value, 150), 'UTF-8');
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
        'note' => phonebookNote($value['note'] ?? $value['notiz'] ?? ''),
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
        $key = $contact['phone_key'] . '|' . phonebookNameKey($contact['name']);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $contacts[] = $contact;
    }
    return $contacts;
}
