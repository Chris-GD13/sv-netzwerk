---
name: schadenbericht
description: Erstellt Erst-, Zwischen-, Schluss- und sonstige Schadenberichte aus einem eigenen Schadenfall im SV-Netzwerk Prüfportal. Verwenden, wenn ein Sachverständiger eine Schaden-Nr. nennt, Portal- oder Drive-Unterlagen auswerten oder einen kopierfertigen Bericht für ClaimsForce erstellen lassen möchte. Normale Schadenberichte und SV-GF-Schäden werden strikt getrennt behandelt.
---

# SV-Netzwerk Schadenbericht

## Verbindlicher Ablauf

1. Den Fall immer zuerst mit `search` anhand der Schaden-Nr. suchen und danach mit `fetch` öffnen.
2. Die von `fetch` zurückgegebenen Falldaten und die Dokumentenliste prüfen. Relevante Unterlagen einzeln mit `read_case_file` lesen. Bei Fotos oder Drohnenaufnahmen nur sichtbare, fachlich sicher erkennbare Tatsachen beschreiben.
3. Vor dem Schreiben anhand von Versicherer, Fallart, Dateinamen und Vorlagen entscheiden:
   - Normaler Schaden: `references/normaler-schadenbericht.md` vollständig befolgen.
   - SV-GF- oder Groß-TF-Schaden: `references/sv-gf-schaden.md` befolgen. Niemals die normale Gliederung darüberlegen.
4. Ausschließlich belegte Angaben verwenden. Unklare oder fehlende Angaben knapp als offen kennzeichnen. Keine Daten, Ursachen, Beträge, Teilnehmer, Versicherungsverhältnisse oder Besichtigungsergebnisse erfinden.
5. Standardausgabe ist reiner, kopierfertiger Berichtstext im Chat. Kein Word, PDF oder sonstiger Dateianhang, sofern der Benutzer das nicht ausdrücklich verlangt.
6. Einen Bericht nur auf ausdrücklichen Wunsch mit `save_case_draft` als neuen, ungeprüften Entwurf an das Portal zurückgeben. Originalunterlagen, Falldaten, Freigaben und versendete Dokumente niemals verändern.

## Schreibweise

- Natürliches, sachverständiges Deutsch; keine KI-Floskeln.
- Tatsachen, Angaben Dritter und eigene Feststellungen sprachlich trennen.
- Den Besichtigungstag und den Einsatz von Drohne oder sonstiger Messtechnik nur nennen, wenn dies aus dem Auftrag oder den Unterlagen hervorgeht.
- Zahlen mit Einheit und Bezugsart wiedergeben, etwa netto, Mehrwertsteuer und brutto.
- Widersprüche nicht stillschweigend auflösen, sondern im Bericht kenntlich machen.

## Quellenkontrolle

Vor Abgabe intern prüfen:

- Stimmen Schaden-Nr., Versicherungsschein-Nr., Versicherer, VN/Objekt und Schadenort?
- Ist jede Tatsachenbehauptung durch Falldaten, Dokumente, Bilder oder eine ausdrücklich mitgeteilte Besichtigungsfeststellung gedeckt?
- Wurde die richtige Gliederung verwendet?
- Sind offene Punkte als offen bezeichnet?
- Ist der Text direkt kopierbar und frei von technischen Quellen-IDs?
