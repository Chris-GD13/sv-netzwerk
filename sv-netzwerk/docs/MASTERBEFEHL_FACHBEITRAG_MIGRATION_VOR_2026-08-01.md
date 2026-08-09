# MASTERBEFEHL: Serielle Migration aller Fachbeiträge vor 2026-08-01

Stand: 09.08.2026
Status: verbindlich und aktiv

Dieses Dokument ist die vollständige, verbindliche Arbeitsanweisung für die serielle Migration aller öffentlichen Fachbeiträge mit `publication.status published` und `publishedAt` vor dem 01.08.2026.

---

## Ziel

Alle betroffenen Beiträge werden vollständig auf den verbindlichen SV-Netzwerk-Standard migriert. Bestehende Slugs, URLs, Canonicals und ursprüngliche Veröffentlichungsdaten bleiben erhalten. Genau ein Beitrag pro PR. Serielle Abarbeitung: erst nach Merge, Deployment und Live-Prüfung beginnt der nächste Beitrag.

---

## Verbindliche Grundlagen

Vor Beginn jedes Beitrags vollständig lesen:
- `sv-netzwerk/MASTER_ARBEITSSTANDARD.md`
- `sv-netzwerk/docs/FACHBEITRAG_STANDARD.md`
- `sv-netzwerk/VALIDATION.md`

**Qualitätsreferenzen:**
- `src/content/knowledge/schneedruck-winterschaeden-bewertung-regulierung.md` (primäre Referenz)
- `src/content/knowledge/frostbedingte-leitungswasserschaeden-rohrbruch-abgrenzung.md` (zweite Referenz)

Fachliche Systematik, Bearbeitungstiefe, vorsichtige Kausalitätsbewertung, Fachgrenzen, Dokumentationsstruktur, Kostenprüfung und Quellenqualität themengerecht übertragen. Keine fachfremden Inhalte oder Formulierungen kopieren.

---

## Phase 0 – Standard und Masterdatei (abgeschlossen)

- ✅ `docs/FACHBEITRAG_STANDARD.md` aktualisiert (09.08.2026): nennt Schneedruck-Beitrag als primäre Referenz
- ✅ Wissen in 180 Sekunden gehört nicht zur Produktionspflicht
- ✅ Keine neuen separaten Video-Skripte/-Dateien, Video-Routen, Video-Menüs oder Video-Automatiken
- ✅ Keine typografischen Platzhalterbilder
- ✅ `src/data/library.ts` niemals leeren oder vollständig ersetzen
- ✅ Dieses MASTERBEFEHL-Dokument erstellt
- ✅ `docs/FACHBEITRAG_MIGRATION_VOR_2026-08-01.csv` erstellt

---

## Phase 1 – Bestandsaufnahme (abgeschlossen mit Inventar-PR)

Inventar-Datei: `docs/FACHBEITRAG_MIGRATION_VOR_2026-08-01.csv`

### Ausgangszahlen (Stand origin/main, 09.08.2026)

- **Library-Gesamtanzahl**: 46 Einträge in `src/data/library.ts`
- **Knowledge-Dateien gesamt**: 41 in `src/content/knowledge/`
- **Beiträge vor 2026-08-01 (published)**: 19 Artikel
- **Beiträge ab 2026-08-01**: 22 Artikel (nicht Gegenstand dieser Migration)

### Bekannte Anomalien in library.ts (Klasse D – ausgeschlossen von Auto-Migration)

1. `/fachwissen/brandschaden-notmassnahmen-uebergang-zur-wiederherstellung/` – **doppelt** (Zeile 288 + 375 mit unterschiedlichem Titel/Tags)
2. `/fachwissen/wasserschaden-rueckbau-technische-abgrenzung/` – **doppelt** (Zeile 356 + 430 mit unterschiedlichem Inhalt)
3. `/fachwissen/unwetter-ludwigsburg-starkregen-hagel-sturm-schadensteuerung/` – Library-Eintrag ohne Knowledge-Datei (Klasse C)

Diese Anomalien werden **nicht** im Rahmen dieser Migration automatisch bereinigt. Sie sind in der CSV als `ausgeschlossen` oder `blockiert` markiert.

### Zustandsklassen-Zuweisung

- **Klasse A** (regulär migrieren): 15 Beiträge
- **Klasse B** (listing inkonsistent): 0 (alle Library-Einträge gefunden)
- **Klasse C** (Library ohne Content): unwetter-ludwigsburg – ausgeschlossen
- **Klasse D** (Duplikat/widersprüchlich): brandschaden-notmassnahmen (Duplikat in Library), wasserschaden-rueckbau (Duplikat in Library)
- **Klasse E** (widersprüchlich, ungeklärt): 0

