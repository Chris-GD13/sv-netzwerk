<?php
declare(strict_types=1);
require_once __DIR__.'/../public/intern/api/case-analysis-core.php';
require_once __DIR__.'/../public/intern/api/case-identity.php';

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function fails(callable $run, string $message): void {
    try { $run(); } catch (RuntimeException $error) { check(str_contains($error->getMessage(), $message), $error->getMessage()); return; }
    throw new RuntimeException('Expected error: '.$message);
}
$queries = [];
$list = function(string $query) use (&$queries): array {
    $queries[] = $query;
    foreach (CA_RULE_ROOTS as $index=>$name) if (str_contains($query, "name='".$name."'")) {
        $root = ['id'=>'root-'.$index, 'name'=>$name, 'parents'=>['drive-root']];
        return $index === 0 ? [$root, ['id'=>'duplicate-standard', 'parents'=>['root-1']]] : [$root];
    }
    if (str_contains($query, "'root-0' in parents")) return [
        ['id'=>'master', 'name'=>'MASTER.md', 'mimeType'=>'text/markdown'],
        ['id'=>'always', 'name'=>'ab sofort immer gültig', 'mimeType'=>'application/vnd.google-apps.folder'],
    ];
    if (str_contains($query, "'always' in parents")) return [['id'=>'current', 'name'=>'AKTUELL.md', 'mimeType'=>'text/markdown']];
    return [['id'=>hash('sha256', $query), 'name'=>'Regel.md', 'mimeType'=>'text/markdown']];
};
$rules = caRuleFiles($list);
check(count($rules) === 4, 'Every rule root and the always-binding directory must be loaded');
check(count(array_filter($rules, fn($file)=>str_contains($file['path'], 'ab sofort immer gültig'))) === 1, 'Binding subfolder missing');
fails(fn()=>caRuleFiles(fn($query)=>[]), 'nicht eindeutig');
$nestedList = function(string $query) use ($list): array {
    if (str_contains($query, "name='00_Standards_Regeln'")) {
        return [['id'=>'root-0', 'name'=>'00_Standards_Regeln', 'parents'=>['root-1']]];
    }
    if (str_contains($query, "'root-1' in parents")) {
        return [['id'=>'root-0', 'name'=>'00_Standards_Regeln', 'mimeType'=>'application/vnd.google-apps.folder']];
    }
    return $list($query);
};
$nestedRules = caRuleFiles($nestedList);
check(count($nestedRules) === 3, 'Nested standards must be reachable and shared MD files loaded only once');
check(count(array_filter($nestedRules, fn($file)=>str_starts_with($file['path'], '00_Standards_Regeln/ab sofort immer gültig/'))) === 1,
    'Knowledge traversal must not overwrite mandatory standards paths');
fails(fn()=>caRuleFiles(function($query) use($nestedList) {
    $files = $nestedList($query);
    if (str_contains($query, "name='00_Standards_Regeln'")) {
        $files[] = ['id'=>'other-standard', 'parents'=>['root-1']];
    }
    return $files;
}), '2 Treffer');
fails(fn()=>caRuleFiles(fn($query)=>[]), '0 Treffer');
fails(fn()=>caRuleFiles(function($query) use($list) {
    if (str_contains($query, "'always' in parents")) return [];
    return $list($query);
}), 'ab sofort immer gültig');
check(caFileKind('Angebot.pdf','application/pdf') === 'file', 'PDF original');
check(caFileKind('Boden.jpg','image/jpeg') === 'image', 'Photo original');
check(caFileKind('Original.eml','message/rfc822') === 'text', 'Original mail');
check(caFileKind('Archiv.zip','application/zip') === 'unsupported', 'No silent ZIP handling');
caTaskMatches(['case_number'=>'26-031 578-4'], ['schaden_nr'=>'26-031578-4']);
fails(fn()=>caTaskMatches(['case_number'=>'26-031578-4'], ['schaden_nr'=>'26-031579-4']), 'anderen Schadennummer');
$result = ['summary'=>'Entwurf', 'assessment'=>'Prüfvorschlag', 'reply_draft'=>'Bitte Nachweise senden.',
    'facts'=>[], 'open_points'=>[], 'next_steps'=>[], 'rule_checks'=>['MASTER.md geprüft']];
