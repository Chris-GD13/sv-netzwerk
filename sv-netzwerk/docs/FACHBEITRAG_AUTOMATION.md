# Fachbeitrags-Automation (täglich, zwei Slots)

## Zeitfenster und Zeitzone

- **Morgenslot:** 05:15–06:40 Uhr (Europe/Berlin)
- **Nachmittagsslot:** 16:15–17:30 Uhr (Europe/Berlin)
- GitHub Actions Cron-Zeitpläne laufen grundsätzlich in UTC. Da GitHub keine native Zeitzonen-Unterstützung für Cron-Ausdrücke bietet, sind **duale UTC-Crons** hinterlegt:
  - Sommerzeit (CEST, UTC+2): `3:20 UTC` / `14:20 UTC`
  - Winterzeit (CET, UTC+1): `4:20 UTC` / `15:20 UTC`
- Der Generator prüft zusätzlich im Skript die aktuelle Berliner Ortszeit über `Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Berlin' })`. Runs außerhalb des Zeitfensters enden sauber mit Status `skipped`.
- Sommer-/Winterzeitumstellungen werden damit automatisch korrekt behandelt: in der Sommerzeit trifft der 3:20-UTC-Cron, in der Winterzeit der 4:20-UTC-Cron das jeweilige Zeitfenster. Der jeweils andere Cron fällt außerhalb des Fensters und wird vom Skript sauber ignoriert.

## Themenlogik und regionale Recherche

### Themenpool
Der Generator verfügt über einen Themenpool von 20 vordefinierten Fachthemen aus den Bereichen:
Starkregen/Rückstau, Hochwasser/Überflutung, Sturm/Hagel, Leitungswasser, Brandschaden, Schneedruck, Tornadoereignisse, Erstbesichtigung, Plausibilitätsprüfung, Beweissicherung, Sanierungsplanung/Trocknung, Koordination Sachverständige, Rechnungs-/KVA-Prüfung, Reservierung, Kommunikation, Schadenminderung, Massenanfall, Gutachter-Plattform, Abgrenzung versichert/nicht versichert, Katastrophenschäden, Zusammenarbeit mit Fachplanern.

### Themenrotation
- Die letzten 10 verwendeten Themen (aus dem Protokoll) werden nicht erneut ausgewählt.
- Am gleichen Tag wird dasselbe Thema nicht zweimal verwendet.
- Wenn keine freien Themen verfügbar sind, fällt der Generator auf das zuletzt verwendete freie Thema zurück.

### Regionale Recherche (Mo–Fr)
- Montag bis Freitag wird zuerst ein regionaler Aufhänger gesucht.
- Quelle: Google News RSS mit kombinierten Schaden-/Unwetterbegriffen (Starkregen, Hochwasser, Sturm, Brand, Katastrophe usw.) und Regionsbezug (Aalen, Ostalbkreis, Schwäbisch Gmünd, Heidenheim, Ulm, Göppingen, Stuttgart, Ludwigsburg, Esslingen, Ansbach, Nördlingen, Ellwangen, Backnang, Rems-Murr).
- Kandidaten werden nur berücksichtigt, wenn **sowohl** ein Regionsname als auch ein Ereignisbegriff im Titel enthalten ist und die Meldung nicht älter als 72 Stunden ist.
- Es werden ausschließlich öffentlich verfügbare Informationen genutzt. Keine erfundenen Tatsachen, keine unbelegten Schadenhöhen, Opferzahlen oder Ursachenbehauptungen.
- Wenn kein belastbarer Regionalanlass gefunden wird: automatischer Wechsel auf allgemeines Fachthema.

## Wochenendregel

- **Samstag und Sonntag:** Keine regionale Recherche; ausschließlich allgemeine Fachbeiträge ohne Vor-Ort-Behauptungen.
- Formulierungen, die einen aktuellen persönlichen Einsatz suggerieren, sind technisch ausgeschlossen (regionaler Signal-Lookup wird am Wochenende nicht ausgeführt).
- Erlaubte Wochenendthemen: Schadenprävention, Dokumentationsstandards, Aufgaben des Sachverständigen, Sofortmaßnahmen, Trocknungsstandards, Sanierungsfehler, Qualitätssicherung, Gutachter-Plattform.

## Inhaltserzeugung und Struktur

Der Generator erstellt pro Lauf:

