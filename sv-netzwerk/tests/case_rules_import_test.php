<?php
declare(strict_types=1);
require_once __DIR__.'/../public/intern/api/ionos-storage.php';
require_once __DIR__.'/../public/intern/api/case-rules.php';
require_once __DIR__.'/../public/intern/api/case-rules-cleanup-core.php';
function env(string $name, string $fallback = ''): string { return getenv($name) ?: $fallback; }
function ruleCheck(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function ruleFails(callable $run, string $message): void {
    try { $run(); } catch (RuntimeException $error) { ruleCheck(str_contains($error->getMessage(), $message), $error->getMessage()); return; }
    throw new RuntimeException('Expected error: '.$message);
}
$root = getenv('CASE_RULES_TEST_ROOT') ?: sys_get_temp_dir().'/case-rules-test-'.bin2hex(random_bytes(8));
function cleanupRuleTest(string $root): void {
    foreach (['objects', 'versions'] as $directory) {
        if (!is_dir($root.'/'.$directory)) continue;
        foreach (glob($root.'/'.$directory.'/*') as $file) unlink($file);
        rmdir($root.'/'.$directory);
    }
    foreach (glob($root.'/index.sqlite*') as $file) unlink($file);
    rmdir($root);
}
if (PHP_OS_FAMILY === 'Windows' && !in_array('--worker', $argv, true)) {
    putenv('CASE_RULES_TEST_ROOT='.$root);
    $process = proc_open([PHP_BINARY, '-d', 'extension_dir='.ini_get('extension_dir'), '-d', 'extension=pdo_sqlite', __FILE__, '--worker'],
        [0=>STDIN, 1=>STDOUT, 2=>STDERR], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Test process could not start');
    $code = proc_close($process);
    cleanupRuleTest($root);
    exit($code);
}
mkdir($root); mkdir($root.'/objects');
putenv('IONOS_STORAGE_ROOT='.$root);
putenv('GOOGLE_DRIVE_KNOWLEDGE_FOLDER_ID=knowledge');
$database = new PDO('sqlite:'.$root.'/index.sqlite');
$database->exec('CREATE TABLE items(id TEXT PRIMARY KEY,parent TEXT,name TEXT,mime TEXT,meta TEXT,path TEXT,verified INTEGER)');
foreach (['root', 'knowledge'] as $id) {
    $meta = ['id'=>$id, 'name'=>$id === 'root' ? 'root' : '00_KI-Wissensbasis', 'mimeType'=>'application/vnd.google-apps.folder', 'parents'=>$id === 'root' ? [] : ['root']];
    $stmt = $database->prepare('INSERT INTO items VALUES(?,?,?,?,?,?,1)');
    $stmt->execute([$id, $id === 'root' ? '' : 'root', $meta['name'], $meta['mimeType'], json_encode($meta), '']);
}
try {
    $canonicalPath = '00_Standards_Regeln/ab sofort immer gültig/MASTER-ARBEITSSTANDARD.md';
    $emptyPlan = crcBuildPlan([], $canonicalPath);
    ruleCheck($emptyPlan['master_count'] === 0 && $emptyPlan['archive_count'] === 0 && !$emptyPlan['ready'], 'No master file must produce an explicit not-ready dry-run');
    $single = [['id'=>'one','path'=>$canonicalPath,'name'=>CRC_MASTER_NAME,'size'=>3,'sha256'=>CRC_MASTER_SHA256]];
    $singlePlan = crcBuildPlan($single, $canonicalPath);
    ruleCheck($singlePlan['master_count'] === 1 && $singlePlan['canonical_count'] === 1 && $singlePlan['archive_count'] === 0 && $singlePlan['ready'], 'Exactly one matching canonical master must be ready');
    $multiple = array_merge($single, [['id'=>'two','path'=>'00_KI-Wissensbasis/MASTER-ARBEITSSTANDARD.md','name'=>CRC_MASTER_NAME,'size'=>3,'sha256'=>CRC_MASTER_SHA256], ['id'=>'three','path'=>'SV-Netzwerk-Projekt/MASTER-ARBEITSSTANDARD.md','name'=>CRC_MASTER_NAME,'size'=>3,'sha256'=>'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA']]);
    $multiplePlan = crcBuildPlan($multiple, $canonicalPath);
    ruleCheck($multiplePlan['master_count'] === 3 && $multiplePlan['archive_count'] === 2 && $multiplePlan['ready'], 'Multiple masters must leave one canonical file and archive the rest');
    ruleCheck(crcClassify($canonicalPath, CRC_MASTER_NAME, 3, CRC_MASTER_SHA256, $canonicalPath)['classification'] === 'kanonisch', 'Canonical file must be classified as canonical');
    ruleCheck(crcClassify('00_KI-Wissensbasis/'.CRC_MASTER_NAME, CRC_MASTER_NAME, 3, CRC_MASTER_SHA256, $canonicalPath)['classification'] === 'identisch', 'Matching non-canonical file must be classified as identical');
    ruleCheck(crcClassify('SV-Netzwerk-Projekt/'.CRC_MASTER_NAME, CRC_MASTER_NAME, 3, str_repeat('A', 64), $canonicalPath)['classification'] === 'Alt-/Doppelfassung', 'Hash deviation must be classified as old/duplicate');
    ruleCheck(str_ends_with(crcArchiveName('SV-Netzwerk-Projekt/'.CRC_MASTER_NAME, 'legacy-id'), '__'.CRC_MASTER_NAME), 'Archive name must remain recoverable and retain the original filename');
    ruleFails(fn()=>caIonosRuleFiles(), 'MASTER-ARBEITSSTANDARD.md fehlt');
    ruleFails(fn()=>caImportRule('../escape.md', 'rule', 'standards', false), 'Dateinamen');
    ruleFails(fn()=>caImportRule('rule.php', 'rule', 'standards', false), 'Dateinamen');
    ruleFails(fn()=>caImportRule('rule.md', "\xff", 'standards', false), 'UTF-8');
    $saved = caImportRule('MASTER-ARBEITSSTANDARD.md', '# Master original', 'standards', false);
    ruleCheck(ionosBytes($saved['id']) === '# Master original', 'Original bytes must match');
    $duplicate = caImportRule('MASTER-ARBEITSSTANDARD.md', '# Master original', 'standards', false);
    ruleCheck($duplicate['duplicate'] && $duplicate['id'] === $saved['id'], 'Repeat uploads must not duplicate originals');
    ruleFails(fn()=>caImportRule('MASTER-ARBEITSSTANDARD.md', '# New master', 'standards', false), 'Ersetzen');
    $replacement = caImportRule('MASTER-ARBEITSSTANDARD.md', '# New master', 'standards', true);
    ruleCheck($replacement['id'] === $saved['id'] && ionosBytes($saved['id']) === '# New master', 'Replacement must preserve identity');
    ruleCheck(count(glob($root.'/versions/*')) === 1, 'Prior version must be preserved');
    $archive = ionosWrite(['name'=>'ARCHIV – NICHT AKTIV', 'mimeType'=>'application/vnd.google-apps.folder', 'parents'=>['root']], null);
    $legacy = ionosWrite(['name'=>CRC_MASTER_NAME, 'mimeType'=>'text/markdown', 'parents'=>['knowledge']], '# legacy');
    $legacyBytes = ionosBytes($legacy['id']);
    ionosWrite(['name'=>'2026-11-05__legacy__'.CRC_MASTER_NAME, 'parents'=>[$archive['id']]], null, $legacy['id']);
    $legacyAfter = ionosItem($legacy['id']);
    ruleCheck(($legacyAfter['parents'][0] ?? '') === $archive['id'] && ionosBytes($legacy['id']) === $legacyBytes, 'Recoverable archive move must preserve the original bytes');
    caImportRule('Richtlinie.pdf', '%PDF-original', 'knowledge', false);
    caImportRule('Vorlage.docx', 'original-template', 'templates', false);
    $rules = caIonosRuleFiles();
    ruleCheck(count($rules) === 4, 'All MD originals, duplicate master, PDF and Office originals must be available without duplicate traversal');
    ruleCheck(count(array_filter($rules, fn($file)=>str_contains($file['path'], '/ab sofort immer gültig/'))) === 1, 'Binding originals must retain their path');
    echo "case_rules_import_test: ok\n";
} finally {
    $database = null;
    if (PHP_OS_FAMILY !== 'Windows') cleanupRuleTest($root);
}