check(caValidateResult($result) === $result, 'Valid result');
$requests = caValidateTaskRequests(['requests'=>['Türposition prüfen', 'Bodenaufnahme zuordnen']]);
check($requests[0]['id'] === 'request-1' && $requests[1]['request'] === 'Bodenaufnahme zuordnen', 'Stable current request identity');
fails(fn()=>caValidateTaskRequests(['requests'=>[]]), 'nicht erkannt');
fails(fn()=>caValidateTaskRequests(['requests'=>['']]), 'Ungültiges Anliegen');
$worked = $result;
$worked['task_results'] = [
    ['request_id'=>'request-1', 'answer'=>'Türposition konkret geprüft.', 'status'=>'worked_out', 'source_refs'=>['Angebot.pdf']],
    ['request_id'=>'request-2', 'answer'=>'Die Aufnahme ist nicht datiert; Aufnahmedatum zum Abgleich erforderlich.', 'status'=>'open', 'source_refs'=>['Boden.jpg']],
];
check(caValidateResult($worked, $requests, ['Angebot.pdf', 'Boden.jpg']) === $worked, 'Each current request is worked out or specifically open');
$invalidWork = $worked; array_pop($invalidWork['task_results']);
fails(fn()=>caValidateResult($invalidWork, $requests, ['Angebot.pdf', 'Boden.jpg']), 'nicht vollständig');
$invalidWork = $worked; $invalidWork['task_results'][1]['request_id'] = 'request-1';
fails(fn()=>caValidateResult($invalidWork, $requests, ['Angebot.pdf', 'Boden.jpg']), 'doppelte Anliegen');
$invalidWork = $worked; $invalidWork['task_results'][0]['source_refs'] = ['Erfunden.pdf'];
fails(fn()=>caValidateResult($invalidWork, $requests, ['Angebot.pdf', 'Boden.jpg']), 'nicht gelesene Quelle');
$invalidWork = $worked; $invalidWork['task_results'][0]['source_refs'] = [];
fails(fn()=>caValidateResult($invalidWork, $requests, ['Angebot.pdf', 'Boden.jpg']), 'ohne Beleg');
$invalidWork = $worked; $invalidWork['task_results'][0]['answer'] = '';
fails(fn()=>caValidateResult($invalidWork, $requests, ['Angebot.pdf', 'Boden.jpg']), 'Ungültiges Arbeitsergebnis');
$invalid = $result; $invalid['rule_checks'] = [];
fails(fn()=>caValidateResult($invalid), 'MD-Regelprüfung');
$invalid = $result; $invalid['reply_draft'] = null;
fails(fn()=>caValidateResult($invalid), 'reply_draft');
$batch = [['name'=>'03_Rechnungen/Angebot.pdf'], ['name'=>'02_Bilder/Boden.jpg']];
$read = ['documents'=>[
    ['name'=>$batch[0]['name'], 'findings'=>'Angebot mit Originalsumme und Leistungsumfang', 'warnings'=>[]],
    ['name'=>$batch[1]['name'], 'findings'=>'Bodenaufnahme', 'warnings'=>['Aufnahmedatum fehlt']],
]];
check(count(caValidateEvidence($read,$batch)) === 2, 'All original sources processed');
$wrong = $read; $wrong['documents'][1]['name'] = 'Anderer_Fall.pdf';
fails(fn()=>caValidateEvidence($wrong,$batch), 'falsche oder fehlende');
$wrong = $read; array_pop($wrong['documents']);
fails(fn()=>caValidateEvidence($wrong,$batch), 'unvollständig');
check(str_contains(caPrompt('Originalregel'), 'Originalregel'), 'Actual MD contents in instruction');
check(str_contains(caPrompt(''), 'ENTWURF'), 'Never an automatic release');
echo "case_analysis_test: ok\n";
