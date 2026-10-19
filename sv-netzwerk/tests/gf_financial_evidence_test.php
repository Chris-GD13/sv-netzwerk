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
try { gfExcelValidateData('zahlungsbefuerwortung', ['entries'=>[$entry]], 'KVA nur auflisten'); throw new LogicException('Fehlende Freigabe wurde akzeptiert.'); }
catch (RuntimeException $e) { check(str_contains($e->getMessage(), 'ausdrücklicher Freigabe'), 'Falsche Sperre.'); }


// Reproduce one invoice and the two estimates that blocked the SE.
$input=['entries'=>[
    ['description'=>'Ortung und Reparatur','source_type'=>'Rechnung','source_reference'=>'R-1','amount'=>100.0],
    ['description'=>'Wiederherstellung Boden','source_type'=>'KVA','source_reference'=>'A-2','amount'=>200.0],
    ['description'=>'Wiederherstellung Wand','source_type'=>'Freigegebener KVA','source_reference'=>'A-3','amount'=>300.0],
], 'totals'=>['self_retention'=>10,'partial_payments'=>50,'direct_payments'=>0,'rest_reserve'=>0]];
$prepared=gfExcelPrepareResult('schlusserklaerung',$input,'SE aktualisieren. KVA noch nicht freigeben.');
check(gfExcelValidateData('schlusserklaerung',$prepared,'KVA noch nicht freigeben.')['passed'],'Belegte KVA müssen die SE als Reserve passieren.');
check($prepared['entries'][0]===$input['entries'][0]&&$prepared['totals']===$input['totals'],'Rechnung, Zahlungen und Summen dürfen nicht verändert werden.');
foreach([1,2]as$i){
    check($prepared['entries'][$i]['source_type']==='KVA','Ohne Freigabe darf die KI keine Freigabe behaupten.');
    check(str_contains($prepared['entries'][$i]['description'],'derzeit als Reserve'),'Reservekennzeichnung fehlt.');
    check($prepared['entries'][$i]['amount']===$input['entries'][$i]['amount'],'Originalbetrag wurde verändert.');
}
check(gfExcelPrepareResult('schlusserklaerung',$prepared,'KVA noch nicht freigeben.')===$prepared,'Wiederaufnahme darf Reservekennzeichnung nicht verdoppeln.');
check(gfExcelPrepareResult('schlusserklaerung',['entries'=>[$entry]],'KVA freigeben.')===['entries'=>[$entry]],'Ausdrückliche KVA-Freigabe muss erhalten bleiben.');
check(gfExcelPrepareResult('zahlungsbefuerwortung',$input,'KVA nur auflisten.')===$input,'SE-Normalisierung darf keine Zahlungsbefürwortung ändern.');
$invoice=['description'=>'Schlussrechnung zum Angebot A-123','source_type'=>'Rechnung','source_reference'=>'R-9','amount'=>123.45];
check(gfExcelPrepareResult('schlusserklaerung',['entries'=>[$invoice]],'SE erstellen.')['entries'][0]===$invoice,'Eine Rechnung mit Angebotsverweis muss Rechnung bleiben.');
check(gfExcelValidateData('zahlungsbefuerwortung',['entries'=>[$invoice]],'Rechnung prüfen.')['passed'],'Angebotsverweis darf eine Rechnung nicht als KVA sperren.');
$mixed=['entries'=>[
    ['description'=>'Boden','source_type'=>'Freigegebener KVA','source_reference'=>'A','amount'=>100.0],
    ['description'=>'Wand','source_type'=>'Freigegebener KVA','source_reference'=>'B','amount'=>200.0],
]];
$mixedInstruction='Angebot A freigeben. Angebot B als Reserve aufnehmen.';
$mixedPrepared=gfExcelPrepareResult('schlusserklaerung',$mixed,$mixedInstruction);
check($mixedPrepared['entries'][0]===$mixed['entries'][0],'Ausdrücklich freigegebenes Angebot A muss erhalten bleiben.');
check($mixedPrepared['entries'][1]['source_type']==='KVA'&&str_contains($mixedPrepared['entries'][1]['description'],'derzeit als Reserve'),'Freigabe A darf Angebot B nicht freigeben.');
check(gfExcelValidateData('schlusserklaerung',$mixedPrepared,$mixedInstruction)['passed'],'Gemischter Freigabe-/Reservestand muss zulässig sein.');
try{gfExcelValidateData('zahlungsbefuerwortung',['entries'=>[$mixed['entries'][1]]],$mixedInstruction);throw new LogicException('Angebot B ohne eigene Freigabe als Zahlung akzeptiert.');}
catch(RuntimeException $error){check(str_contains($error->getMessage(),'ausdrücklicher Freigabe'),'Falsche Sperre bei fremder KVA-Freigabe.');}

