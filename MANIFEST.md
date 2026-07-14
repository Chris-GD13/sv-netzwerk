# Manifest – SV-Netzwerk v5.1

## Plattformkern

- zentrale Typen für Schadenarten, Experten und Module
- Datenquellen für zwölf Schadenarten und bestätigte Experten
- wiederverwendbare DamageCard, ExpertCard, ModuleCard und ProcessFlow
- SVOS-Dashboard mit Modulen, Rollen, Prozess und Roadmap

## Neue Routen

- `/experten/` und `/experten/[slug]/`
- `/schadenarten/[slug]/`
- `/wissen-in-180-sekunden/` und Detailseiten
- `/schaden-melden/`
- dynamische Fachbeiträge unter `/fachwissen/[id]/`

## Wissen und Suche

- Fachbeiträge für 12., 13. und 14.07.2026
- Videos-Collection für Wissen in 180 Sekunden
- Suchindex aus Seiten, Artikeln, Schadenarten, Praxisfällen, Downloads, Videos und Experten
- Validierungsskript und GitHub-Workflow für den täglichen Fachwissensstandard

## Projektdokumentation

- `docs/PROJECT_RULES.md`
- `docs/PROJECT_LOG.md`
- `docs/ROADMAP.md`
- `docs/DECISIONS.md`
- `docs/LESSONS_LEARNED.md`
- `docs/WORKFLOW_STANDARD.md`
- fünf ADRs unter `docs/adr/`
- `ARCHITECTURE.md`, `CONTENT_STANDARD.md`, `SVOS_ROADMAP.md`
- aktualisierte README-, Changelog-, Release-, Validierungs- und Deployment-Dokumentation

Die vollständige technische Dateiliste wird über `git diff --name-status` nachgewiesen.