1. **Fachbeitrag** in `src/content/knowledge/{slug}.md` mit vollständigem Frontmatter (Titel, Description, Kategorie, Tags, Autor, CTA, interne Links, Canonical-URL, OG-Bild, Alt-Text, Publication-Status)
2. **LinkedIn-Begleittext** in `src/content/linkedin/{datum}_{slug}.txt` (mit URL und thematisch passenden Hashtags)
3. **Wissen-in-180-Sekunden-Skript** in `src/content/videos/{datum}_wissen-in-180-sekunden_{slug}.txt`
4. **Beitragsbild** in `public/assets/images/linkedin/{slug}.svg` (SVG, 1200×630, professionelles Design in SV-Netzwerk-Farben)
5. **Library-Eintrag** am Anfang von `src/data/library.ts` (damit der neue Beitrag als aktuellster erscheint)
6. **Protokollzeile** in `docs/fachbeitrag-veroeffentlichungsprotokoll.csv`
7. **Changelog-Eintrag** in `CHANGELOG.md` (dynamische Versionsnummer)

## SEO und interne Verlinkung

- Frontmatter enthält: `seo.title` (max. 70 Zeichen), `seo.description` (max. 180 Zeichen), `seo.canonical`, `seo.image` (vollständige URL), `seo.imageAlt`.
- Interne Verlinkungen im Beitragstext: `/schaden-melden/`, `/fachwissen/schadenabgrenzung/`, `/fachwissen/prueffaehige-dokumentation/`, `/gutachter-plattform/`.
- Sitemap wird automatisch per `@astrojs/sitemap` beim Build erzeugt.
- Suchindex und Build-Ausgabe werden über `validate:knowledge -- --dist` auf Vollständigkeit geprüft.

## LinkedIn-/Zap-Übergabe

- Zapier-Webhook bleibt unverändert (`secrets.ZAPIER_WEBHOOK_URL`).
- Auslösung erfolgt **ausschließlich nach erfolgreicher Live-URL-Prüfung** (HTTP 200 + Slug im Seiteninhalt).
- Payload-Format (Zap-kompatibel):
  ```json
  {
    "title": "...",
    "description": "...",
    "first_paragraph": "...",
    "hashtags": "...",
    "image_url": "...",
    "url": "...",
    "date": "YYYY-MM-DD",
    "slug": "...",
    "publication_id": "..."
  }
  ```

## Bildgenerierung

- Format: SVG (1200×630), optimiert für OG/LinkedIn.
- Design: Professioneller Farbverlauf in SV-Netzwerk-Blau/Grün, Themenbezeichnung, Slot-Bezeichnung, Themenkürzel.
- Keine erkennbaren Personen, keine Unternehmenslogos Dritter, keine lesbare Kennzeichen.
- Dateiname: `{slug}.svg`; wird in `public/assets/images/linkedin/` abgelegt.
- Bei aktuellen regionalen Ereignissen visualisiert das Bild die Schadenart allgemein – es ist kein Originalfoto eines konkreten Ereignisses.

## Fehlerbehandlung

| Szenario | Verhalten |
|---|---|
| Außerhalb Zeitfenster | Lauf beendet mit Status `skipped`; kein Fehler |
| Slot bereits erfolgreich veröffentlicht | Lauf beendet mit Status `skipped` |
| Slot bereits protokolliert (Retry ohne `force`) | Lauf beendet mit Status `skipped` |
| Build fehlgeschlagen | Kein LinkedIn-Post; kein Commit |
| Live-Check fehlgeschlagen | Kein LinkedIn-Post; Protokoll auf `failed` gesetzt |
| Zapier-Webhook fehlgeschlagen | Protokoll auf `linkedin=failed` gesetzt; Workflow schlägt fehl |
| Kein Regionalanlass gefunden | Fallback auf allgemeines Fachthema |
| Bildgenerierung fehlgeschlagen | Keine Veröffentlichung; Workflow schlägt fehl |
| Slug/Titel bereits vorhanden | Lauf schlägt mit Fehlermeldung ab |

## Doppelausführungsschutz

- **GitHub Actions Concurrency:** Gruppe `fachbeitrag-automation-main`, `cancel-in-progress: false` – kein Abbruch laufender Jobs, aber keine simultane Ausführung.
- **Slot-ID:** `{YYYY-MM-DD}-{slot}` als eindeutige Identifikation pro Zeitfenster und Tag.
- **publication_id:** SHA256-basierter Hash über Slot-ID für globale Eindeutigkeit.
- **Protokollprüfung:** Vor der Erzeugung wird das CSV-Protokoll auf bereits erfolgreiche oder protokollierte Einträge für Datum+Slot geprüft.
- **Slug-/Titel-Duplikatprüfung:** Bereits vorhandene Knowledge-Dateien mit gleichem Slug oder gleichem Titel werden erkannt und abgelehnt.