foreach(['gfExcelOutput','gfEngelValidate']as$function){
    $a=strpos($core,'function '.$function.'(');$b=strpos($core,"\nfunction ",$a+1);eval(substr($core,$a,$b-$a));
}
$throughValidation=$input;
check(gfEngelValidate('schlusserklaerung',$throughValidation,[],'KVA nicht freigeben.')['passed'],'Aktiver Validierungsweg muss die Reservekorrektur anwenden.');
check($throughValidation===$input,'Die Validierung darf die Originaldaten nicht verändern.');

foreach(['zahlungsbefuerwortung']as$type){
    $bad=$type==='zahlungsbefuerwortung'?$prepared:$input;
    try{gfExcelValidateData($type,$bad,'KVA nicht freigeben.');throw new LogicException('Unzulässige Position wurde akzeptiert.');}
    catch(RuntimeException $error){check(str_contains($error->getMessage(),'Excel-QS-Sperre'),'Falscher Fehler bei unzulässiger Position.');}
}
check(str_contains(gfExcelResponseRule('schlusserklaerung'),'derzeit als Reserve'),'SE-Anweisung muss KVA als Reserve berücksichtigen.');
check(str_contains(gfExcelResponseRule('zahlungsbefuerwortung'),'ausschließlich bei ausdrücklicher Freigabe'),'Zahlungsanweisung muss Freigabe weiterhin verlangen.');

