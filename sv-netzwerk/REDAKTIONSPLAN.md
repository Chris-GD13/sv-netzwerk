# Redaktionsplan

## Wöchentlicher Veröffentlichungsrhythmus

| Tag | Aktion | Format | Kanal |
|---|---|---|---|
| Montag | Fachbeitrag erstellen und veröffentlichen | B-Beitrag (800–1.200 Wörter) | sv-netzwerk.eu |
| Dienstag | LinkedIn-Beitrag zum Montagsartikel | Kurzbeitrag mit Link | LinkedIn |
| Mittwoch | Täglicher Pflichtbeitrag | A-Beitrag (1.500–2.500 Wörter) | sv-netzwerk.eu |
| Donnerstag | LinkedIn-Beitrag zu bestehendem Fachthema | Kurzbeitrag mit Link | LinkedIn |
| Freitag | Fachbeitrag erstellen und veröffentlichen | B-Beitrag (800–1.200 Wörter) | sv-netzwerk.eu |
| Samstag | Kurzwissen / Praxis-Tipp | C-Beitrag (300–500 Wörter) | sv-netzwerk.eu |
| Sonntag | Kurzwissen / Archiv | C-Beitrag (300–500 Wörter) | sv-netzwerk.eu |

## Qualitätsanforderungen je Fachbeitrag

Jeder Fachbeitrag muss:
- ein konkretes Praxisproblem behandeln,
- fachlich korrekt und recherchiert sein,
- SEO-Titel, Meta-Description und sprechende URL enthalten,
- H1-, H2- und H3-Struktur aufweisen,
- Einleitung, Hauptteil, Praxisbeispiel, Fazit und CTA enthalten,
- intern auf Leistungen, Schadenarten, Experten und thematisch passende Beiträge verlinken.

## SEO-Ziele

Mit jedem Beitrag soll:
- die thematische Autorität (Topical Authority) wachsen,
- die Zahl indexierter Seiten steigen,
- die interne Verlinkung verbessert werden,
- langfristig organischer Traffic aufgebaut werden.

## Monatliche Erfolgskontrolle

Am 1. des Monats wird automatisch ein SEO-Statusbericht als GitHub Issue erstellt (Workflow: `.github/workflows/seo-report.yml`).
Auszuwerten sind: indexierte Seiten, organische Klicks, Impressionen, Ø Google-Position, Top-Keywords, erfolgreichste Seiten, neue Backlinks, Core Web Vitals, Ladezeiten, Crawl-/Indexierungsfehler sowie Seiten mit positiver oder negativer Rankingentwicklung.

## LinkedIn-Richtlinien

LinkedIn-Beiträge dienen ausschließlich der Reichweitensteigerung. Sie wecken Interesse, geben den Artikel nicht vollständig wieder und führen den Leser auf die Website. Jeder Beitrag verlinkt den zugehörigen Fachartikel auf sv-netzwerk.eu.

## Technische Voraussetzungen (täglich)

Die tägliche Prüfung durch `npm run validate:knowledge` stellt sicher, dass jeden Tag genau ein `dailyStandard`-Artikel mit korrektem Wortumfang und vollständigem Frontmatter vorhanden ist. Details: `docs/WORKFLOW_STANDARD.md`.

Stand: 17.07.2026
