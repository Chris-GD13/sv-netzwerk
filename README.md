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

Die interne Seite `/intern/aufgaben/` zeigt Outlook-Nachrichten aus „Zu erledigen“
als kompakte Tabelle mit Eingang, Schadennummer, Absender, Betreff, IONOS-Ablage
und Aktionen. Suche, Fallfilter und Sortierung ermöglichen gezieltes Abarbeiten;
Mailvorschauen werden nur auf Wunsch aufgeklappt. Erkannte Schadennummern
verlinken den Fall mit der konkreten Aufgaben-ID in einem neuen Tab, sodass die
Arbeitsliste erhalten bleibt. „Aufgabe bearbeiten“ öffnet ebenfalls die
fallbezogene Nachricht, „Erledigt“ verschiebt sie in den gleichnamigen
Outlook-Ordner. Der automatische IONOS-Abgleich zeigt seinen Stand je Nachricht
separat an und ersetzt keine fachliche Erledigung.
Die Schadennummernerkennung berücksichtigt auch geteilte Zifferngruppen wie
`26-165 840-0` und `26-165-840-0` sowie zweistellige Endungen wie
`61-1181377-91`. Die Fallzuordnung ignoriert reine Trennzeichenunterschiede,
behält aber sämtliche Ziffern einschließlich der Endung bei.
Mehrteilige Versicherer-Nummern wie `00-031-404193-0001` und
`408-53-25000238-1` bleiben vollständig erhalten, einschließlich führender
Nullen. Eine ausdrücklich als Schadennummer bezeichnete Nummer hat Vorrang
vor anderen Referenzen; Telefon-, Rechnungs- und Auftragsnummern werden nicht
als solche übernommen.

```text
cd sv-netzwerk
npm ci
npm run validate:knowledge
npm run build
```

Deployment: GitHub Actions überträgt den Inhalt von `dist/` direkt in das bestätigte IONOS-Document-Root `/sv-netzwerk`. Die Live-Version wird über `/deploy-version.txt` verifiziert.
