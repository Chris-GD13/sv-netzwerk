# SV-Netzwerk

Master-Repository für den vollständigen Website- und Plattformstand von [sv-netzwerk.eu](https://sv-netzwerk.eu/).

## Projektorganisation

Die verbindliche Projektdokumentation liegt in [`docs/`](docs/):

- [Projektregeln](docs/PROJECT_RULES.md)
- [Projektlog](docs/PROJECT_LOG.md)
- [Roadmap](docs/ROADMAP.md)
- [Entscheidungen](docs/DECISIONS.md)
- [Lessons Learned](docs/LESSONS_LEARNED.md)
- [Workflow-Standard](docs/WORKFLOW_STANDARD.md)
- [Architecture Decision Records](docs/adr/)

Rollen: Christian ist Product Owner, ChatGPT verantwortet Architektur und Projektsteuerung, Codex ist die Entwicklungsumgebung und setzt Änderungen direkt im Repository um.

## Technischer Einstieg

Das Astro-Projekt liegt in `sv-netzwerk/`.

```text
cd sv-netzwerk
npm ci
npm run validate:knowledge
npm run build
```

Deployment: GitHub Actions überträgt den Inhalt von `dist/` direkt in das bestätigte IONOS-Document-Root `/sv-netzwerk`. Die Live-Version wird über `/deploy-version.txt` verifiziert.
