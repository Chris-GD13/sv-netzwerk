# ADR-001: GitHub ist der Projektmaster

- Status: akzeptiert
- Datum: 14.07.2026

## Kontext

Lokale, deployte und historisch erzeugte Dateien müssen eindeutig einem freigegebenen Stand zugeordnet werden.

## Entscheidung

`Chris-GD13/sv-netzwerk`, Branch `main`, ist die einzige Quelle der Wahrheit. Codex synchronisiert vor der Arbeit und versioniert Code, Inhalte, Dokumentation und Workflows gemeinsam.

## Konsequenzen

Direkte Serveränderungen sind kein regulärer Entwicklungsweg. Ein produktiver Stand ist erst nach Commit, Push, erfolgreichem Workflow und Live-Verifikation bestätigt.
