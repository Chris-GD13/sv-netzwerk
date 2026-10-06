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

/** A register is an inventory, so render the extracted records without resummarizing. */
function gfFinancialRegisterGenerate(array $evidence, string $instructions='', ?array $context=null): array
{
    $groups=[]; $notes=[]; $duplicates=[];
    foreach ($evidence as $bundle) foreach (($bundle['files']??[]) as $file) {
        if (!isset($file['financial_documents'])) continue;
        foreach ($file['financial_documents'] as $document) {
            $type=trim((string)($document['document_type']??'Finanzbeleg'));
            $number=trim((string)($document['number']??''));
            $issuer=trim((string)($document['issuer']??''));
            $issuerWords=preg_split('/\s+/u',mb_strtolower($issuer,'UTF-8'))?:[];
            $key=$number!==''?$type.'|'.($issuerWords[0]??'').'|'.$number:hash('sha256',json_encode($document));
            $source=(string)($file['name']??'Original').' · Originalseiten '.(string)($document['original_page_block']??$document['pages']??'offen');
            if (in_array($type,['Rechnung','KVA','Angebot','Nachtrag'],true) && $number==='' && (!is_numeric($document['net']??null) || empty($document['line_items']))) {
                $notes[]='Bezugnahme bzw. Begleitunterlage, kein zusätzlich anzusetzender eigenständiger Finanzbeleg: '.$issuer.' · '.(string)($document['description']??'').' · '.$source;
                continue;
            }
            $document['_source']=$source;
            if (isset($groups[$key])) {
                $duplicates[]=$issuer.' · '.$number.' · '.$source;
                foreach (['net','tax','gross','date','description','payment_status','approval_status'] as $field) {
                    if (empty($groups[$key][$field]) && !empty($document[$field])) $groups[$key][$field]=$document[$field];
                    elseif (in_array($field,['net','tax','gross'],true) && is_numeric($document[$field]??null) && is_numeric($groups[$key][$field]??null) && abs((float)$document[$field]-(float)$groups[$key][$field])>0.01) $notes[]='Abweichende Originalbeträge bei '.$issuer.' · '.$number.' · '.$field.'. Einzelprüfung erforderlich.';
                }
                $groups[$key]['_source'].='; '.$source;
                continue;
            }
            $groups[$key]=$document;
        }
    }
    if (!$groups) throw new RuntimeException('Keine Finanzbelege aus Originalunterlagen für das Register erkannt.');
    $invoices=[]; $offers=[]; $payments=[]; $overview=[]; $invoiceSum=0.0; $count=0;
    $money=static fn($value)=>is_numeric($value)?number_format((float)$value,2,',','.').' EUR':'nicht eindeutig ablesbar';
    foreach ($groups as $document) {
        $type=(string)($document['document_type']??'Finanzbeleg');
        $line=$type.' · '.(string)($document['issuer']??'Aussteller offen').' · Belegnummer '.((string)($document['number']??'')?:'offen').' · Datum '.((string)($document['date']??'')?:'offen').' · '.$document['_source']."\n".(string)($document['description']??'')."\nNetto: ".$money($document['net']??null).' · Umsatzsteuer: '.$money($document['tax']??null).' · Brutto: '.$money($document['gross']??null).'.';
        foreach (($document['line_items']??[]) as $item) if (!empty($item['conditional'])) $line.="\nBedarfsposition: ".(string)($item['description']??'').' · Netto: '.$money($item['net']??null).' · Brutto: '.$money($item['gross']??null).'. Nicht zusätzlich zur Belegsumme addiert.';
        $status=trim((string)($document['payment_status']??''));
        $line.="\nZahlungsstand laut Original: ".($status!==''?$status:'kein eindeutiger Zahlungsnachweis').'.';
        if (!empty($document['approval_status'])) $line.="\nFreigabestand laut Original: ".$document['approval_status'].'.';
        foreach (($document['open_points']??[]) as $point) $notes[]=(string)$point;
        if ($type==='Rechnung') {
            $invoices[]=$line;
            if (is_numeric($document['gross']??null)) {$invoiceSum+=(float)$document['gross']; $count++;}
            $payments[]=(string)($document['issuer']??'').' · '.(string)($document['number']??'').' · '.$money($document['gross']??null).' · '.($status!==''?$status:'Zahlungsnachweis offen').'.';
        } elseif (in_array($type,['KVA','Angebot','Nachtrag'],true)) $offers[]=$line;
        else $payments[]=$line;
        $overview[]=$line;
    }
    $texts=[
        implode("\n\n",$invoices)."\n\nSumme der ".$count.' eindeutigen Rechnungsbelege mit ablesbarem Bruttobetrag: '.$money($invoiceSum).'. Angebote und Nachträge sind darin nicht enthalten.',
        $offers?implode("\n\n",$offers):'Keine gesonderten Angebote oder Nachträge in den ausgewerteten Originalen erkannt.',
        $duplicates?'Mehrfach erfasste Belege bzw. überlappende Seiten wurden anhand Aussteller, Belegnummer und Dokumentart zusammengeführt und nicht erneut summiert:' ."\n".implode("\n",array_unique($duplicates)):'Keine mehrfach erfassten Belege mit identischer Aussteller-/Belegnummernzuordnung erkannt.',
        'Freigabe und Zahlung sind getrennte Vorgänge. Fehlender Zahlungsnachweis bedeutet einen offenen Zahlungsstand.' ."\n\n".implode("\n\n",$payments),
        implode("\n\n",$overview),
        'Dieses Register erfasst Originalbelege. Es veranlasst keine neue Freigabe oder Zahlung. Rechnungen, Angebote und Nachträge dürfen nicht ungeprüft zu einem Entschädigungsbetrag addiert werden.'.($notes?"\n\nOffene Punkte:\n".implode("\n",array_unique($notes)):''),
    ];
    [$texts[0],$texts[1]]=[$texts[1],$texts[0]];
    $sections=[];
    foreach (gfHeadings('rechnungsregister') as $index=>$heading) $sections[]=['heading'=>$heading,'text'=>$texts[$index]];
    $context??=gfFinancialDecisionContext(array_values($groups),$instructions);
    $tables=gfFinancialDecisionTables(array_values($groups),$context);
    $sections[0]['tables']=[$tables['offers']];
    $sections[1]['tables']=[$tables['invoices']];
    $sections[4]['tables']=[$tables['calculation']];
    $sections[4]['text']='Bedingte Berechnung bei vollständiger sachlicher und preislicher Anerkennung der offenen Originalbelege. Bereits abgerechnete KVA werden nicht nochmals angesetzt.'.$tables['note'];
    $critical=array_values(array_unique(array_filter($notes,static fn($note)=>str_starts_with($note,'Abweichende Originalbeträge'))));
    $sections[5]['text']=$critical?implode("\n",$critical):'Die erforderlichen Entscheidungen sind in den Tabellen ausgewiesen.';
    $sections[5]['internal_only']=$critical===[];
    return ['summary'=>'Inhaltliches Belegregister aus den Originalunterlagen, einschließlich gescannter Sammelakten und der jeweiligen Originalseiten.','sections'=>$sections,'open_points'=>[],'audit_open_points'=>array_values(array_unique($notes)),'compact_register'=>true];
}

