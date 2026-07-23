# Fachbeitrags-Automation (täglich, zwei Slots)

## Bestandsschutz veröffentlichter Inhalte (verbindlich)

- Bereits veröffentlichte Beiträge dürfen nur nach **ausdrücklicher Anweisung** gelöscht, umbenannt, ersetzt, zusammengeführt oder aus Übersichten, Sitemaps, Navigation und Routing entfernt werden.
- Änderungen an Layout, Navigation, SEO, Redirects oder Beitragsautomation dürfen keine bestehenden Inhalte unbeabsichtigt unpublizieren.
- Jede Änderung mit möglicher Auswirkung auf bestehende Fachwissen-URLs muss vor dem Merge auf Erreichbarkeit (200/301), Sitemap-Präsenz und Übersichtseinbindung geprüft werden.

## Verbindliche Begriffsnutzung (Redaktionsstandard)

- **Kumulschaden:** Vielzahl einzelner Schäden infolge derselben Unwetterlage in einem räumlich-zeitlich zusammenhängenden Bereich.
- **Komplexschaden:** einzelner Schadenfall mit erhöhter technischer, organisatorischer, wirtschaftlicher oder regulierungsbezogener Komplexität.
- **Großschaden:** nur verwenden, wenn ein einzelner Schaden außergewöhnlich umfangreich oder schadenhöhenmäßig besonders bedeutend ist.
- Begriffe wie „Großschadenkoordination“ oder „Großschadenregulierung“ dürfen nicht pauschal als Synonym für Kumullagen verwendet werden.
- In Kumullagen bleibt jeder Einzelfall separat hinsichtlich Ursache, Deckung, Vorschadenanteil, Schadenumfang und Maßnahmenbedarf zu bewerten.

## Verbindliche Zielgruppenformulierung

- Standardformulierung für Fachbeiträge: **„Versicherer, Sachverständige und Schadenregulierer“**.
- Diese Formulierung ist in Beiträgen, Meta-Descriptions, strukturierten Daten, CTAs, LinkedIn-Texten und Automationsvorlagen zu verwenden.
- Die persönliche Berufsbezeichnung von Christian Wächter bleibt unverändert: **„Sachverständiger & Großschadenregulierer“**.

## Slug-Standard für neue Fachbeiträge

- Neue Slugs müssen dauerhaft verständlich und thematisch sein.
- Nicht zulässig sind Datums-, Uhrzeit- oder Slot-Zusätze in Slugs (z. B. `YYYY-MM-DD`, `morning`, `afternoon`).
- Bestehende veröffentlichte URLs bleiben unverändert erreichbar; kürzere Nachfolge-Slugs werden nur zusätzlich mit permanenter 301-Weiterleitung eingeführt.

## Zeitfenster und Zeitzone

- **Morgenslot:** 05:15–06:40 Uhr (Europe/Berlin)
- **Nachmittagsslot:** 16:15–17:30 Uhr (Europe/Berlin)
- GitHub Actions Cron-Zeitpläne laufen grundsätzlich in UTC. Da GitHub keine native Zeitzonen-Unterstützung für Cron-Ausdrücke bietet, sind **duale UTC-Crons** hinterlegt:
  - Sommerzeit (CEST, UTC+2): `3:20 UTC` / `14:20 UTC`
  - Winterzeit (CET, UTC+1): `4:20 UTC` / `15:20 UTC`
- Der Generator prüft zusätzlich im Skript die aktuelle Berliner Ortszeit über `Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Berlin' })`. Runs außerhalb des Zeitfensters enden sauber mit Status `skipped`.
- **Kein Force-Bypass:** Veröffentlichungen außerhalb der beiden Zeitfenster sind technisch ausgeschlossen.
- Sommer-/Winterzeitumstellungen werden damit automatisch korrekt behandelt: in der Sommerzeit trifft der 3:20-UTC-Cron, in der Winterzeit der 4:20-UTC-Cron das jeweilige Zeitfenster. Der jeweils andere Cron fällt außerhalb des Fensters und wird vom Skript sauber ignoriert.

## Themenlogik und Quellenpriorität

### Primärquelle: Anonymisierte Realfälle aus Outlook-Kalender
- Der Generator nutzt (wenn konfiguriert) echte Kalenderfälle aus dem verknüpften Outlook-Postfach als primäre Fallbasis.
- Dafür werden Ereignisse im Rückblick bis zu **3 Jahren** technisch ausgewertet (`CALENDAR_CASE_LOOKBACK_DAYS`, Standard 1095).
- Ausgewertet werden strukturierte Hinweise aus Terminmetadaten und Anhängen (z. B. Dokumentations-, KVA-, Rechnungs-, Protokoll- oder Gutachtenhinweise).
- Veröffentlichung erfolgt ausschließlich **anonymisiert**: keine Namen, keine Orte/Adressen, keine Aktenzeichen, keine personenbezogenen Daten.
- Pro Beitrag werden 1–2 Fälle als fachliche Musterbasis ausgewählt.

