<?php
declare(strict_types=1);

// Internal evidence notes remain in the review record, never in correspondence.
function reviewCorrespondenceProblem(string $text): bool {
    return preg_match('/\b(?:KI|ChatGPT|OpenAI|Sprachmodell|Prüfvorschlag|Belegsatz)\b|künstlich(?:e|er|en)?\s+Intelligenz|(?:automatisiert|automatisch|modellgestützt|maschinell)(?:e|er|en)?\s+(?:Prüfung|Freigabe|Bewertung)|mitgeteilt(?:e|en|er)?\s+Angabe|nicht\s+(?:als\s+)?unabhängig\s+(?:nachgewiesen|bestätigt|verifiziert)|offene\s+Punkte\s*:|interne\s+(?:Prüfhinweise|Auswertung|Quellenpriorität)/iu', $text) === 1;
}

function reviewAssertCorrespondence(string $text): void {
    if (!mb_check_encoding($text, 'UTF-8') || str_contains($text, "\u{FFFD}")) {
        throw new RuntimeException('Der Mailtext enthält beschädigte Zeichen. Bitte die betroffenen Wörter vor dem Versand korrigieren.');
    }
    if (reviewCorrespondenceProblem($text)) {
        throw new RuntimeException('Bitte die Stellungnahme für die Mail fachlich formulieren. Interne Prüf- und Quellenhinweise sowie Angaben zur automatisierten Verarbeitung dürfen nicht in die Korrespondenz übernommen werden. Konkrete Vorbehalte oder benötigte Unterlagen bitte direkt benennen.');
    }
}

function reviewSignature(string $name): string {
    if ($name === 'Christian Wächter') return $name."\nRegulierer und Bausachverständiger\nSV-Netzwerk\nDIN EN ISO/IEC 17024 zertifiziert\ncw@sv-netzwerk.eu\nhttps://www.sv-netzwerk.eu/";
    return $name."\nSV-Büro Marc Schütt e.K.";
}

function reviewMailSubject(string $text, string $caseNo): string {
    $text=trim($text);$caseNo=trim($caseNo);
    if ($caseNo==='' || $text==='' || mb_strlen($text)>500 || preg_match('/[\r\n]/',$text)) throw new RuntimeException('Bitte einen gültigen Betreff mit der Schadennummer eingeben (maximal 500 Zeichen).');
    if (!preg_match('/(?<![\p{L}\p{N}-])'.preg_quote($caseNo,'/').'(?![\p{L}\p{N}-])/u',$text)) throw new RuntimeException('Der Betreff muss die vollständige Schadennummer '.$caseNo.' enthalten.');
    reviewAssertCorrespondence($text);
    return $text;
}
