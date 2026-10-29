<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
commonHeaders();
$user = requireAuth();
if (!in_array((string)($user['role'] ?? ''), ['administrator','projektleiter','pruefer','sachverstaendiger'], true)) {
    apiError(403, 'Keine Berechtigung.');
}
header('Content-Type: text/plain; charset=utf-8');
echo <<<TXT
SV-NETZWERK ARBEITSGRUNDLAGE
Stand: 29.10.2026

Diese authentifizierte Portalquelle ist vor jeder regelabhängigen Fallbearbeitung vollständig zu lesen. Neuere ausdrückliche Nutzeranweisungen gehen älteren Aussagen vor.

Pflicht vor jeder Bearbeitung:
Fall zuerst im IONOS-gestützten SV-Netzwerk-Prüfportal suchen und öffnen. Vollständige Schadennummer, Berechtigung, VN, Objekt und Schadenort prüfen. Fehlende oder unvollständige Daten als offenen Punkt behandeln. Keine Fallkopien anlegen. Keine automatische Versendung. Ergebnisse nur als Entwurf oder zur Freigabe vorbereiten. Tatsächliche Übermittlung, Zahlung oder Freigabe niemals behaupten, wenn sie nicht nachgewiesen ist.

Haftpflichtprüfung:
Jeden Haftpflichtfall in dieser Reihenfolge prüfen: Plausibilität, danach Haftung, danach Deckung. Plausibilität fragt, ob sich der Schaden so zutragen konnte und ob Schadenbild und Schilderung zusammenpassen. Haftung fragt, ob der Versicherungsnehmer verantwortlich gemacht werden kann und ob Entlastungsgründe bestehen. Deckung fragt, ob für den festgestellten Schaden Versicherungsschutz besteht oder ein Ausschluss greift.

Mietschäden und Bodenbeläge:
Einzelne abgrenzbare Ereignisse wie einzelne Kratzer oder Dellen können Haftung und Deckung auslösen. Wiederholte Ereignisse oder kumulierte Beschädigungen können als übermäßige Beanspruchung gelten und vom Versicherungsschutz ausgeschlossen sein. Normale Abnutzung während der Mietzeit begründet keine Haftung. Die Abnutzung ist im Verhältnis zur Mietdauer zu bewerten. Für die Abwehr unberechtigter Ansprüche kann trotzdem Haftpflichtdeckung bestehen.

Berichtswesen:
Risikoangaben vollständig erfassen, insbesondere Wohnfläche, Baujahr, Zustand, Nutzung, Lage, Ausstattung und Versicherungssumme. Regress und Doppelversicherung gezielt prüfen und bei fehlender Möglichkeit kurz begründen. Handlungsempfehlungen müssen die nächsten Schritte, Beträge und Gewerke nennen. Kürzungen nachvollziehbar begründen. Berichte in vollständigen Sätzen schreiben. Schadenumfang, Fotodokumentation und Bildinhalte beschreiben. Relevante Unterlagen beifügen. Vor Abgabe Bericht und Anlagen gegenlesen. Originalvorlagen und native Claims-Formulare fortführen. Originale nicht überschreiben.

Geltung:
Diese Arbeitsgrundlage gilt verbindlich für Marc Work, Holger Work, Christians Work, Codex und Langdock. Langdock verwendet dabei die authentifizierte SV-Netzwerk-Kennung und darf nur die für diesen Zugang freigegebenen Arbeitsgrundlagen und Fälle verarbeiten. Die jeweiligen Nutzer arbeiten ausschließlich unter ihrer eigenen Anmeldung und in ihrem eigenen Bestand. Bei fehlendem Zugriff auf diese authentifizierte Quelle darf keine regelabhängige Bearbeitung fortgesetzt werden.
TXT;