### Sekundärquelle: Themenpool und regionale Recherche (Fallback)

### Themenpool
Der Generator verfügt über einen Themenpool von 20 vordefinierten Fachthemen aus den Bereichen:
Starkregen/Rückstau, Hochwasser/Überflutung, Sturm/Hagel, Leitungswasser, Brandschaden, Schneedruck, Tornadoereignisse, Erstbesichtigung, Plausibilitätsprüfung, Beweissicherung, Sanierungsplanung/Trocknung, Koordination Sachverständige, Rechnungs-/KVA-Prüfung, Reservierung, Kommunikation, Schadenminderung, Massenanfall, Gutachter-Plattform, Abgrenzung versichert/nicht versichert, Katastrophenschäden, Zusammenarbeit mit Fachplanern.

### Themenrotation
- Die letzten 10 verwendeten Themen (aus dem Protokoll) werden nicht erneut ausgewählt.
- Am gleichen Tag wird dasselbe Thema nicht zweimal verwendet.
- Wenn keine freien Themen verfügbar sind, fällt der Generator auf das zuletzt verwendete freie Thema zurück.

### Regionale Recherche (Mo–Fr, nur wenn keine Kalenderfallbasis verfügbar ist)
- Montag bis Freitag wird zuerst ein regionaler Aufhänger gesucht.
- Quelle: Google News RSS mit kombinierten Schaden-/Unwetterbegriffen (Starkregen, Hochwasser, Sturm, Brand, Katastrophe usw.) und Regionsbezug (Aalen, Ostalbkreis, Schwäbisch Gmünd, Heidenheim, Ulm, Göppingen, Stuttgart, Ludwigsburg, Esslingen, Ansbach, Nördlingen, Ellwangen, Backnang, Rems-Murr).
- Kandidaten werden nur berücksichtigt, wenn **sowohl** ein Regionsname als auch ein Ereignisbegriff im Titel enthalten ist und die Meldung nicht älter als 72 Stunden ist.
- Es werden ausschließlich öffentlich verfügbare Informationen genutzt. Keine erfundenen Tatsachen, keine unbelegten Schadenhöhen, Opferzahlen oder Ursachenbehauptungen.
- Die regionale Themenableitung bleibt auf den definierten Suchraum um Aalen begrenzt; unklare Sachstände werden im Beitrag als vorläufig gekennzeichnet.
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
8. **Anonymisierte Fallhinweise** im Beitragstext (wenn Kalenderfallbasis verfügbar)

## SEO und interne Verlinkung

- Frontmatter enthält: `seo.title` (max. 70 Zeichen), `seo.description` (max. 180 Zeichen), `seo.canonical`, `seo.image` (vollständige URL), `seo.imageAlt`.
- Interne Verlinkungen im Beitragstext: `/schaden-melden/`, `/fachwissen/schadenabgrenzung/`, `/fachwissen/prueffaehige-dokumentation/`, `/gutachter-plattform/`.
- Sitemap wird automatisch per `@astrojs/sitemap` beim Build erzeugt.
- Suchindex und Build-Ausgabe werden über `validate:knowledge -- --dist` auf Vollständigkeit geprüft.

## LinkedIn-/Zap-Übergabe

- Zapier-Webhook bleibt unverändert (`secrets.ZAPIER_WEBHOOK_URL`).
- Auslösung erfolgt **nur im Morgenslot** und ausschließlich nach erfolgreicher Live-URL-Prüfung (HTTP 200 + Slug im Seiteninhalt).
- Vor LinkedIn werden im Lauf verpflichtend Vorprüfung, Fachwissensvalidierung, Typprüfung (`astro check`), Build, HTML-Validierung und Link-/Build-Integration ausgeführt.
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
| Slot bereits protokolliert | Lauf beendet mit Status `skipped` |
| Build fehlgeschlagen | Kein LinkedIn-Post; kein Commit |
| Typ-/HTML-/Link-Prüfung fehlgeschlagen | Kein Commit; kein Deployment; kein LinkedIn |
| Live-Check fehlgeschlagen | Kein LinkedIn-Post; Protokoll auf `failed` gesetzt |
| Zapier-Webhook fehlgeschlagen | Protokoll auf `linkedin=failed` gesetzt; Workflow schlägt fehl |
| Deploy-Workflow (`deploy.yml`) fehlgeschlagen/Timeout | Kein LinkedIn-Post; Protokoll auf `deploy=failed` gesetzt |
| Kein Regionalanlass gefunden | Fallback auf allgemeines Fachthema |
| Keine verwertbaren Kalenderfallhinweise | Fallback auf regionale Recherche/Themenpool |
| Bildgenerierung fehlgeschlagen | Keine Veröffentlichung; Workflow schlägt fehl |
| Slug/Titel bereits vorhanden | Lauf schlägt mit Fehlermeldung ab |