### Sonderbehandlung: Nicht zu migrieren

- `svos-foundation.md` – kein Fachbeitrag (technische Architekturannotation, kein Inhalt)
- `leitungswasserschaden-aalen-sachverstaendiger-aufgaben.md` – contentLevel C, lokaler SEO-Artikel, kein vollwertiger Fachbeitrag; migrierbar wenn eindeutig Klasse A
- `sachverstaendiger-versicherungsschaeden-aalen-schadenaufnahme.md` – contentLevel C, lokaler SEO-Artikel; migrierbar wenn eindeutig Klasse A
- `schadenregulierer-versicherungen-technische-pruefung-schadensteuerung.md` – contentLevel kurz; migrierbar

---

## Phase 2 – Serielle Migration

### Reihenfolge (chronologisch absteigend, jüngster zuerst)

| Reihenfolge | Datum | Slug (Kurzform) | Migrationsstatus |
|---|---|---|---|
| 1 | 2026-07-31 | rueckstauschaden-fitnessstudio | offen |
| 2 | 2026-07-28 | grossflaechige-leitungswasserschaeden | offen |
| 3a | 2026-07-27 | schadenregulierer-versicherungen | offen |
| 3b | 2026-07-27 | sachverstaendiger-aalen-schadenaufnahme | offen |
| 3c | 2026-07-27 | leitungswasserschaden-aalen | offen |
| 4 | 2026-07-21 | sturm-hagel-serienschaeden | offen |
| 5a | 2026-07-20 | starkregen-rueckstau-schadenaufnahme | offen |
| 5b | 2026-07-20 | hochwasser-grossschadenkoordination | offen |
| 6 | 2026-07-19 | kumulschaeden-priorisierung | offen |
| 7 | 2026-07-18 | leitungswasserschaden-erstmassnahmen | offen |
| 8 | 2026-07-17a | brandschaden-notmassnahmen | offen |
| 9 | 2026-07-17b | schadenakte-strukturieren | offen |
| 10 | 2026-07-17c | sturmschaden-windwirkung | offen |
| 11 | 2026-07-16 | regiekosten-pruefen | offen |
| 12 | 2026-07-15 | fachliche-zustaendigkeit | offen |
| 13 | 2026-07-13 | prueffaehige-dokumentation | offen |
| 14 | 2026-07-12 | kontrollierter-rueckbau | offen |
| 15 | 2026-07-14 | schadenabgrenzung | offen |
| X | 2026-07-14 | svos-foundation | ausgeschlossen |

---

## Fachlicher Mindestumfang je Beitrag

Jeder migrierte Beitrag muss enthalten (soweit thematisch anwendbar):

1. Fachliche Einordnung / Einleitung
2. Typische Schadenbilder und betroffene Bauteile
3. Abgrenzung ähnlicher Ursachen und Vorschäden / konkurrierende Ursachen
4. Vorschäden, Mängel und Instandhaltung
5. Objektspezifische Prüffragen / Feststellungen
6. Gefahrenabwehr und Sofortmaßnahmen
7. Konkrete prüffähige Dokumentationsanforderungen
8. Messungen und technische Nachweise
9. Zuständigkeits- und Fachgrenzen
10. Notwendige Fachplaner, Labore, Spezialisten
11. Versicherungstechnische Prüffragen ohne Deckungszusage
12. Schadenminderung / Regress
13. Schaden- und Kostenprüfung
14. Fiktives Praxisbeispiel (ausdrücklich als fiktiv gekennzeichnet)
15. Handlungsempfehlungen
16. Fazit
17. Belastbare Primär-/offizielle Quellen (alle per GET verifiziert)

---

## Inhaltliche Verbote

- Keine pauschalen Deckungs- oder Leistungsaussagen
- Keine erfundenen Ereignisse, Messwerte, Fälle, Norminhalte oder Grenzwerte
- Keine spekulative Kausalität
- Befund, Ursache, Bewertung, Zuständigkeit und Kostenfolge trennen
- Fachgrenzen wahren
- Praxisbeispiele ausschließlich fiktiv und eindeutig so kennzeichnen

---

## Metadaten je Beitrag

Erhalten: `slug`, `canonical`, `publishedAt`, `author`, `category`
Ergänzen: präziser `title`, `description`, `tags`, `teaser`, `linkedinSummary`, `cta`, `relatedLinks`, `damageTypes`
Setzen: `author: christian-waechter`, `dailyStandard: false`, passendes `contentLevel`, `updatedAt: <tatsächliches Datum>`, `publication.status: published`, `seo.noindex: false`
`videoScript`: nur behalten wenn Schema es technisch zwingend erfordert; niemals separate Video-Datei erstellen

