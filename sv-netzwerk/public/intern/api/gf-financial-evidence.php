<?php
declare(strict_types=1);

/** Financial extraction has its own cache: old general summaries are insufficient. */
function gfFinancialEvidenceCacheKey(array $file): string
{
    return 'gf_financial_v2_'.hash('sha256', json_encode(gfEvidenceFileSignature($file), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function gfFinancialEvidenceUsable(mixed $evidence): bool
{
    if (!gfEvidenceIsUsable($evidence)) return false;
    foreach ($evidence['files'] as $file) {
        if (!is_array($file) || !is_array($file['financial_documents'] ?? null)) return false;
    }
    return true;
}

function gfFinancialEvidencePrompt(): string
{
    return <<<'PROMPT'
Lies alle Seiten jeder Originaldatei einschließlich gescannter Seiten vollständig. Ermittle die Dokumentart aus dem Inhalt, niemals allein aus Dateiname oder Ablageordner. Insbesondere Sparkassen-Dateien namens Brief, Schreiben, Anlage oder Dokument können eine vollständige Sammelakte mit mehreren eigenständigen Rechnungen, Angeboten, KV/KVA, Kostenschätzungen, Nachträgen, Zahlungs- und Freigabebelegen enthalten. Ein Anschreiben am Anfang ist keine Klassifikation für die nachfolgenden Seiten. OCR beziehungsweise visuelle Auswertung aller PDF-Seiten ist zwingend; ein leerer Textlayer bedeutet nicht, dass keine Belege vorhanden sind.

Antworte ausschließlich als JSON: {"files":[{"name":"exakter bereitgestellter Dateiname","financial_documents":[{"document_type":"Rechnung|KVA|Angebot|Nachtrag|Zahlungsbeleg|Freigabe|Abgeltung","issuer":"Aussteller","number":"Belegnummer","date":"Originaldatum","pages":"Seitenbereich im Original","description":"Hauptleistungen","net":null,"tax":null,"gross":null,"line_items":[{"description":"Leistung","quantity":null,"unit":"","unit_price":null,"net":null,"gross":null,"conditional":false}],"payment_status":"nur nachgewiesener Zahlungsstand","approval_status":"nur nachgewiesene Freigabe mit Quelle","duplicate_of":"Quelle einer identischen Kopie oder leer","open_points":[]}],"open_points":[]}]}.

Jede Originaldatei muss genau einmal im files-Array vorkommen, auch wenn sie keine Finanzbelege enthält (dann financial_documents=[]). Innerhalb einer Sammeldatei jeden eigenständigen Beleg mit Aussteller, Nummer, Datum, Originalseiten, Hauptleistungsgruppen, Mengen, Einzelpreisen, Netto, Umsatzsteuer und Brutto erfassen. Mehrseitige Belege zusammenhalten; identische Kopien kennzeichnen und nicht mehrfach addieren. Ursprüngliches Angebot, Rechnung und Nachtrag unterscheiden; ihre Beträge nicht ungeprüft kumulieren. Bedarfspositionen, Optionen, Summenüberträge, bereits geleistete Zahlungen und Stornierungen ausdrücklich kennzeichnen. Erwähnte Beträge aus Anschreiben oder Notizen nicht als zusätzliche Rechnung behandeln. Reserve und Selbstbehalt sind keine Rechnungspositionen. Der Nutzerauftrag ist keine Quelle für einen bereits erfolgten Zahlungsvorgang. Fehlende oder unlesbare Zahlen als null und offene Punkte ausgeben; keine Beträge oder Freigaben erfinden. Erfasse die belegten Zahlen auch ohne Freigabe: Die fachliche Aufnahme in Schlusserklärung oder Zahlungsbefürwortung erfolgt erst im nächsten Schritt anhand des konkreten Arbeitsauftrags und der QS.
PROMPT;
}

function gfFinancialExtractCase(int $jobId, array $caseFiles): array
{
    $evidence = [];
    $pending = [];
    foreach ($caseFiles as $file) {
        $cached = json_decode(gfSettingGet(gfFinancialEvidenceCacheKey($file), '{}'), true);
        if (gfFinancialEvidenceUsable($cached)) $evidence[] = $cached;
        else $pending[] = $file;
    }
    // One original per request prevents embedded invoice titles from being
    // confused with separate uploaded files in large insurer bundles.
    foreach (array_chunk($pending, 1) as $index => $chunk) {
        gfJobUpdate($jobId, 'running', 27, 'Originalbelege werden inhaltlich geprüft, einschließlich Sammelakten und Scans · Datei '.($index + 1).' von '.count($pending).' · '.(string)($chunk[0]['name'] ?? 'Unterlage').'.');
        $content = [['type' => 'input_text', 'text' => gfFinancialEvidencePrompt()]];
        $sources = [];
        foreach ($chunk as $file) {
            $ref = gfOpenAIUploadDriveFile($file, 'case');
            if (!$ref) throw new RuntimeException('Originalbeleg konnte nicht gelesen werden: '.(string)($file['name'] ?? 'Unterlage'));
            $file['_evidence_name'] = gfOpenAIUploadName((string)($ref['name'] ?? ''), (string)($ref['mime'] ?? ''));
            $sources[] = $file;
            $content[] = gfCalculationInputPart($ref);
        }
        $content[0]['text'].="\n\nVERBINDLICHE ORIGINALDATEILISTE: ".json_encode(array_column($sources, '_evidence_name'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).". Genau eine Originaldatei wurde beigefügt. Alle enthaltenen Einzelbelege gehören in deren financial_documents-Array, nicht als weitere Dateien in files. Gib für die Originaldatei den oben vorgegebenen Namen aus.";
        $extracted = gfFinancialReadOriginal($chunk[0], $content, $jobId);
        // The source identity is unambiguous for a single-file request even if
        // the model uses the printed heading instead of the upload filename.
        if (gfFinancialEvidenceUsable($extracted) && count($extracted['files']) === 1) {
            $extracted['files'][0]['name'] = $sources[0]['_evidence_name'];
        }
        $split = gfEvidenceSplitBySource($extracted, $sources);
        if (count($split) !== count($sources) || !gfFinancialEvidenceUsable($extracted)) {
            throw new RuntimeException('Originalbelegprüfung unvollständig für '.(string)($chunk[0]['name'] ?? 'Unterlage').': Erwartet ist eine Originaldatei mit financial_documents; erhaltene Dateien: '.count(is_array($extracted['files'] ?? null) ? $extracted['files'] : []).'.');
        }
        foreach ($sources as $file) {
            $single = $split[gfEvidenceFileCacheKey($file)];
            gfSettingSet(gfFinancialEvidenceCacheKey($file), json_encode($single, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $evidence[] = $single;
        }
    }
    return $evidence;
}

function gfFinancialCommand(array $command): string
{
    $process = proc_open($command, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('PDF-Seitenprüfung konnte nicht gestartet werden.');
    fclose($pipes[0]);
    $output=stream_get_contents($pipes[1]); fclose($pipes[1]);
    $error=stream_get_contents($pipes[2]); fclose($pipes[2]);
    $status=proc_close($process);
    if ($status!==0) throw new RuntimeException('PDF-Seitenprüfung fehlgeschlagen: '.substr(trim($error),0,300));
    return trim($output);
}

function gfFinancialPageRanges(int $pages): array
{
    $ranges=[];
    for ($start=1; $start<=$pages; $start+=10) {
        $end=min($pages,$start+11);
        $ranges[]=[$start,$end];
        if ($end===$pages) break;
    }
    return $ranges;
}

function gfFinancialUploadPart(string $path, string $name): string
{
    $ch=curl_init('https://api.openai.com/v1/files');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Authorization: Bearer '.trim(env('OPENAI_API_KEY',''))],CURLOPT_POSTFIELDS=>['purpose'=>'user_data','file'=>new CURLFile($path,'application/pdf',$name)],CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>180]);
    $response=curl_exec($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    $id=(string)(json_decode((string)$response,true)['id']??'');
    if ($status<200||$status>=300||$id==='') throw new RuntimeException('PDF-Seitenblock konnte nicht zur Originalprüfung bereitgestellt werden.');
    return $id;
}

function gfFinancialTempPdf(string $prefix): string
{
    $base=tempnam(sys_get_temp_dir(),$prefix);
    if ($base===false) throw new RuntimeException('PDF-Prüfdatei konnte nicht angelegt werden.');
    $path=$base.'.pdf';
    if (!rename($base,$path)) { @unlink($base); throw new RuntimeException('PDF-Prüfdatei konnte nicht benannt werden.'); }
    return $path;
}

function gfFinancialReadOriginal(array $file, array $content, int $jobId): array
{
    $system='Du prüfst deutsche Schadenakten vollständig auf Finanzbelege. Klassifiziere nach Originalinhalt, lies alle Seiten, trenne eingebettete Belege und liefere ausschließlich quellengetreues JSON.';
    if (($file['mimeType']??'')!=='application/pdf') return gfOpenAI($content,$system,16000);
    if (!is_executable('/usr/bin/qpdf')) throw new RuntimeException('PDF-Seitenprüfung ist auf dem Portalserver nicht verfügbar.');
    $download=gfDriveDownload($file);
    if (!$download) throw new RuntimeException('PDF-Original konnte nicht gelesen werden.');
    $source=gfFinancialTempPdf('gf-original-');
    $parts=[];
    try {
        if (file_put_contents($source,$download['bytes'])===false) throw new RuntimeException('PDF-Prüfdatei konnte nicht geschrieben werden.');
        $pages=(int)gfFinancialCommand(['/usr/bin/qpdf','--show-npages',$source]);
        if ($pages<1) throw new RuntimeException('Seitenzahl des PDF-Originals fehlt.');
        if ($pages<=12) return gfOpenAI($content,$system,16000);
        $documents=[]; $points=[]; $ranges=gfFinancialPageRanges($pages);
        foreach ($ranges as $index=>[$start,$end]) {
            gfJobUpdate($jobId,'running',27,'Sammelakte wird seitenweise geprüft · Originalseiten '.$start.'–'.$end.' von '.$pages.' · Block '.($index+1).' von '.count($ranges).'.');
            $part=gfFinancialTempPdf('gf-pages-');
            $parts[]=$part;
            gfFinancialCommand(['/usr/bin/qpdf',$source,'--pages','.',$start.'-'.$end,'--',$part]);
            $partId=gfFinancialUploadPart($part,'Originalseiten-'.$start.'-'.$end.'.pdf');
            $prompt=$content[0]['text']."\nDieser PDF-Block enthält genau die Originalseiten $start bis $end von insgesamt $pages Seiten. PDF-Seite 1 entspricht Originalseite $start. Verwende ausschließlich diese globalen Originalseitenzahlen. Lies jede Seite visuell. Am Blockrand angeschnittene Belege mit den tatsächlich sichtbaren Angaben erfassen; fehlende Angaben als null. Überschneidungen werden nachher zusammengeführt.";
            $block=gfOpenAI([['type'=>'input_text','text'=>$prompt],['type'=>'input_file','file_id'=>$partId]],$system,16000);
            if (!gfFinancialEvidenceUsable($block)||count($block['files'])!==1) throw new RuntimeException('PDF-Seitenblock '.$start.'–'.$end.' wurde nicht vollständig geprüft.');
            foreach ($block['files'][0]['financial_documents'] as $document) {
                $document['original_page_block']=$start.'–'.$end;
                $documents[]=$document;
            }
            foreach (($block['files'][0]['open_points']??[]) as $point) $points[]=$point;
        }
        return ['files'=>[['name'=>$file['name'],'financial_documents'=>$documents,'open_points'=>$points,'original_pages_read'=>$pages,'page_blocks'=>$ranges]]];
    } finally {
        @unlink($source);
        foreach ($parts as $part) @unlink($part);
    }
}

/** Repair presentation only, preserving the extracted original evidence. */
function gfFinancialRegisterStructure(array $result, array $content, string $system): array
{
    $headings = gfHeadings('rechnungsregister');
    if (count(is_array($result['sections'] ?? null) ? $result['sections'] : []) === count($headings) && gfFinancialRegisterComplete($result,$content)) return $result;
    $content[] = ['type'=>'input_text', 'text'=>
        'Korrigiere ausschließlich die Gliederung des folgenden Rechnungsregisters. Gib genau '.count($headings).
        ' sections in dieser Reihenfolge aus: '.json_encode($headings, JSON_UNESCAPED_UNICODE).
        '. Jeder Abschnitt hat heading und text. Keine zusätzlichen sections. Alle belegten Rechnungen, Angebote, Nachträge, Quellen und offenen Punkte vollständig erhalten und in die passenden Abschnitte einordnen. Jede Originalbelegnummer aus financial_documents muss vorkommen. Finanzdaten ausschließlich aus financial_documents verwenden, nicht aus früheren allgemeinen Kurzfassungen. Überlappende Seitenblöcke und identische Kopien anhand Aussteller, Nummer und Dokumentart zusammenführen. Angebote niemals mit späteren Rechnungen ungeprüft summieren. Fehlender Zahlungsnachweis bedeutet Zahlungsstand unbekannt, nicht unbezahlt. Keine neue Freigabe oder Zahlung ableiten. Antworte ausschließlich im bereits verlangten JSON-Format. Entwurf: '.json_encode($result, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)];
    $repaired=gfOpenAI($content, $system, 16000);
    if (!gfFinancialRegisterComplete($repaired,$content)) throw new RuntimeException('Rechnungsregister unvollständig: Nicht alle erkannten Originalbelegnummern wurden übernommen.');
    return $repaired;
}

function gfFinancialRegisterComplete(array $result, array $content): bool
{
    $text=json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    foreach ($content as $item) {
        if (($item['type']??'')!=='input_text') continue;
        $raw=(string)($item['text']??''); $offset=strpos($raw,'{');
        if ($offset===false) continue;
        $evidence=json_decode(substr($raw,$offset),true);
        foreach (($evidence['files']??[]) as $file) foreach (($file['financial_documents']??[]) as $document) {
            $number=trim((string)($document['number']??''));
            if ($number!=='' && !str_contains((string)$text,$number)) return false;
        }
    }
    return true;
}
