<?php
declare(strict_types=1);

// Internal evidence notes remain in the review record, never in correspondence.
function reviewCorrespondenceProblem(string $text): bool {
    return preg_match('/\b(?:KI|ChatGPT|OpenAI|Sprachmodell|Prüfvorschlag|Belegsatz)\b|künstlich(?:e|er|en)?\s+Intelligenz|(?:automatisiert|automatisch|modellgestützt|maschinell)(?:e|er|en)?\s+(?:Prüfung|Freigabe|Bewertung)|mitgeteilt(?:e|en|er)?\s+Angabe|nicht\s+(?:als\s+)?unabhängig\s+(?:nachgewiesen|bestätigt|verifiziert)|offene\s+Punkte\s*:|interne\s+(?:Prüfhinweise|Auswertung|Quellenpriorität)/iu', $text) === 1;
}

function reviewAssertCorrespondence(string $text): void {
    if (reviewCorrespondenceProblem($text)) {
        throw new RuntimeException('Bitte die Stellungnahme für die Mail fachlich formulieren. Interne Prüf- und Quellenhinweise sowie Angaben zur automatisierten Verarbeitung dürfen nicht in die Korrespondenz übernommen werden. Konkrete Vorbehalte oder benötigte Unterlagen bitte direkt benennen.');
    }
}
