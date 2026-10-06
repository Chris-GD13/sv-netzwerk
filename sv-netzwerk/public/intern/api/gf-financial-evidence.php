<?php
declare(strict_types=1);

/** Financial extraction has its own cache: old general summaries are insufficient. */
function gfFinancialEvidenceCacheKey(array $file): string
{
    return 'gf_financial_v1_'.hash('sha256', json_encode(gfEvidenceFileSignature($file), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
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
        $extracted = gfOpenAI($content, 'Du prüfst deutsche Schadenakten vollständig auf Finanzbelege. Klassifiziere nach Originalinhalt, lies alle Seiten, trenne eingebettete Belege und liefere ausschließlich quellengetreues JSON.', 16000);
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

/** Repair presentation only, preserving the extracted original evidence. */
function gfFinancialRegisterStructure(array $result, array $content, string $system): array
{
    $headings = gfHeadings('rechnungsregister');
    if (count(is_array($result['sections'] ?? null) ? $result['sections'] : []) === count($headings)) return $result;
    $content[] = ['type'=>'input_text', 'text'=>
        'Korrigiere ausschließlich die Gliederung des folgenden Rechnungsregisters. Gib genau '.count($headings).
        ' sections in dieser Reihenfolge aus: '.json_encode($headings, JSON_UNESCAPED_UNICODE).
        '. Jeder Abschnitt hat heading und text. Keine zusätzlichen sections. Alle belegten Rechnungen, Angebote, Nachträge, Quellen und offenen Punkte vollständig erhalten und in die passenden Abschnitte einordnen. Keine neue Freigabe oder Zahlung ableiten. Antworte ausschließlich im bereits verlangten JSON-Format. Entwurf: '.json_encode($result, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)];
    return gfOpenAI($content, $system, 16000);
}
