<?php
declare(strict_types=1);
require_once __DIR__.'/../public/intern/api/case-analysis-core.php';
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
function krDrive(string $url): string {
    global $mode;
    if (!str_contains($url, '?alt=media')) {
        parse_str((string)parse_url($url, PHP_URL_QUERY), $params); $query = $params['q'];
        foreach (CA_RULE_ROOTS as $index=>$name) if (str_contains($query, "name='".$name."'")) {
            return json_encode(['files'=>$mode === 'missing-rules' ? [] : [['id'=>'rules-'.$index, 'name'=>$name]]]);
        }
        if (str_contains($query, "'rules-0' in parents")) return json_encode(['files'=>[
            ['id'=>'binding', 'name'=>'ab sofort immer gültig', 'mimeType'=>'application/vnd.google-apps.folder'],
            ['id'=>'master', 'name'=>'MASTER.md', 'mimeType'=>'text/markdown'],
        ]]);
        return json_encode(['files'=>[['id'=>hash('sha256', $query), 'name'=>'REGEL.md', 'mimeType'=>'text/markdown']]]);
    }
    return 'Verbindliche Originalregel: Fakten und Bewertung trennen.';
}
function ionosList(array $query): array {
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
    return match ($id) { 'meta'=>json_encode(['schaden_nr'=>'26-031578-4', 'vn_objekt'=>'Testobjekt']), 'offer'=>'PDF-original', 'photo'=>'photo-original', default=>throw new RuntimeException('Unexpected original '.$id) };
}
function ionosWrite(array $meta, string $bytes): array {
    if ($GLOBALS['mode'] === 'storage-failure') throw new RuntimeException('IONOS-Speicherung fehlgeschlagen');
    $GLOBALS['written'][] = ['meta'=>$meta, 'record'=>json_decode($bytes,true)];
    return ['id'=>'saved-record'];
}
function otMailbox(array $user): string { return 'test@example.invalid'; }
function otFolderByName(string $mailbox, string $name): array { return ['id'=>'inbox']; }
function otMessage(string $mailbox, string $folder, string $id): array { return ['id'=>$id]; }
function otPage(string $path): array { return [
    ['id'=>'attachment', 'name'=>'Angebot neu.pdf', 'contentType'=>'application/pdf', '@odata.type'=>'#microsoft.graph.fileAttachment'],
    ['id'=>'inline-photo', 'name'=>'Boden-inline.jpg', 'contentType'=>'image/jpeg', 'isInline'=>true, '@odata.type'=>'#microsoft.graph.fileAttachment'],
]; }
function otTaskDetail(array $message, array $attachments): array {
    return ['id'=>$message['id'], 'subject'=>'Tür und Boden', 'body'=>'Bitte Umfang prüfen.',
        'case_number'=>$GLOBALS['mode'] === 'wrong-case' ? '26-031579-4' : '26-031578-4'];
}
function otGraph(string $method, string $path): array {
    return ['contentBytes'=>base64_encode(str_ends_with($path, 'inline-photo') ? 'inline-photo-original' : 'PDF-original')];
}
function krHttp(string $method, string $url, array $headers = [], mixed $body = null): array {
    if ($method === 'DELETE') { $GLOBALS['deleted'][] = $url; return ['status'=>200, 'body'=>'{}']; }
    $GLOBALS['uploaded'][] = $body;
    return ['status'=>200, 'body'=>json_encode(['id'=>'file-'.count($GLOBALS['uploaded'])])];
}
function krOpenAiJson(string $key, array $content, string $system, string $prompt, int $tokens): array {
    $GLOBALS['aiCalls'][] = ['content'=>$content, 'system'=>$system, 'prompt'=>$prompt];
    if (str_contains($prompt, 'JSON mit documents')) {
        $documents = [];
        foreach ($content as $part) if ($part['type'] === 'input_text' && preg_match('/^Original(?:quelle|foto|beleg): ([^\n]+)/', $part['text'], $match)) {
            $documents[] = ['name'=>$match[1], 'findings'=>'Originalquelle mit belegten Angaben geprüft.', 'warnings'=>[]];
        }
        if ($GLOBALS['mode'] === 'incomplete-read') array_pop($documents);
        return ['documents'=>$documents];
    }
    return ['folder_id'=>'wrong-model-folder', 'status'=>'approved', 'summary'=>'Entwurf', 'assessment'=>'Umfang offen',
        'reply_draft'=>'Bitte Nachweise ergänzen.', 'facts'=>['Angebot geprüft'], 'open_points'=>[], 'next_steps'=>[],
        'rule_checks'=>['MASTER.md geprüft']];
}
function assertWorker(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
caRun(1, 'case-folder', 'task-one', 'Tür prüfen', ['id'=>1]);
$done = end($updates);
assertWorker($done[':s'] === 'done', 'Worker must complete after confirmed IONOS write');
assertWorker(count($written) === 1 && $written[0]['meta']['parents'] === ['reports'], 'Existing IONOS report folder');
$record = $written[0]['record'];
assertWorker($record['folder_id'] === 'case-folder' && $record['status'] === 'draft', 'Model cannot override case identity or draft status');
assertWorker(count($record['rules']) === 4, 'All MD areas loaded');
assertWorker(count($record['sources']) === 5, 'Case originals, current attachment and inline photo included');
assertWorker(count(array_filter($record['sources'], fn($file)=>$file['duplicate_of'] !== null)) === 1, 'Same offer not processed twice');
assertWorker(count($record['source_gaps']) === 1 && str_contains($record['source_gaps'][0], 'Alte Mail.msg'), 'Unsupported originals visible');
assertWorker(count($uploaded) === count($deleted), 'Temporary OpenAI files cleaned up');
assertWorker(str_contains(end($aiCalls)['system'], 'Verbindliche Originalregel'), 'MD content used, not just filenames');
foreach (['missing-rules', 'wrong-case', 'incomplete-read', 'storage-failure'] as $failure) {
    $mode = $failure; $updates = []; $written = [];
    caRun(2, 'case-folder', 'task-one', '', ['id'=>1]);
    assertWorker(end($updates)[':s'] === 'failed', $failure.' must fail explicitly');
    assertWorker(!$written, $failure.' must not save a success-shaped output');
}
echo "case_analysis_worker_test: ok\n";
