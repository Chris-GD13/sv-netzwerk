<?php
declare(strict_types=1);

/** Normalized representation used by the local case search index. */
function caseSearchNormalize(string $value): string
{
    $value = mb_strtolower(trim($value), 'UTF-8');
    $value = strtr($value, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
    return trim(preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? '');
}

/** All scalar case fields are searchable, including both postal addresses. */
function caseSearchText(array $meta, string $folderName = ''): string
{
    $values = [$folderName];
    array_walk_recursive($meta, static function (mixed $value) use (&$values): void {
        if (is_scalar($value)) {
            $values[] = (string) $value;
        }
    });
    return caseSearchNormalize(implode(' ', $values));
}

function caseSearchTerms(string $query): array
{
    $normalized = caseSearchNormalize($query);
    return $normalized === '' ? [] : array_values(array_filter(explode(' ', $normalized)));
}

function caseSearchMatches(string $searchText, string $query): bool
{
    foreach (caseSearchTerms($query) as $term) {
        if (!str_contains($searchText, $term)) {
            return false;
        }
    }
    return true;
}

function caseSearchScore(string $searchText, string $query): int
{
    $normalized = caseSearchNormalize($query);
    if ($normalized === '') return 0;
    if ($searchText === $normalized) return 300;
    if (str_starts_with($searchText, $normalized)) return 200;
    return caseSearchMatches($searchText, $query) ? 100 : 0;
}