## Doppelausführungsschutz

- **GitHub Actions Concurrency:** Gruppe `fachbeitrag-automation-main`, `cancel-in-progress: false` – kein Abbruch laufender Jobs, aber keine simultane Ausführung.
- **Slot-ID:** `{YYYY-MM-DD}-{slot}` als eindeutige Identifikation pro Zeitfenster und Tag.
- **publication_id:** SHA256-basierter Hash über die Slot-ID `{YYYY-MM-DD}-{slot}`; damit ist die ID pro Zeitfenster deterministisch und retry-stabil.
- **Protokollprüfung:** Vor der Erzeugung wird das CSV-Protokoll auf bereits protokollierte Einträge für Datum+Slot geprüft. Existiert ein Slot-Eintrag, wird kein zweiter Beitrag erzeugt.
- **publication_id-Duplikatschutz:** Vor der Erzeugung wird das Protokoll zusätzlich auf bereits vorhandene `publication_id` geprüft.
- **Slug-/Titel-Duplikatprüfung:** Bereits vorhandene Knowledge-Dateien mit gleichem Slug oder gleichem Titel werden erkannt und abgelehnt.

## Deployment- und Live-Gating

- Nach dem Content-Commit auf `main` wartet der Automationslauf auf den **bestehenden push-basierten Deploy-Workflow** `.github/workflows/deploy.yml`.
- Der Automationslauf wartet aktiv auf den Abschluss genau dieses Deploy-Runs für den veröffentlichten Commit (`head_sha`-Abgleich).
- Erst nach `deploy=success` folgt die Live-URL-Prüfung, erst danach die LinkedIn-/Zap-Übergabe.
- Deploy-, Live- und LinkedIn-Status werden in `docs/fachbeitrag-veroeffentlichungsprotokoll.csv` fortlaufend aktualisiert und am Laufende auf `main` committed (auch bei Fehlerfällen).

## Manueller Notfallstart und Deaktivierung

### Manueller Start über `workflow_dispatch`
1. GitHub Actions → `Fachbeitrags-Automation` → `Run workflow`
2. Optionaler Input:
   - `slot`: `morning` oder `afternoon` (erzwingt Slot-Erkennung innerhalb des gültigen Zeitfensters; leer = automatische Erkennung)

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
| `zeit_berlin` | Berliner Veröffentlichungszeit (HH:MM) |
| `slot` | `morgens` oder `nachmittags` |
| `title` | Vollständiger Beitragstitel |
| `url` | Live-URL des Beitrags |
| `category` | Fachkategorie |
| `anlass` | Anonymisierte Realfälle, regionaler Anlass oder `allgemeines Fachthema` |
| `quellen` | Anonymisierte interne Fall-/Unterlagenauswertung oder externe/Fallback-Quelle |
| `bilddatei` | Pfad zur Bilddatei (relativ zu Webroot) |
| `bild_alt_text` | Alt-Text des Bildes |
| `linkedin_status` | `pending` → `success` / `failed` / `skipped` (Nachmittag) |
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
| `M365_TENANT_ID` | Secret | Azure/Entra Mandanten-ID für Graph-Zugriff |
| `M365_CLIENT_ID` | Secret | App-Registrierung Client-ID |
| `M365_CLIENT_SECRET` | Secret | App-Registrierung Client Secret (Wert, nicht Secret-ID) |
| `M365_CALENDAR_USER_ID` | Secret | Postfach/Kalender-Benutzer (z. B. `cw@sv-schuett.eu`) |
| `FACHBEITRAG_AUTOMATION_ENABLED` | Variable | `true` (Standard) / `false` zum Deaktivieren |
| `CALENDAR_CASE_LOOKBACK_DAYS` | Variable | Rückblickfenster für Fallauswahl (Standard `1095`) |

## Relevante Dateien

| Datei | Zweck |
|---|---|
| `.github/workflows/knowledge-standard.yml` | Hauptworkflow (Zeitplanung, Orchestrierung) |
| `.github/workflows/deploy.yml` | Deploy-Workflow (Push → IONOS via SFTP) |
| `scripts/trigger-and-await-workflow.mjs` | Dispatch und Polling für Deploy-Workflow bis Abschluss |
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