---

## Quellen

- Aktuelle, thematisch passende Primär-/offizielle Quellen: `gesetze-im-internet.de`, DIN Media, DGUV, BAuA, BBK, DWD, UBA, zuständige Verbände
- Jede URL tatsächlich per GET auf Status, Endziel, Titel und fachliche Passung prüfen
- Normen vollständig mit Teil und Ausgabe nennen
- Nicht erreichbare, ungeeignete oder widersprüchliche Quellen nicht verwenden
- Bei nicht belastbarer Quellenlage: Status `blockiert`, nicht veröffentlichen

---

## Library.ts – Schutzregeln

- Niemals leeren oder Array vollständig ersetzen
- Keine Architektur-/Deduplizierungsänderung
- Keine fremden Einträge entfernen
- Nur vorhandenen Eintrag desselben Slugs aktualisieren
- Keine Doppelkarte
- Library-Anzahl vor/nach jeder Bestandsmigration identisch: **46 Einträge**
- Bei Mengenänderung sofort stoppen, Diff prüfen, beheben

---

## Arbeitsablauf je Beitrag

1. `git fetch origin` und aktuelles `origin/main` prüfen
2. Neuen Worktree und Branch `content/migrate-<published-date>-<slug-kurzform>` von `origin/main` erstellen
3. CSV auf `in_arbeit` setzen
4. Ausschließlich einen Beitrag samt Library-Eintrag, CHANGELOG, CSV und Protokoll bearbeiten
5. `npm ci`, `npm run check`, `npm run build` und alle Dist-Nachweise
6. Vollständigen Diff und library.ts-Schutz prüfen (46 Einträge)
7. Committen, pushen, PR nach main erstellen
8. PR-Dateiumfang und Checks prüfen
9. Nur bei grünem, konfliktfreiem Stand mergen
10. Produktionsdeployment vollständig überwachen
11. Live-Seite mit Cache-Busting prüfen
12. CSV mit tatsächlichen Werten aktualisieren
13. Erst danach nächsten Beitrag beginnen

---

## Pflichtprüfung je PR

- Genau eine bestehende Content-Datei fachlich geändert
- Keine zweite Veröffentlichung
- Slug/Canonical/publishedAt unverändert
- `updatedAt` korrekt gesetzt
- Genau ein bestehender LibraryItem aktualisiert
- Library-Anzahl: 46 (unverändert)
- Keine Einträge entfernt
- Dist-Detailroute vorhanden
- Übersicht genau eine Karte
- Kategorie/Tag/Suche/Sitemap konsistent
- Keine Drafts öffentlich
- Keine neue Video-Datei oder Platzhaltergrafik
- Keine unerwarteten Dateien
- `check`/`build` Exit 0
- Keine Regression

---

## Live-Prüfung je Beitrag

HTTP 200, endgültige URL, Titel, ursprüngliches Datum, Aktualisierungsdatum, vollständiger Artikel, Quellenabschnitt, keine Ersatzzeichen, keine Deckungszusage, Karte/Übersicht/Kategorie/Tags/Suche/Sitemap, keine Doppelkarte, keine Draft-/Videodatei, gesamte Übersicht vollständig.

---

## Echte Stopbedingungen

- Widersprüchliche/nicht belastbare Quellen
- Ungeklärte Identität
- Fehlende Ausgangsdatei
- Widersprüchlicher Slug/Canonical
- Unerwartete fremde Änderungen
- Verlust/Hinzufügen von Library-Einträgen
- Mergekonflikt
- Fehlgeschlagener Check/Build/Deployment
- Nicht erreichbare Route
- Verschwundene Karten/Kategorien/Tags
- Pagination-Regression
- Mehr als ein Inhaltsbeitrag im PR

---

## Wiederaufnahme

Bei echter Laufzeitgrenze: keinen halbfertigen Artikel mergen. Aktuellen Stand sicher committen/pushen, CSV aktualisieren, letzten vollständig live geprüften und nächsten offenen Beitrag nennen.

Fortsetzungsbefehl:
> Setze die Fachbeitragsmigration gemäß `docs/FACHBEITRAG_MIGRATION_VOR_2026-08-01.csv` beim ersten noch offenen oder sauber wiederaufnehmbaren Eintrag fort. Wende `MASTER_ARBEITSSTANDARD`, `docs/FACHBEITRAG_STANDARD.md` und `docs/MASTERBEFEHL_FACHBEITRAG_MIGRATION_VOR_2026-08-01.md` unverändert an.
