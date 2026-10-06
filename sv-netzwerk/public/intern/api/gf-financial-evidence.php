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
    foreach (array_chunk($pending, 2) as $index => $chunk) {
        gfJobUpdate($jobId, 'running', 27, 'Originalbelege werden inhaltlich geprüft, einschließlich Sammelakten und Scans · Gruppe '.($index + 1).' von '.(int)ceil(count($pending) / 2).'.');
        $content = [['type' => 'input_text', 'text' => gfFinancialEvidencePrompt()]];
        $sources = [];
        foreach ($chunk as $file) {
            $ref = gfOpenAIUploadDriveFile($file, 'case');
            if (!$ref) throw new RuntimeException('Originalbeleg konnte nicht gelesen werden: '.(string)($file['name'] ?? 'Unterlage'));
            $file['_evidence_name'] = gfOpenAIUploadName((string)($ref['name'] ?? ''), (string)($ref['mime'] ?? ''));
            $sources[] = $file;
            $content[] = gfCalculationInputPart($ref);
        }
        $extracted = gfOpenAI($content, 'Du prüfst deutsche Schadenakten vollständig auf Finanzbelege. Klassifiziere nach Originalinhalt, lies alle Seiten, trenne eingebettete Belege und liefere ausschließlich quellengetreues JSON.', 16000);
        $split = gfEvidenceSplitBySource($extracted, $sources);
        if (count($split) !== count($sources) || !gfFinancialEvidenceUsable($extracted)) {
            throw new RuntimeException('Originalbelegprüfung unvollständig: Nicht alle Dateien wurden mit einer Finanzbelegliste ausgewertet.');
        }
        foreach ($sources as $file) {
            $single = $split[gfEvidenceFileCacheKey($file)];
            gfSettingSet(gfFinancialEvidenceCacheKey($file), json_encode($single, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $evidence[] = $single;
        }
    }
    return $evidence;
}
