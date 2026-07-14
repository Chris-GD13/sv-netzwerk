# Deployment – sv-netzwerk.eu

## Bestätigtes IONOS-Ziel

Das Document Root der Domain `sv-netzwerk.eu` ist verbindlich:

`/sv-netzwerk`

Der Inhalt des lokalen Build-Verzeichnisses `sv-netzwerk/dist/` wird direkt in dieses Verzeichnis übertragen. Dadurch liegen insbesondere `index.html`, `assets/` und `deploy-version.txt` unmittelbar unter `/sv-netzwerk`.

Ein Unterordner `/sv-netzwerk/dist/` darf nicht entstehen. Der Workflow prüft das Document Root vor und nach dem Upload und bricht ab, sobald dort ein `dist/`-Unterordner erkannt wird.

## Live-Verifikation

Der Build erzeugt `dist/deploy-version.txt` mit:

- vollständiger Git-Commit-ID
- Build-Zeit in UTC
- Kennzeichnung `Homepage-v5`

Nach dem Upload ruft der Workflow `https://sv-netzwerk.eu/deploy-version.txt` ab. Das Deployment gilt nur dann als erfolgreich, wenn die dort gelesene Commit-ID exakt `github.sha` entspricht.

## Fachwissensprüfung

Der Build führt vor der statischen Erzeugung den täglichen Fachwissensstandard aus. Ein separater GitHub-Workflow prüft den vorhandenen Tagesbeitrag um 04:00 und 12:00 UTC; fachlich entsprechen diese Läufe am 14.07.2026 den vorgesehenen Prüfzeiten 06:00 und 14:00 Uhr Europe/Berlin. Es findet keine automatische Inhaltserzeugung oder Veröffentlichung statt.
