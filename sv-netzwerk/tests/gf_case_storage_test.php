<?php
declare(strict_types=1);
function ionosStorageEnabled(): bool { return true; }
function ionosItem(string $id): array {
    if ($id === 'broken') throw new RuntimeException('Database unavailable');
    $items = [
        'old-case-id' => ['mimeType'=>'application/vnd.google-apps.folder','parents'=>[]],
        'old-report-id' => ['parents'=>['old-case-id']],
        'old-pdf-id' => ['parents'=>['old-report-id']],
        'other-case-id' => ['parents'=>[]],
    ];
    if (!isset($items[$id])) throw new RuntimeException('Datei nicht im geprüften IONOS-Bestand: '.$id);
    return $items[$id];
}
function ionosWrite(array $meta, string $bytes): array { return $meta + ['id'=>'ionos_created','bytes'=>$bytes]; }
function gfHttp(...$args): array { throw new RuntimeException('Unexpected external case upload'); }
require __DIR__.'/../public/intern/api/gf-case-storage.php';
function verifyStorage(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
gfCaseStorageActivate('old-case-id');
verifyStorage(gfCaseStorageLocal('old-pdf-id'), 'Imported file IDs must resolve to IONOS');
verifyStorage(!gfCaseStorageLocal('external-rule-master'), 'External standards remain external');
verifyStorage(!gfCaseStorageLocal('other-case-id'), 'Other case is outside active root');
$failed=false;
try { gfCaseStorageLocal('broken'); } catch (RuntimeException $e) { $failed=$e->getMessage()==='Database unavailable'; }
verifyStorage($failed, 'Storage failures must not fall back to external storage');
$core=file_get_contents(__DIR__.'/../public/intern/api/gf-ai-generate-core.php');
preg_match('/^function gfUpload\([^\n]+/m', $core, $match);
eval($match[0]);
$created=gfUpload('old-report-id','register.doc','application/msword','proof');
verifyStorage($created['id']==='ionos_created' && $created['parents']===['old-report-id'], 'Imported parent must write to IONOS');
echo "gf_case_storage_test: OK\n";
