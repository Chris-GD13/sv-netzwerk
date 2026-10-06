<?php
declare(strict_types=1);
require_once __DIR__.'/../public/intern/api/gf-evidence-cache.php';
require_once __DIR__.'/../public/intern/api/gf-financial-evidence.php';

$core = file_get_contents(__DIR__.'/../public/intern/api/gf-ai-generate-core.php');
$start = strpos($core, 'function gfExcelEntries(');
$end = strpos($core, 'function gfExcelCell(', $start);
eval(substr($core, $start, $end - $start));
function gfNorm(string $text): string { return mb_strtolower(trim($text), 'UTF-8'); }
function gfInstructionUsesSavedCalculation(string $text): bool { return false; }
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }

check(gfInstructionApprovesKva('Alle KVA und Rechnungen auflisten und freigeben'), 'Konkreter Nutzerauftrag wird nicht erkannt.');
check(gfInstructionApprovesKva('Die Angebote freigeben.'), 'Mehrzahl Angebot wird nicht erkannt.');
check(gfInstructionApprovesKva('Freigabe für den Kostenvoranschlag.'), 'Bestehende Freigabeformulierung geht verloren.');
foreach (['KVA nur auflisten.', 'KVA nicht freigeben.', 'Keine Angebote freigeben.', 'KVA erst nach Prüfung freigeben.', 'KVA freigeben? Noch nicht freigeben.'] as $instruction) {
    check(!gfInstructionApprovesKva($instruction), 'Unzulässige Freigabe: '.$instruction);
}
$entry = ['description'=>'Wiederherstellung','source_type'=>'Freigegebener KVA','source_reference'=>'Brief.pdf, S. 10–12, Angebot A-123','amount'=>1441.57];
check(gfExcelValidateData('schlusserklaerung', ['entries'=>[$entry]], 'Alle KVA und Rechnungen auflisten und freigeben')['passed'], 'Neutral benannte Originalquelle blockiert geprüften KVA.');
try { gfExcelValidateData('schlusserklaerung', ['entries'=>[$entry]], 'KVA nur auflisten'); throw new LogicException('Fehlende Freigabe wurde akzeptiert.'); }
catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'ausdrücklicher Freigabe'), 'Falsche Sperre.'); }

$cache=[]; $calls=0; $missing=false;
function gfSettingGet(string $key, string $default=''): string { return $GLOBALS['cache'][$key] ?? $default; }
function gfSettingSet(string $key, string $value): void { $GLOBALS['cache'][$key]=$value; }
function gfJobUpdate(...$args): void {}
function gfOpenAIUploadDriveFile(array $file, string $scope): array { return ['file_id'=>$file['id'],'name'=>$file['name'],'mime'=>$file['mimeType']]; }
function gfOpenAIUploadName(string $name, string $mime): string { return $name; }
function gfCalculationInputPart(array $ref): array { return ['type'=>'input_file','file_id'=>$ref['file_id']]; }
function gfOpenAI(array $content, string $system, ?int $maxOutputTokens=null): array {
    $GLOBALS['calls']++;
    if ($GLOBALS['missing']) return ['files'=>[]];
    check($content[1]['file_id']==='brief-1', 'Original-Sammelakte wurde nicht gelesen.');
    return ['files'=>[['name'=>'Brief_26-1261626.pdf','financial_documents'=>[
        ['document_type'=>'Rechnung','issuer'=>'Firma A','number'=>'R-123','pages'=>'9','gross'=>250.00],
        ['document_type'=>'Angebot','issuer'=>'Firma B','number'=>'A-123','pages'=>'10–12','gross'=>1441.57],
    ]]]];
}
$file=['id'=>'brief-1','name'=>'Brief_26-1261626.pdf','mimeType'=>'application/pdf','modifiedTime'=>'2026-10-02T10:00:00Z'];
$cache[gfEvidenceFileCacheKey($file)]=json_encode(['files'=>[['name'=>$file['name'],'document_type'=>'Brief','facts'=>['Schriftverkehr']]]]);
$result=gfFinancialExtractCase(1, [$file]);
check($calls===1 && count($result[0]['files'][0]['financial_documents'])===2, 'Alter Brief-Cache verdeckt eingebettete Finanzbelege.');
gfFinancialExtractCase(2, [$file]);
check($calls===1, 'Vollständig geprüfte Finanzbelege müssen wiederverwendbar sein.');
$file['modifiedTime']='2026-10-03T10:00:00Z';
gfFinancialExtractCase(3, [$file]);
check($calls===2, 'Geänderte Originaldatei wurde nicht neu gelesen.');
$file['modifiedTime']='2026-10-04T10:00:00Z'; $missing=true;
try { gfFinancialExtractCase(4, [$file]); throw new LogicException('Unvollständige Belegprüfung wurde akzeptiert.'); }
catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'unvollständig'), 'Unvollständige Belege müssen ausdrücklich sperren.'); }
echo "GF financial evidence tests passed\n";