require_once __DIR__.'/../public/intern/api/gf-excel-ooxml.php';
foreach(['gfExcelCell','gfExcelSet','gfExcelHeaders','gfExcelPages','gfExcelBuildOne','gfExcelValidateWorkbook']as$function){
    $a=strpos($core,'function '.$function.'(');$b=strpos($core,"\nfunction ",$a+1);
    eval(substr($core,$a,$b-$a));
}
$templatePath=tempnam(sys_get_temp_dir(),'kva-template-');
$zip=new ZipArchive();$zip->open($templatePath,ZipArchive::OVERWRITE);
$ns='http://schemas.openxmlformats.org/spreadsheetml/2006/main';
foreach(['[Content_Types].xml'=>'<Types/>','xl/workbook.xml'=>'<workbook xmlns="'.$ns.'"><sheets/><extLst/></workbook>','xl/styles.xml'=>'<styleSheet/>','xl/vbaProject.bin'=>'original-macro-bytes','xl/worksheets/sheet1.xml'=>'<worksheet xmlns="'.$ns.'"><sheetData/></worksheet>']as$name=>$bytes)$zip->addFromString($name,$bytes);
$zip->close();$template=file_get_contents($templatePath);unlink($templatePath);
$mixedPages=gfExcelPages('schlusserklaerung',gfExcelEntries($mixedPrepared));
$mixedWorkbook=gfExcelBuildOne($template,'schlusserklaerung',[],$mixedPages[0],$mixedPrepared);
check(gfExcelValidateWorkbook($template,$mixedWorkbook,'schlusserklaerung',$mixedPages[0],$mixedInstruction)['passed'],'Auch die gespeicherte SE muss A als Freigabe und B als Reserve prüfen.');
$mixedPath=tempnam(sys_get_temp_dir(),'kva-mixed-');file_put_contents($mixedPath,$mixedWorkbook);$zip=new ZipArchive();$zip->open($mixedPath);
$zip->addFromString('xl/worksheets/sheet1.xml',str_replace('derzeit als Reserve','',$zip->getFromName('xl/worksheets/sheet1.xml')));$zip->close();$mixedDamaged=file_get_contents($mixedPath);unlink($mixedPath);
try{gfExcelValidateWorkbook($template,$mixedDamaged,'schlusserklaerung',$mixedPages[0],$mixedInstruction);throw new LogicException('Freigabe A verdeckt fehlende Reservekennzeichnung B.');}
catch(RuntimeException $error){check(str_contains($error->getMessage(),'Excel-QS-Sperre'),'Falsche gemischte Formular-QS-Sperre.');}
$pages=gfExcelPages('schlusserklaerung',gfExcelEntries($prepared));
check(count($pages)===1&&$pages[0]['total']===600.0,'KVA müssen genau einmal in die SE-Summe eingehen.');
$workbook=gfExcelBuildOne($template,'schlusserklaerung',[],$pages[0],$prepared);
check(gfExcelValidateWorkbook($template,$workbook,'schlusserklaerung',$pages[0],'KVA nicht freigeben.')['passed'],'Auch die fertige XLSM muss KVA als Reserve akzeptieren.');
$path=tempnam(sys_get_temp_dir(),'kva-output-');file_put_contents($path,$workbook);$zip=new ZipArchive();$zip->open($path);
$sheet=$zip->getFromName('xl/worksheets/sheet1.xml');
check($zip->getFromName('xl/vbaProject.bin')==='original-macro-bytes','Originalmakros verändert.');
$dom=new DOMDocument();$dom->loadXML($sheet);$xp=new DOMXPath($dom);$xp->registerNamespace('x',$ns);
foreach(['N19'=>100,'N20'=>200,'N21'=>300,'N38'=>600,'N40'=>50,'N42'=>540]as$ref=>$amount)check((float)$xp->evaluate('string(//x:c[@r="'.$ref.'"]/x:v)')===(float)$amount,'Falscher Betrag in '.$ref);
check(substr_count($sheet,'derzeit als Reserve')===2,'Beide Reservepositionen müssen im Formular erkennbar sein.');
// A missing marker in the actual saved sheet must still fail the second QS.
$zip->addFromString('xl/worksheets/sheet1.xml',str_replace('derzeit als Reserve','',$sheet));$zip->close();$damaged=file_get_contents($path);unlink($path);
try{gfExcelValidateWorkbook($template,$damaged,'schlusserklaerung',$pages[0],'KVA nicht freigeben.');throw new LogicException('Fehlende Reservekennzeichnung im Formular akzeptiert.');}
catch(RuntimeException $error){check(str_contains($error->getMessage(),'Reservekennzeichnung'),'Falsche Formular-QS-Sperre.');}

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
check($register['compact_register']===true&&$register['open_points']===[]&&$register['sections'][5]['internal_only']===true,'Routinehinweise dürfen nicht nochmals unter den Tabellen erscheinen.');
foreach(['gfSignatureHtml','gfDocumentHtml'] as $function){$htmlStart=strpos($core,'function '.$function.'(');$htmlEnd=strpos($core,"\n",$htmlStart);eval(substr($core,$htmlStart,$htmlEnd-$htmlStart));}
check(str_contains(gfSignatureHtml('Christian Wächter'),'cw@sv-netzwerk.eu')&&str_contains(gfSignatureHtml('Christian Wächter'),'Regulierer und Bausachverständiger'),'Verifizierter Absender fehlt.');
check(!str_contains(gfSignatureHtml('Holger Roth'),'Christian'),'Signatur darf kein fremdes Bearbeiterprofil einsetzen.');
$registerHtml=gfDocumentHtml('Register',[], $register,['Brief.pdf'],['Master.md'],'Test');
check(str_contains($registerHtml,'KVA – getrennte Übersicht')&&str_contains($registerHtml,'11.745,40'),'Tabellen und Berechnung müssen erhalten bleiben.');
foreach(['Zahlungsempfehlung / weiterer Klärungsbedarf','Offene Punkte','Angewendete Regelwerke','Ausgewertete Unterlagen'] as $noise)check(!str_contains($registerHtml,$noise),'Unnötiger Registeranhang: '.$noise);
$foreignDocuments=$documents;
$foreignDocuments[4]['issuer']='Andere Firma';
$foreignRegister=gfFinancialRegisterGenerate([['files'=>[['name'=>'Brief.pdf','financial_documents'=>$foreignDocuments]]]],'', $context);
check(!str_contains(json_encode($foreignRegister,JSON_UNESCAPED_UNICODE),'341,46'),'KVA einer anderen Firma dürfen nicht zur Rechnung zugeordnet werden.');
