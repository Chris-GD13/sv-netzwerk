<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
commonHeaders();
$user = requireAuth();
if (!in_array($user['role'] ?? '', ['administrator','projektleiter','pruefer','sachverstaendiger'], true)) apiError(403, 'Keine Berechtigung.');
$index = getenv('INSURANCE_KNOWLEDGE_INDEX') ?: __DIR__ . '/insurance-knowledge-index.json';
if (!is_file($index)) apiError(503, 'Versicherungswissen ist noch nicht bereitgestellt.');
$data = json_decode((string)file_get_contents($index), true);
if (!is_array($data)) apiError(503, 'Versicherungswissen konnte nicht gelesen werden.');
$insurer = trim((string)($_GET['insurer'] ?? ''));
$q = mb_strtolower(trim((string)($_GET['q'] ?? '')), 'UTF-8');
$files = array_values(array_filter($data['files'] ?? [], static function(array $f) use ($insurer, $q): bool {
    if ($insurer !== '' && ($f['insurer'] ?? '') !== $insurer) return false;
    if ($q !== '') return str_contains(mb_strtolower(($f['path'] ?? '').' '.($f['name'] ?? ''), 'UTF-8'), $q);
    return true;
}));
$sheets = $insurer === 'Sparkassen Versicherung' ? ($data['sparkassen_workbook'] ?? []) : [];
apiJson(['ok'=>true,'generated_at'=>$data['generated_at']??null,'file_count'=>$data['file_count']??0,'insurers'=>array_values(array_unique(array_map(static fn(array $f)=>(string)($f['insurer']??''),$data['files']??[]))),'files'=>$files,'workbook'=>$sheets]);
