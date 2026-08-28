<?php
declare(strict_types=1);

function kvaContactFieldMap(): array
{
    return [
        'company'=>'sanierer_firma', 'contact_person'=>'sanierer_ansprechpartner', 'contact_role'=>'sanierer_funktion',
        'street'=>'sanierer_strasse', 'postal_code'=>'sanierer_plz', 'city'=>'sanierer_ort',
        'email'=>'sanierer_email', 'phone'=>'sanierer_telefon', 'mobile'=>'sanierer_mobil',
        'fax'=>'sanierer_fax', 'website'=>'sanierer_website',
    ];
}

function kvaContactString(mixed $value): string
{
    if (is_array($value)) $value = implode('; ', array_filter(array_map('strval', $value)));
    return mb_substr(trim((string)$value), 0, 500);
}

function kvaDetectedCaseContacts(array $result): array
{
    $people = [];
    $roles = [];
    foreach (is_array($result['contact_people'] ?? null) ? $result['contact_people'] : [] as $person) {
        if (!is_array($person)) continue;
        $name = kvaContactString($person['name'] ?? '');
        $role = kvaContactString($person['role'] ?? '');
        if ($name !== '') $people[] = $name;
        if ($role !== '') $roles[] = $role;
    }
    if ($people === []) $people[] = kvaContactString($result['contact_person'] ?? '');
    if ($roles === []) $roles[] = kvaContactString($result['contact_role'] ?? '');
    $result['contact_person'] = implode('; ', array_values(array_unique(array_filter($people))));
    $result['contact_role'] = implode('; ', array_values(array_unique(array_filter($roles))));
    $contacts = [];
    foreach (kvaContactFieldMap() as $source=>$target) {
        $value = kvaContactString($result[$source] ?? '');
        if ($value !== '') $contacts[$target] = $value;
    }
    return $contacts;
}

function kvaContactComparable(string $value): string
{
    return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($value, 'UTF-8')) ?? '';
}

function kvaMergeCaseContacts(array $case, array $detected, string $source, string $quoteNumber, ?string $timestamp = null): array
{
    $applied = [];
    $conflicts = [];
    foreach (array_values(kvaContactFieldMap()) as $field) {
        $incoming = kvaContactString($detected[$field] ?? '');
        if ($incoming === '') continue;
        $existing = kvaContactString($case[$field] ?? '');
        if ($existing === '') {
            $case[$field] = $incoming;
            $applied[$field] = $incoming;
        } elseif (kvaContactComparable($existing) !== kvaContactComparable($incoming)) {
            $conflicts[$field] = ['existing'=>$existing, 'detected'=>$incoming];
        }
    }
    $hints = is_array($case['sanierer_kva_hinweise'] ?? null) ? $case['sanierer_kva_hinweise'] : [];
    if ($conflicts !== []) {
        $fingerprint = hash('sha256', json_encode([$source, $quoteNumber, $conflicts], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
        if (!in_array($fingerprint, array_column($hints, 'fingerprint'), true)) $hints[] = [
            'fingerprint'=>$fingerprint, 'source'=>$source, 'quote_number'=>$quoteNumber,
            'detected_at'=>$timestamp ?? gmdate('c'), 'conflicts'=>$conflicts,
        ];
        $case['sanierer_kva_hinweise'] = array_slice($hints, -50);
    }
    return ['case'=>$case, 'applied'=>$applied, 'conflicts'=>$conflicts];
}
