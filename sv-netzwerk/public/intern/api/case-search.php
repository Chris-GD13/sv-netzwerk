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

/** Uses only columns from the long-standing production table. */
function searchCaseFolderIndex(array $user, string $query, int $limit = 30): array
{
    if (empty($user['id']) || caseSearchNormalize($query) === '') return [];
    $stmt = db()->prepare('SELECT folder_id,case_no,policy_no,object_name,damage_type,case_type,registered_at
        FROM case_folder_owners WHERE user_id=:user_id AND user_email=:user_email ORDER BY registered_at DESC LIMIT 1000');
    $stmt->execute([':user_id'=>(int)$user['id'],':user_email'=>(string)($user['email']??'')]);
    $results = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $meta = ['schaden_nr'=>(string)($row['case_no']??''),'versicherungsschein_nr'=>(string)($row['policy_no']??''),'vn_objekt'=>(string)($row['object_name']??''),'schadenart'=>(string)($row['damage_type']??''),'fallart'=>(string)($row['case_type']??'')];
        $searchText = caseSearchText($meta);
        if (!caseSearchMatches($searchText, $query)) continue;
        $results[] = [
            'id'=>(string)$row['folder_id'],
            'name'=>(string)($meta['schaden_nr'] ?: ($meta['vn_objekt'] ?: 'Versicherungsfall')),
            'modifiedTime'=>(string)($row['registered_at']??''),
            'webViewLink'=>null,
            'meta'=>$meta,
            '_search_text'=>$searchText,
        ];
        if (count($results) >= $limit) break;
    }
    return $results;
}
