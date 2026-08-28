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

function kvaPreferredSiteRole(string $role): int
{
    $normalized = mb_strtolower(trim($role), 'UTF-8');
    if ($normalized === '') return 0;
    if (preg_match('/\bprojektleiter(?:in)?\b|\bprojektleitung\b/u', $normalized) === 1) return 30;
    if (preg_match('/\boberbauleiter(?:in)?\b/u', $normalized) === 1) return 20;
    if (preg_match('/\bbauleiter(?:in)?\b|\bbauleitung\b/u', $normalized) === 1) return 10;
    return 0;
}

function kvaDetectedCaseContacts(array $result): array
{
    $preferred = [];
    foreach (is_array($result['contact_people'] ?? null) ? $result['contact_people'] : [] as $person) {
        if (!is_array($person)) continue;
        $name = kvaContactString($person['name'] ?? '');
        $role = kvaContactString($person['role'] ?? '');
        $priority = kvaPreferredSiteRole($role);
        if ($name !== '' && $priority > 0) $preferred[] = ['name'=>$name, 'role'=>$role, 'priority'=>$priority];
    }

    if ($preferred !== []) {
        $bestPriority = max(array_column($preferred, 'priority'));
        $people = [];
        $roles = [];
        foreach ($preferred as $person) {
            if ($person['priority'] !== $bestPriority) continue;
            $people[] = $person['name'];
            $roles[] = $person['role'];
        }
        $result['contact_person'] = implode('; ', array_values(array_unique($people)));
        $result['contact_role'] = implode('; ', array_values(array_unique($roles)));
    } else {
        $fallbackRole = kvaContactString($result['contact_role'] ?? '');
        if (kvaPreferredSiteRole($fallbackRole) > 0) {
            $result['contact_person'] = kvaContactString($result['contact_person'] ?? '');
            $result['contact_role'] = $fallbackRole;
        } else {
            // Geschäftsführer, Inhaber und sonstige Organvertreter sind keine Sanierer-Ansprechpartner
            // für die operative Schadenbearbeitung. Ohne Projekt-/Bauleitung bleiben die Felder leer.
            $result['contact_person'] = '';
            $result['contact_role'] = '';
        }
    }

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