## Manueller Notfallstart und Deaktivierung

### Manueller Start über `workflow_dispatch`
1. GitHub Actions → `Fachbeitrags-Automation` → `Run workflow`
2. Optionale Inputs:
   - `slot`: `morning` oder `afternoon` (erzwingt Slot; leer = automatische Erkennung)
   - `force`: `true` übersteuert Zeitfenster- und Retry-Schutz (aber **nicht** den Schutz vor bereits erfolgreich publiziertem Slot)

### Vorübergehende Deaktivierung
- Repository Variable `FACHBEITRAG_AUTOMATION_ENABLED` auf `false` setzen (Settings → Secrets and variables → Actions → Variables).
- Bei `false` wird der gesamte Job übersprungen (Status: `skipped`, kein Fehler, kein Beitrag).
- Die Prüfung erfolgt via Job-Level-Bedingung (`if: vars.FACHBEITRAG_AUTOMATION_ENABLED != 'false'`), sodass auch scheduled Runs vollständig deaktiviert werden.
- Zum Reaktivieren: Variable auf `true` setzen, auf leer setzen oder ganz löschen (Default ist `enabled`).

## Veröffentlichungsprotokoll

**Datei:** `sv-netzwerk/docs/fachbeitrag-veroeffentlichungsprotokoll.csv`

Spalten:

| Spalte | Inhalt |
|---|---|
| `date` | Berliner Datum (YYYY-MM-DD) |
| `slot` | `morgens` oder `nachmittags` |
| `title` | Vollständiger Beitragstitel |
| `url` | Live-URL des Beitrags |
| `category` | Fachkategorie |
| `anlass` | Regionaler Anlass oder `allgemeines Fachthema` |
| `quellen` | Verwendete Quellen (URL + Titel) oder Fallback-Hinweis |
| `bilddatei` | Pfad zur Bilddatei (relativ zu Webroot) |
| `bild_alt_text` | Alt-Text des Bildes |
| `linkedin_status` | `pending` → `success` / `failed` |
| `commit` | Git-Commit-Hash nach Push |
| `deploy_status` | `pending` → `triggered` → `success` / `failed` |
| `live_pruefung` | `pending` → `success` / `failed` |
| `topic_id` | ID des verwendeten Themas |
| `publication_id` | Eindeutige UUID-ähnliche Publikations-ID |

## Erforderliche GitHub Secrets und Variablen

| Name | Typ | Zweck |
|---|---|---|
| `SFTP_HOST` | Secret | IONOS-Server-Hostname für Deploy |
| `SFTP_USERNAME` | Secret | SFTP-Benutzername |
| `SFTP_PASSWORD` | Secret | SFTP-Passwort |
| `SFTP_PORT` | Secret | SFTP-Port |
| `ZAPIER_WEBHOOK_URL` | Secret | Zapier-Webhook-URL für LinkedIn-Post |
| `FACHBEITRAG_AUTOMATION_ENABLED` | Variable | `true` (Standard) / `false` zum Deaktivieren |

## Relevante Dateien

| Datei | Zweck |
|---|---|
| `.github/workflows/knowledge-standard.yml` | Hauptworkflow (Zeitplanung, Orchestrierung) |
| `.github/workflows/deploy.yml` | Deploy-Workflow (Push → IONOS via SFTP) |
| `scripts/run-fachbeitrag-automation.mjs` | Generator für Beitrag, Bild, LinkedIn, Library, Changelog |
| `scripts/validate-fachbeitrag-preflight.mjs` | Preflight-Prüfung vor Build |
| `scripts/update-fachbeitrag-log.mjs` | Protokollaktualisierung nach Push, Live-Check und LinkedIn |
| `scripts/validate-knowledge.mjs` | Frontmatter- und Build-Integrationsvalidierung |
| `src/content/knowledge/` | Veröffentlichte Fachbeiträge (Markdown) |
| `src/content/linkedin/` | LinkedIn-Begleittexte |
| `src/content/videos/` | Wissen-in-180-Sekunden-Skripte |
| `public/assets/images/linkedin/` | Beitragsbilder (SVG) |
| `src/data/library.ts` | Fachwissens-Übersichtsdaten |
| `docs/fachbeitrag-veroeffentlichungsprotokoll.csv` | Veröffentlichungsprotokoll |
