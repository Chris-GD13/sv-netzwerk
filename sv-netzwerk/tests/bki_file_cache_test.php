<?php
declare(strict_types=1);
require_once __DIR__ . '/../public/intern/api/bki-openai-cache.php';
$status = 200;
$calls = 0;
function bkOpenAIJson(string $method, string $path, ?array $payload = null, int $timeout = 360): array {
    global $status, $calls;
    $calls++;
    if ($status !== 200) throw new RuntimeException('API failure', $status);
    return ['id' => rawurldecode(substr($path, strlen('files/')))];
}
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
$cached = ['file_id' => 'file-old', 'modified' => 'same', 'name' => 'BKI.pdf'];
check(bkCachedOpenAIFile($cached, 'same')['file_id'] === 'file-old', 'Existing file must be reused.');
$status = 404;
check(bkCachedOpenAIFile($cached, 'same') === null, 'Deleted file must be uploaded again.');
foreach ([401, 403, 429, 500, 503] as $status) {
    try { bkCachedOpenAIFile($cached, 'same'); throw new LogicException('Failure was swallowed.'); }
    catch (RuntimeException $error) { check($error->getCode() === $status, 'Real API failure must remain visible.'); }
}
$callsBefore = $calls;
check(bkCachedOpenAIFile($cached, 'changed') === null, 'Changed source must be uploaded again.');
check(bkCachedOpenAIFile([], 'same') === null, 'Empty cache must upload.');
check($calls === $callsBefore, 'Changed or uncached sources need no remote cache check.');
echo "BKI file cache recovery: OK\n";
