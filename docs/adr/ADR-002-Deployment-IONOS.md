# ADR-002: Deployment nach IONOS

- Status: akzeptiert
- Datum: 14.07.2026

## Entscheidung

GitHub Actions baut das Astro-Projekt. Der Inhalt von `sv-netzwerk/dist/` wird per SFTP direkt nach `/sv-netzwerk` übertragen. `/sv-netzwerk/dist/` darf nicht entstehen.

## Verifikation

Vor und nach dem Upload wird das Zielverzeichnis geprüft. Live muss `deploy-version.txt` vorhanden sein und exakt `github.sha` ausweisen.

## Konsequenzen

Automatische Remote-Verzeichnissuche und Hash-Heuristiken sind ausgeschlossen. Das bestätigte Document Root bleibt fest konfiguriert.
