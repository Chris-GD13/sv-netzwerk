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
    if (!empty($GLOBALS['registry_repair'])) {
        check($content[0]['text']==='Originalbeleg R-123: 250 EUR', 'Originalevidenz fehlt bei Gliederungskorrektur.');
        check(str_contains($content[1]['text'], 'R-123') && $maxOutputTokens===16000, 'Entwurf oder vollständiges Ausgabelimit fehlt.');
        return ['sections'=>array_map(fn($h)=>['heading'=>$h,'text'=>'R-123: 250 EUR'],gfHeadings('rechnungsregister'))];
    }
    if ($GLOBALS['missing']) return ['files'=>[]];
    check($content[1]['file_id']==='brief-1', 'Original-Sammelakte wurde nicht gelesen.');
    return ['files'=>[['name'=>'Brief_26-1261626.pdf','financial_documents'=>[
        ['document_type'=>'Rechnung','issuer'=>'Firma A','number'=>'R-123','pages'=>'9','gross'=>250.00],
        ['document_type'=>'Angebot','issuer'=>'Firma B','number'=>'A-123','pages'=>'10–12','gross'=>1441.57],
    ]]]];
}
$file=['id'=>'brief-1','name'=>'Brief_26-1261626.pdf','mimeType'=>'image/jpeg','modifiedTime'=>'2026-10-02T10:00:00Z'];
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
$headingsStart=strpos($core, 'function gfHeadings(');
$headingsEnd=strpos($core, "\n", $headingsStart);
eval(substr($core, $headingsStart, $headingsEnd-$headingsStart));
$registry_repair=true;
check(gfFinancialPageRanges(71)===[[1,12],[11,22],[21,32],[31,42],[41,52],[51,62],[61,71]], 'Große Sammelakte muss vollständig mit Überlappung gelesen werden.');
$tempPdf=gfFinancialTempPdf('gf-test-');
check(str_ends_with($tempPdf,'.pdf') && file_exists($tempPdf), 'PDF-Prüfdateien benötigen eine PDF-Dateiendung.');
unlink($tempPdf);
check(!gfFinancialRegisterComplete(['sections'=>[]],[['type'=>'input_text','text'=>json_encode(['files'=>[['financial_documents'=>[['number'=>'R-123']]]]])]]), 'Erkannter Originalbeleg darf im Register nicht fehlen.');
$repaired=gfFinancialRegisterStructure(['sections'=>[['heading'=>'Abweichend','text'=>'R-123: 250 EUR']]], [['type'=>'input_text','text'=>'Originalbeleg R-123: 250 EUR']], 'Register');
check(count($repaired['sections'])===6, 'Register muss sechs Abschnitte enthalten.');
$previousCalls=$calls;
gfFinancialRegisterStructure($repaired, [], 'Register');
check($calls===$previousCalls, 'Gültige Register benötigen keine weitere KI-Korrektur.');
$documents=[];
foreach ([476,388.93,297.50,589.05,3928.56,1261.52] as $index=>$amount) $documents[]=['document_type'=>'Rechnung','issuer'=>'Firma A','number'=>'R'.($index+1),'gross'=>$amount,'pages'=>(string)($index+1),'payment_status'=>$index<3?'bezahlt':'kein Zahlungsnachweis'];
foreach ([1441.57,2145.53,4803.84] as $index=>$amount) $documents[]=['document_type'=>'Angebot','issuer'=>'Firma A','number'=>'K'.($index+1),'gross'=>$amount,'pages'=>(string)($index+10),'approval_status'=>$index<2?'freigegeben':'offen'];
$documents[]=$documents[0]; // overlap of two page blocks must not double count
$documents[]=['document_type'=>'KVA','issuer'=>'Firma A','number'=>'','gross'=>1441.57,'description'=>'KVA nur im Anschreiben erwähnt'];
$documents[]=['document_type'=>'Rechnung','issuer'=>'Technischer Bericht','number'=>'','gross'=>null,'description'=>'Messprotokoll'];
$context=['offer_invoice_links'=>[['offer_numbers'=>['K1','K2'],'invoice_number'=>'R5','source'=>'Originalrechnung']], 'drying_kwh'=>226,'electricity_rate'=>0.35];
$register=gfFinancialRegisterGenerate([['files'=>[['name'=>'Brief.pdf','financial_documents'=>$documents]]]],'', $context);
$rendered=json_encode($register,JSON_UNESCAPED_UNICODE);
foreach (['6.941,56','1.162,43','5.779,13','3.587,10','341,46','11.745,40','10.582,97','79,10','11.824,50','10.662,07'] as $expected) check(str_contains($rendered,$expected), 'Fehlerhafter Abrechnungswert: '.$expected);
check($register['sections'][0]['tables'][0]['caption']==='KVA – getrennte Übersicht', 'KVA müssen zuerst und getrennt ausgegeben werden.');
function gfH(string $text): string { return htmlspecialchars($text,ENT_QUOTES,'UTF-8'); }
$html=gfFinancialSectionHtml(['heading'=>'Test','text'=>'Text','tables'=>[['columns'=>['Firma'],'rows'=>[['<script>unsafe</script>']]]]]);
check(str_contains($html,'<table>')&&!str_contains($html,'<script>'), 'Registertabellen müssen sicher gerendert werden.');
echo "GF financial evidence tests passed\n";
