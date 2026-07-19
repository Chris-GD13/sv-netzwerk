# Fachbeitrags-Automation (täglich, zwei Slots)

## Zeitfenster und Zeitzone

- **Morgenslot:** 05:15-06:40 Uhr (Europe/Berlin)
- **Nachmittagsslot:** 16:15-17:30 Uhr (Europe/Berlin)
- Workflow-Crons laufen in UTC; die Slot-Zuordnung erfolgt zusätzlich im Generator über lokale Berlin-Zeit.
- Sommer-/Winterzeit wird über duale UTC-Crons und die Laufzeitprüfung in `Europe/Berlin` abgesichert.
- Eine manuelle `workflow_dispatch`-Ausführung mit `slot` bleibt außerhalb des Fensters blockiert, sofern `force` nicht gesetzt ist.

## Themenlogik und regionale Recherche

- Montag bis Freitag wird zuerst ein regionaler Aufhänger gesucht (Google-News-RSS mit Unwetter-/Schadenbegriffen und Regionsbezug im priorisierten Suchraum um Aalen, ca. 150-km-Region über definierte Orts-/Kreis-Filter).
- Es werden nur öffentlich verfügbare Meldungen genutzt; keine erfundenen Fakten, keine unbelegten Schadenhöhen, Opferzahlen oder Ursachenbehauptungen.
- Kandidaten werden nur berücksichtigt, wenn Regions- und Ereignisbegriffe (z. B. Starkregen, Hochwasser, Sturm, Brand, Katastrophe) im Titel enthalten sind.
- Wenn kein belastbarer Regionalanlass vorliegt, wird automatisch ein allgemeiner Fachbeitrag aus dem Kumul-/Regulierungskontext erstellt.

## Wochenendregel

- Samstag/Sonntag werden ausschließlich allgemeine Fachbeiträge ohne Vor-Ort-Behauptungen erzeugt.
- Formulierungen, die einen aktuellen persönlichen Einsatz suggerieren, sind ausgeschlossen.

## Inhaltserzeugung und Struktur

Der Generator erstellt pro Lauf:

1. Fachbeitrag in `src/content/knowledge/*.md`
2. LinkedIn-Begleittext in `src/content/linkedin/*.txt`
3. Wissen-in-180-Sekunden-Skript in `src/content/videos/*.txt`
4. Beitragsbild im bestehenden Projektformat (SVG) in `public/assets/images/linkedin/*.svg`
5. Eintrag in `src/data/library.ts`
6. Protokollzeile in `docs/fachbeitrag-veroeffentlichungsprotokoll.csv`

## SEO und interne Verlinkung

- Frontmatter enthält Titel, Description, Kategorie, Tags, Veröffentlichung, Canonical-URL, OG-Bildpfad und `imageAlt`.
- Interne Verlinkungen sind im Beitrag vorgegeben (`/schaden-melden/`, Fachwissensbeiträge, Gutachter-Plattform).
- Sitemap-/Suche-/Link-Integration wird über Build und `validate:knowledge -- --dist` geprüft.

## LinkedIn-/Zap-Übergabe

- Zap bleibt unverändert als Webhook (`ZAPIER_WEBHOOK_URL`).
- Auslösung erfolgt **erst nach erfolgreicher Live-URL-Prüfung**.
- Payload-Format bleibt Zap-kompatibel (`title`, `description`, `first_paragraph`, `hashtags`, `image_url`, `url`, `date`, `slug`, `publication_id`).

## Fehlerbehandlung

- Außerhalb Zeitfenster: Lauf wird sauber mit Status `skipped` beendet.
- Bereits veröffentlichter Slot: erneuter Lauf erzeugt keinen weiteren Beitrag.
- Bereits protokollierter Slot (Retry/Neustart): erneuter Lauf erzeugt ohne `force` keinen weiteren Beitrag.
- Fehlender Live-Check oder fehlender Zap-Webhook: Workflow bricht mit Fehler ab; kein LinkedIn-Post.
- Fehlender Regionalanlass: fallback auf allgemeines Fachthema.
- Veröffentlichungsprotokoll wird nach Push, Live-Check und LinkedIn automatisiert auf `triggered|success|failed` aktualisiert.

## Doppelausführungsschutz

- GitHub Actions `concurrency` verhindert parallele Rennen.
- Slot-ID (`YYYY-MM-DD + slot`) und `publication_id` erzwingen Idempotenz.
- Veröffentlichungsprotokoll wird auf bereits protokollierte und erfolgreich publizierte Slots geprüft.
- Titel-/Slug-Dubletten werden vor Schreiben neuer Beiträge geprüft.

## Manueller Notfallstart und Deaktivierung

- `workflow_dispatch` mit optionalen Inputs:
  - `slot` (`morning|afternoon`)
  - `force` (übersteuert Zeitfenster-/Retry-Schutz für Notfälle, jedoch nicht den Schutz vor bereits erfolgreich publiziertem Slot)
- Repo-Variable `FACHBEITRAG_AUTOMATION_ENABLED` (`true|false`) aktiviert/deaktiviert die Automation temporär.

## Relevante Dateien

- Workflow: `.github/workflows/knowledge-standard.yml`
- Generator: `scripts/run-fachbeitrag-automation.mjs`
- Protokollupdate: `scripts/update-fachbeitrag-log.mjs`
- Validierung: `scripts/validate-fachbeitrag-preflight.mjs`, `scripts/validate-knowledge.mjs`