function gfFinancialDecisionContext(array $documents, string $instructions): array
{
    if ($instructions==='') return [];
    $prompt='Liefere ausschließlich JSON {"offer_invoice_links":[{"offer_numbers":["..."],"invoice_number":"...","source":"konkreter Nachweis"}],"paid_invoice_numbers":[],"drying_kwh":null,"electricity_rate":null}. Ermittle nur ausdrücklich belegte Zuordnungen: Ein ursprünglicher KVA wird durch eine spätere Rechnung abgerechnet. Nicht allein wegen gleicher Firma verbinden. Zahlung nur bei tatsächlich dokumentiertem Zahlungsvermerk oder ausdrücklich mitgeteilter bereits erfolgter Zahlung; Freigabe ist keine Zahlung. Trocknungsstrom nur mit ausdrücklich nachgewiesenen kWh und angegebenem Tarif. Nutzerangaben dürfen als mitgeteilter Sachverhalt übernommen werden, aber niemals als neuer Zahlungsauftrag. Keine Belege oder Beträge erfinden. '.file_get_contents(__DIR__.'/gf-financial-standard.md')."\nArbeitsauftrag:\n".$instructions."\nOriginalbelege:\n".json_encode($documents,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    return gfOpenAI([['type'=>'input_text','text'=>$prompt]],'Du ordnest bereits vorliegende Finanzbelege zu; du entscheidest keine neue Freigabe oder Zahlung.',5000);
}

function gfFinancialDecisionTables(array $documents, array $context): array
{
    $money=static fn(float $n)=>number_format($n,2,',','.').' €';
    $invoiceByNumber=[]; $offerByNumber=[];
    foreach ($documents as $d) {
        $number=(string)($d['number']??'');
        if (($d['document_type']??'')==='Rechnung') $invoiceByNumber[$number]=$d;
        elseif (in_array($d['document_type']??'', ['KVA','Angebot','Nachtrag'],true)) $offerByNumber[$number]=$d;
    }
    $covered=[]; $invoiceOffers=[];
    foreach (($context['offer_invoice_links']??[]) as $link) {
        $invoice=(string)($link['invoice_number']??'');
        if (!isset($invoiceByNumber[$invoice])||trim((string)($link['source']??''))==='') continue;
        foreach (($link['offer_numbers']??[]) as $offer) if (isset($offerByNumber[(string)$offer])) {
            $issuerKey=static fn(string $issuer)=>preg_replace('/[^\p{L}\p{N}]+/u','',mb_strtolower($issuer));
            $offerIssuer=$issuerKey((string)($offerByNumber[(string)$offer]['issuer']??''));
            $invoiceIssuer=$issuerKey((string)($invoiceByNumber[$invoice]['issuer']??''));
            if ($offerIssuer===''||$offerIssuer!==$invoiceIssuer||isset($covered[(string)$offer])) continue;
            $covered[(string)$offer]=$invoice;
            $invoiceOffers[$invoice][]=(string)$offer;
        }
    }
    $invoiceRows=[]; $paidSum=0.0; $invoiceSum=0.0; $openSum=0.0;
    foreach ($invoiceByNumber as $number=>$d) {
        $gross=is_numeric($d['gross']??null)?(float)$d['gross']:null;
        $status=(string)($d['payment_status']??'');
        $paid=(preg_match('/\b(?:gezahlt|bezahlt|paid|ausgekehrt)\b/ui',$status)===1&&!preg_match('/\b(?:nicht|kein|keine|unbezahlt)\b/ui',$status))||in_array((string)$number,array_map('strval',$context['paid_invoice_numbers']??[]),true);
        $decision=$paid?'Keine erneute Freigabe; als Vorzahlung abziehen.':'Rechnung prüfen und gegebenenfalls freigeben; Zahlungsstand nicht nachgewiesen.';
        if (!empty($invoiceOffers[$number]) && $gross!==null) {
            $prior=0.0;
            foreach (array_unique($invoiceOffers[$number]) as $offer) $prior+=(float)($offerByNumber[$offer]['gross']??0);
            $decision='Zugehörige KVA: '.implode(', ',array_unique($invoiceOffers[$number])).' · '.$money($prior).'. Rechnung abschließend anerkennen; Differenz von '.$money($gross-$prior).' gesondert beurteilen. KVA nicht zusätzlich summieren.';
        }
        $invoiceRows[]=[(string)($d['issuer']??'').' · '.$number.' · '.(string)($d['description']??''),$gross!==null?$money($gross):'Betrag offen',$paid?'Als bezahlt dokumentiert':($status?:'Keine Freigabe oder Zahlung dokumentiert'),$decision];
        if ($gross!==null) {$invoiceSum+=$gross; if($paid)$paidSum+=$gross; else $openSum+=$gross;}
    }
    $invoiceRows[]=['Summe bereits regulierter Rechnungen',$money($paidSum),'Bereits ausgekehrt','In der Schlusserklärung als Vorzahlung abziehen.'];
    $invoiceRows[]=['Summe ohne dokumentierten Zahlungsnachweis',$money($openSum),'Zahlungsabgleich erforderlich','Bei vollständiger Anerkennung und bestätigtem offenem Zahlungsstand auszukehren.'];
    $offerRows=[]; $additional=0.0; $coveredSum=0.0; $unresolved=false;
    foreach ($offerByNumber as $number=>$d) {
        $gross=is_numeric($d['gross']??null)?(float)$d['gross']:null;
        $approved=preg_match('/\b(?:freigegeben|genehmigt|freigabe)\b/ui',(string)($d['approval_status']??''))===1&&!preg_match('/\b(?:nicht|keine|kein|offen)\b/ui',(string)($d['approval_status']??''));
        if(isset($covered[$number])) {$status='Bereits durch Rechnung '.$covered[$number].' abgerechnet; nicht zusätzlich summieren.'; if($gross!==null)$coveredSum+=$gross;}
        else {$status=($approved?'Bereits freigegeben; Abrechnung gesondert prüfen.':'Noch freizugeben, sofern sachlich und preislich anerkannt.'); if($gross!==null)$additional+=$gross; if($approved)$unresolved=true;}
        $offerRows[]=[(string)($d['issuer']??'').' · '.$number.' · '.(string)($d['date']??''),(string)($d['description']??''),$gross!==null?$money($gross):'Betrag offen',$status];
    }
    if ($coveredSum>0) $offerRows[]=['Summe bereits abgerechneter KVA','',$money($coveredSum),'Durch Reparaturrechnung abgerechnet; nicht zusätzlich summieren.'];
    $rows=[['Sämtliche Rechnungen',$money($invoiceSum)],['Zusätzlich noch angebotene, nicht durch Rechnungen abgerechnete Arbeiten',$money($additional)]];
    $note='';
    if (!$unresolved) {
        $total=$invoiceSum+$additional;
        $rows[]=['Gesamtbetrag bei vollständiger Anerkennung',$money($total)];
        $rows[]=['Abzüglich bereits ausgekehrter Entschädigung','−'.$money($paidSum)];
        $rows[]=['Verbleibender Betrag bei vollständiger Anerkennung',$money($total-$paidSum)];
        $note=' Davon entfallen '.$money($openSum).' auf Rechnungen ohne dokumentierten Zahlungsnachweis und '.$money($additional).' auf noch angebotene Arbeiten. Eine KVA-Freigabe allein ist keine Zahlungsbefürwortung.';
        if(is_numeric($context['drying_kwh']??null)&&is_numeric($context['electricity_rate']??null)&&$context['drying_kwh']>0&&$context['electricity_rate']>0) {
            $electricity=round((float)$context['drying_kwh']*(float)$context['electricity_rate'],2);
            $rows[]=['Trocknungsstrom: '.$context['drying_kwh'].' kWh × '.$money((float)$context['electricity_rate']).'/kWh; zusätzlich nur bei Anerkennung',$money($electricity)];
            $rows[]=['Gesamtbetrag einschließlich Trocknungsstrom bei Anerkennung',$money($total+$electricity)];
            $rows[]=['Verbleibender Betrag einschließlich Trocknungsstrom bei Anerkennung',$money($total+$electricity-$paidSum)];
        }
    } else $note=' Ein Gesamtbetrag wird noch nicht ausgewiesen: Bei freigegebenen KVA ist die Zuordnung zu späteren Rechnungen offen.';
    return ['invoices'=>['caption'=>'Rechnungen – getrennte Übersicht','columns'=>['Firma / Rechnung','Betrag brutto','Bisheriger Status','Noch erforderliche Entscheidung'],'rows'=>$invoiceRows], 'offers'=>['caption'=>'KVA – getrennte Übersicht','columns'=>['Firma / KVA','Leistung','Betrag brutto','Ansatz in der Schlusserklärung'],'rows'=>$offerRows], 'calculation'=>['caption'=>'Berechnung ohne Doppelzählung','columns'=>['Berechnung bei vollständiger Anerkennung','Betrag brutto'],'rows'=>$rows], 'note'=>$note];
}

function gfFinancialSectionHtml(array $section): string
{
    $html='<h2>'.gfH((string)($section['heading']??'')).'</h2>';
    foreach (($section['tables']??[]) as $table) {
        $html.='<table><caption>'.gfH((string)($table['caption']??'')).'</caption><thead><tr>';
        foreach (($table['columns']??[]) as $column) $html.='<th>'.gfH((string)$column).'</th>';
        $html.='</tr></thead><tbody>';
        foreach (($table['rows']??[]) as $row) {$html.='<tr>'; foreach($row as $cell)$html.='<td>'.gfH((string)$cell).'</td>'; $html.='</tr>';}
        $html.='</tbody></table>';
    }
    return $html.'<p>'.nl2br(gfH((string)($section['text']??''))).'</p>';
}
