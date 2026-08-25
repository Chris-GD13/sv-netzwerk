<?php
declare(strict_types=1);

function gfCaseIsolationText(array $result): string
{
    $parts = [(string)($result['summary'] ?? '')];
    foreach (($result['sections'] ?? []) as $section) {
        if (!is_array($section)) continue;
        $parts[] = (string)($section['heading'] ?? '');
        $parts[] = (string)($section['text'] ?? '');
    }
    foreach (($result['open_points'] ?? []) as $point) $parts[] = (string)$point;
    return trim(implode("\n", $parts));
}

function gfCaseIsolationCompact(string $value): string
{
    return preg_replace('/[^0-9a-zäöüß]+/ui', '', mb_strtolower($value, 'UTF-8')) ?? '';
}

function gfCaseIsolationIdentifiers(string $text): array
{
    $identifiers = [];
    preg_match_all('/(?<!\d)\d{2}-\d{5,8}-\d{1,3}(?!\d)/u', $text, $caseNumbers);
    foreach (($caseNumbers[0] ?? []) as $identifier) $identifiers[] = (string)$identifier;
    preg_match_all('/(?:vertrag(?:s(?:nummer|-nr\.?))?|versicherungsschein(?:nummer|-nr\.?))\D{0,20}(\d{9,12})/ui', $text, $policyNumbers);
    foreach (($policyNumbers[1] ?? []) as $identifier) $identifiers[] = (string)$identifier;
    return array_values(array_unique($identifiers));
}

function gfCaseIsolationAllowedIdentifiers(array $meta, array $sourceNames): array
{
    $haystack = implode(' ', array_merge([
        (string)($meta['schaden_nr'] ?? ''),
        (string)($meta['versicherungsschein_nr'] ?? ''),
        (string)($meta['vertrags_nr'] ?? ''),
    ], array_map('strval', $sourceNames)));
    $allowed = [];
    foreach (gfCaseIsolationIdentifiers($haystack) as $identifier) $allowed[gfCaseIsolationCompact($identifier)] = true;
    foreach (['schaden_nr', 'versicherungsschein_nr', 'vertrags_nr'] as $field) {
        $identifier = trim((string)($meta[$field] ?? ''));
        if ($identifier !== '') $allowed[gfCaseIsolationCompact($identifier)] = true;
    }
    return $allowed;
}

function gfValidateCaseIsolation(array $result, array $meta, array $sourceNames, string $instructions = ''): array
{
    $text = gfCaseIsolationText($result);
    $allowed = gfCaseIsolationAllowedIdentifiers($meta, $sourceNames);
    $foreign = [];
    foreach (gfCaseIsolationIdentifiers($text) as $identifier) {
        $compact = gfCaseIsolationCompact($identifier);
        if ($compact !== '' && !isset($allowed[$compact])) $foreign[$identifier] = true;
    }
    if ($foreign) {
        throw new RuntimeException('Falltrennungs-Sperre: Der Entwurf enthält eine fremde Schaden- oder Vertragsnummer ('.implode(', ', array_keys($foreign)).').');
    }

    $damageType = mb_strtolower((string)($meta['schadenart'] ?? $meta['damage_type'] ?? ''), 'UTF-8');
    $scope = mb_strtolower($text."\n".$instructions, 'UTF-8');
    if (preg_match('/leitungswasser|rohrbruch|wasserschaden/u', $damageType)) {
        $fireSignals = 0;
        foreach (['brandereignis', 'brandschaden', 'rauchbeaufschlag', 'ruß', 'lichtleiste', 'stromschiene', 'ozonbehandlung', 'gefahrenbereich gb 2'] as $signal) {
            if (str_contains($scope, $signal)) $fireSignals++;
        }
        if ($fireSignals >= 2 && !preg_match('/brand(?:schaden|ereignis).*ausdrücklich.*(?:prüfen|berücksichtigen)/u', mb_strtolower($instructions, 'UTF-8'))) {
            throw new RuntimeException('Falltrennungs-Sperre: Der Entwurf enthält mehrere brandspezifische Aussagen, obwohl der aktive Fall als Leitungswasser/Rohrbruch geführt wird.');
        }
    }

    if (preg_match('/feuer|brand/u', $damageType)) {
        $waterSignals = 0;
        foreach (['leitungswasserschaden', 'rohrbruch', 'leckortung', 'trocknungsgerät', 'dämmschichttrocknung'] as $signal) {
            if (str_contains($scope, $signal)) $waterSignals++;
        }
        if ($waterSignals >= 2 && !preg_match('/leitungswasser|rohrbruch/u', mb_strtolower($instructions, 'UTF-8'))) {
            throw new RuntimeException('Falltrennungs-Sperre: Der Entwurf enthält mehrere leitungswasserspezifische Aussagen, obwohl der aktive Fall als Feuer/Brand geführt wird.');
        }
    }

    return ['passed' => true, 'checks' => ['allowed_identifiers_only', 'damage_type_consistency']];
}
