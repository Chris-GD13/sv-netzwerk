<?php
declare(strict_types=1);
require_once __DIR__.'/../public/intern/api/case-analysis-core.php';
require_once __DIR__.'/../public/intern/api/case-rules.php';
require_once __DIR__.'/../public/intern/api/case-identity.php';
$source = str_replace("\r\n", "\n", file_get_contents(__DIR__.'/../public/intern/api/case-analysis.php'));
$start = strpos($source, 'function caJobs()');
$end = strpos($source, "\ntry {\n    if (\$_SERVER");
if ($start === false || $end === false) throw new RuntimeException('Worker declarations not found');
eval(substr($source, $start, $end - $start));

$mode = ''; $updates = []; $written = []; $uploaded = []; $deleted = []; $aiCalls = [];
class FakeStatement {
    public function execute(array $params): void { $GLOBALS['updates'][] = $params; }
}
class FakeDatabase {
    public function prepare(string $sql): FakeStatement { return new FakeStatement(); }
}
function db(): FakeDatabase { return new FakeDatabase(); }
function env(string $key, string $fallback = ''): string { return $key === 'OPENAI_API_KEY' ? 'test-not-a-secret' : $fallback; }
function krSenderProfile(array $user): array { return ['name'=>'Test', 'email'=>'test@example.invalid']; }
function ionosItem(string $id): array { return ['id'=>'rules-1', 'name'=>'00_KI-Wissensbasis', 'mimeType'=>'application/vnd.google-apps.folder']; }
function ionosList(array $query): array {
    global $mode;
    $q = $query['q'];
    if (str_contains($q, "name=") || str_contains($q, "'rules-") || str_contains($q, "'binding'")) {
        foreach (CA_RULE_ROOTS as $index=>$name) if (str_contains($q, "name='".$name."'")) {
            return ['files'=>$mode === 'missing-rules' ? [] : [['id'=>'rules-'.$index, 'name'=>$name]]];
        }
        if (str_contains($q, "'rules-0' in parents")) return ['files'=>[
            ['id'=>'binding', 'name'=>'ab sofort immer gültig', 'mimeType'=>'application/vnd.google-apps.folder'],
            ['id'=>'master', 'name'=>'MASTER-ARBEITSSTANDARD.md', 'mimeType'=>'text/markdown'],
        ]];
        if (str_contains($q, "'rules-1' in parents")) return ['files'=>[
            ['id'=>'rule-pdf', 'name'=>'Richtlinie.pdf', 'mimeType'=>'application/pdf'],
            ['id'=>hash('sha256', $q), 'name'=>'REGEL.md', 'mimeType'=>'text/markdown'],
        ]];
        return ['files'=>[['id'=>hash('sha256', $q), 'name'=>'REGEL.md', 'mimeType'=>'text/markdown']]];
    }
    if (str_contains($query['q'], "'reports'")) return ['files'=>[]];
    if (str_contains($query['q'], "'photos'")) return ['files'=>[['id'=>'photo', 'name'=>'Boden.jpg', 'mimeType'=>'image/jpeg', 'size'=>8]]];
    return ['files'=>[
        ['id'=>'meta', 'name'=>'00_Falldaten.json', 'mimeType'=>'application/json', 'size'=>100],
        ['id'=>'offer', 'name'=>'Angebot neu.pdf', 'mimeType'=>'application/pdf', 'size'=>12],
        ['id'=>'legacy', 'name'=>'Alte Mail.msg', 'mimeType'=>'application/vnd.ms-outlook', 'size'=>10],
        ['id'=>'photos', 'name'=>'02_Bilder', 'mimeType'=>'application/vnd.google-apps.folder'],
        ['id'=>'reports', 'name'=>'05_Berichte', 'mimeType'=>'application/vnd.google-apps.folder'],
    ]];
}
function ionosBytes(string $id): string {
    if ($id === 'master' || preg_match('/^[a-f0-9]{64}$/D', $id)) return 'Verbindliche Originalregel: Fakten und Bewertung trennen.';
    return match ($id) { 'meta'=>json_encode(['schaden_nr'=>'26-031578-4', 'vn_objekt'=>'Testobjekt']), 'offer'=>'PDF-original', 'photo'=>'photo-original', 'rule-pdf'=>'PDF-rule-original', default=>throw new RuntimeException('Unexpected original '.$id) };
}
function ionosWrite(array $meta, string $bytes): array {
    if ($GLOBALS['mode'] === 'storage-failure') throw new RuntimeException('IONOS-Speicherung fehlgeschlagen');
    $GLOBALS['written'][] = ['meta'=>$meta, 'record'=>json_decode($bytes,true)];
    return ['id'=>'saved-record'];
}
function otMailbox(array $user): string { return 'test@example.invalid'; }
function otFolderByName(string $mailbox, string $name): array { return ['id'=>'inbox']; }
function otMessage(string $mailbox, string $folder, string $id): array { return ['id'=>$id]; }
function otPage(string $path): array {
    if ($GLOBALS['mode'] === 'many-attachments') return array_map(fn($index)=>[
        'id'=>'attachment-'.$index, 'name'=>'Original-'.$index.'.pdf', 'contentType'=>'application/pdf',
        '@odata.type'=>'#microsoft.graph.fileAttachment',
    ], range(1, 10));
    return [
    ['id'=>'attachment', 'name'=>'Angebot neu.pdf', 'contentType'=>'application/pdf', '@odata.type'=>'#microsoft.graph.fileAttachment'],
    ['id'=>'inline-photo', 'name'=>'Boden-inline.jpg', 'contentType'=>'image/jpeg', 'isInline'=>true, '@odata.type'=>'#microsoft.graph.fileAttachment'],
]; }
function otTaskDetail(array $message, array $attachments): array {
    return ['id'=>$message['id'], 'subject'=>'Tür und Boden', 'body'=>'Bitte die Türposition im Angebot prüfen und die Bodenaufnahme zuordnen.',
        'case_number'=>$GLOBALS['mode'] === 'wrong-case' ? '26-031579-4' : '26-031578-4'];
}
function otGraph(string $method, string $path): array {
    if ($GLOBALS['mode'] === 'many-attachments') return ['contentBytes'=>base64_encode('PDF-original-'.basename($path))];
    return ['contentBytes'=>base64_encode(str_ends_with($path, 'inline-photo') ? 'inline-photo-original' : 'PDF-original')];
}
function krHttp(string $method, string $url, array $headers = [], mixed $body = null): array {
    if ($method === 'DELETE') { $GLOBALS['deleted'][] = $url; return ['status'=>200, 'body'=>'{}']; }
    $GLOBALS['uploaded'][] = $body;
    return ['status'=>200, 'body'=>json_encode(['id'=>'file-'.count($GLOBALS['uploaded'])])];
}
function krOpenAiJson(string $key, array $content, string $system, string $prompt, int $tokens): array {
    $GLOBALS['aiCalls'][] = ['content'=>$content, 'system'=>$system, 'prompt'=>$prompt];
    if (str_contains($prompt, 'JSON mit requests')) return ['requests'=>$GLOBALS['mode'] === 'missing-requests' ? [] : [
        'Türposition im Angebot prüfen', 'Bodenaufnahme zuordnen',
    ]];
    if (str_contains($prompt, 'JSON mit documents')) {
        $documents = [];
        foreach ($content as $part) if ($part['type'] === 'input_text' && preg_match('/^Original(?:quelle|foto|beleg): ([^\n]+)/', $part['text'], $match)) {
            $documents[] = ['name'=>$match[1], 'findings'=>'Originalquelle mit belegten Angaben geprüft.',
                'warnings'=>$GLOBALS['mode'] === 'unreadable-rule' && str_contains($match[1], 'Richtlinie.pdf') ? ['Seite unlesbar'] : []];
            if (str_contains($prompt, 'content_complete')) {
                $index = array_key_last($documents);
                $documents[$index]['content_complete'] = $GLOBALS['mode'] !== 'incomplete-rule-content';
                $documents[$index]['layout_warnings'] = $GLOBALS['mode'] === 'layout-only' ? ['Formularraster und visuelle Platzierung nicht rekonstruierbar'] : [];
                $documents[$index]['empty_template_fields'] = $GLOBALS['mode'] === 'blank-template'
                    ? ['Telefon / Mobil (Versicherungsnehmer)', 'E-Mail (Versicherungsnehmer)', 'Hier können Sie Ihre Fragen formulieren'] : [];
                if ($GLOBALS['mode'] === 'missing-rule-completeness') unset($documents[$index]['content_complete']);
                if ($GLOBALS['mode'] === 'invalid-empty-field') $documents[$index]['empty_template_fields'] = [42];
                if ($GLOBALS['mode'] === 'invalid-layout-warning') $documents[$index]['layout_warnings'] = [42];
            }
        }
        if ($GLOBALS['mode'] === 'incomplete-read') array_pop($documents);
        return ['documents'=>$documents];
    }
    $result = ['folder_id'=>'wrong-model-folder', 'status'=>'approved', 'summary'=>'Entwurf', 'assessment'=>'Umfang offen',
        'reply_draft'=>'Bitte Nachweise ergänzen.', 'facts'=>['Angebot geprüft'], 'open_points'=>[], 'next_steps'=>[],
        'task_results'=>[
            ['request_id'=>'request-1', 'request'=>'Falscher Modelgegenstand', 'answer'=>'Die Türposition ist anhand des Angebots fachlich geprüft.', 'status'=>'worked_out', 'source_refs'=>['Angebot neu.pdf']],
            ['request_id'=>'request-2', 'answer'=>'Die Bodenaufnahme wurde dem Prüfgegenstand zugeordnet.', 'status'=>'worked_out', 'source_refs'=>['02_Bilder/Boden.jpg']],
        ],
        'rule_checks'=>['MASTER.md geprüft']];
    if ($GLOBALS['mode'] === 'missing-task-result') array_pop($result['task_results']);
    if ($GLOBALS['mode'] === 'open-task-result') {
        $result['task_results'][1]['status'] = 'open';
        $result['task_results'][1]['answer'] = 'Für die Zuordnung fehlt das Aufnahmedatum des Bodenfotos.';
    }
    if ($GLOBALS['mode'] === 'unknown-task-source') $result['task_results'][0]['source_refs'] = ['Nicht gelesen.pdf'];
    return $result;
}
function assertWorker(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
caRun(1, 'case-folder', 'task-one', 'Tür prüfen', ['id'=>1]);
$done = end($updates);
assertWorker($done[':s'] === 'done', 'Worker must complete after confirmed IONOS write');
assertWorker(count($written) === 1 && $written[0]['meta']['parents'] === ['reports'], 'Existing IONOS report folder');
$record = $written[0]['record'];
assertWorker($record['folder_id'] === 'case-folder' && $record['status'] === 'draft', 'Model cannot override case identity or draft status');
assertWorker(count($record['rules']) === 5, 'All MD areas and binary original rules loaded');
assertWorker(count($record['sources']) === 5, 'Case originals, current attachment and inline photo included');
assertWorker(count(array_filter($record['sources'], fn($file)=>$file['duplicate_of'] !== null)) === 1, 'Same offer not processed twice');
assertWorker(count($record['source_gaps']) === 1 && str_contains($record['source_gaps'][0], 'Alte Mail.msg'), 'Unsupported originals visible');
assertWorker(count($uploaded) === count($deleted), 'Temporary OpenAI files cleaned up');
assertWorker(str_contains(end($aiCalls)['system'], 'Verbindliche Originalregel'), 'MD content used, not just filenames');
assertWorker(str_contains($aiCalls[0]['prompt'], 'JSON mit requests') && str_contains($aiCalls[0]['prompt'], 'Türposition im Angebot prüfen'),
    'Actual current message must define the work before reading generic rules');
assertWorker(str_contains($aiCalls[1]['system'], 'Transkribiere'), 'Original PDF rules require full transcription, not case evidence extraction');
assertWorker($record['task_results'][0]['request'] === 'Türposition im Angebot prüfen', 'Server must preserve the actual request, not a model replacement');
assertWorker(count($record['task_results']) === 2, 'Each current request must have an actual work result');
assertWorker(str_contains(end($aiCalls)['prompt'], 'task_requests') && str_contains(end($aiCalls)['system'], 'HAUPTAUFTRAG'),
    'Final work must be focused on current requests, not generic case analysis');
assertWorker(str_contains($aiCalls[2]['prompt'], 'Türposition im Angebot prüfen'), 'Original reading must retain the concrete current task');
$mode = 'many-attachments'; $updates = []; $written = []; $aiCalls = [];
caRun(2, 'case-folder', 'task-one', '', ['id'=>1]);
assertWorker(end($updates)[':s'] === 'done', 'All current task attachments must be read without separate attachment imports');
$attachmentRecord = $written[0]['record'];
assertWorker(count(array_filter($attachmentRecord['sources'], fn($file)=>str_starts_with($file['name'], 'Aktuelle E-Mail/'))) === 10,
    'All ten current message originals must be recorded');
foreach (range(1, 10) as $index) {
    assertWorker(str_contains(end($aiCalls)['prompt'], 'Aktuelle E-Mail\/Original-'.$index.'.pdf'),
        'Current attachment '.$index.' must reach the final work context');
}
$mode = 'open-task-result'; $updates = []; $written = [];
caRun(2, 'case-folder', 'task-one', '', ['id'=>1]);
assertWorker(end($updates)[':s'] === 'done' && count($written[0]['record']['task_results']) === 2,
    'One genuinely unresolved request must not discard other worked-out results');
assertWorker(in_array('Bodenaufnahme zuordnen: Für die Zuordnung fehlt das Aufnahmedatum des Bodenfotos.', $written[0]['record']['open_points'], true),
    'Unresolved request must persist with its specific missing evidence');
$mode = 'blank-template'; $updates = []; $written = [];
caRun(2, 'case-folder', 'task-one', '', ['id'=>1]);
assertWorker(end($updates)[':s'] === 'done', 'Intentionally blank template fields must not prevent working out the actual task');
$blankRecord = $written[0]['record'];
assertWorker(count($blankRecord['rules'][0]['empty_template_fields']) === 3, 'Empty field names must be retained in rule provenance');
assertWorker(!array_filter($blankRecord['open_points'], fn($point)=>str_contains($point, 'Versicherungsnehmer')),
    'General empty template fields must not become missing case evidence');
$mode = ''; $updates = []; $written = [];
caRun(2, 'case-folder', '', '', ['id'=>1]);
assertWorker(end($updates)[':s'] === 'done' && $written[0]['record']['task_requests'] === [],
    'General case analysis without a current task must remain supported');
assertWorker(!isset($written[0]['record']['task_results']), 'General analysis must not display model-invented tasks');
$mode = 'layout-only'; $updates = []; $written = [];
caRun(2, 'case-folder', 'task-one', '', ['id'=>1]);
assertWorker(end($updates)[':s'] === 'done', 'Layout-only limitations must not block a content-complete analysis');
$layoutRecord = $written[0]['record'];
assertWorker(count(array_filter($layoutRecord['open_points'], fn($point)=>str_contains($point, 'Layout wurde nicht bestätigt'))) === 1,
    'Layout limitations must remain visible in persisted open points');
assertWorker(count(array_filter($layoutRecord['rules'], fn($rule)=>!empty($rule['layout_warnings']))) === 1,
    'Rule manifest must retain layout limitations');
foreach (['missing-rules', 'wrong-case', 'incomplete-read', 'storage-failure', 'unreadable-rule',
    'incomplete-rule-content', 'missing-rule-completeness', 'invalid-layout-warning', 'invalid-empty-field',
    'missing-requests', 'missing-task-result', 'unknown-task-source'] as $failure) {
    $mode = $failure; $updates = []; $written = [];
    caRun(2, 'case-folder', 'task-one', '', ['id'=>1]);
    assertWorker(end($updates)[':s'] === 'failed', $failure.' must fail explicitly');
    assertWorker(!$written, $failure.' must not save a success-shaped output');
}
echo "case_analysis_worker_test: ok\n";
