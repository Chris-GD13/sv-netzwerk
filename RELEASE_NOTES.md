# Release Notes – SV-Netzwerk v1.2 Quality & CI

## Zweck

Dieses Release stabilisiert die Qualitätsprüfung im Repository, ohne den bestehenden produktiven IONOS-Deploy zu verändern.

## Änderungen

- automatischer Astro-Build bei Änderungen am Website-Projekt
- manuell startbare Linkprüfung
- manuell startbare HTML-Prüfung
- manuell startbare Lighthouse-Prüfung
- vorherige unvollständige Platzhalter-Workflows werden durch vollständige Workflow-Dateien ersetzt
- Dependabot-Konfiguration für npm im Unterordner `sv-netzwerk`
- dokumentierte Installation, Prüfung und Rücknahme

## Automatische Workflows

Nur `Build Check` läuft automatisch bei relevanten Änderungen.

Die Prüfungen `Link Checker`, `HTML Validator` und `Lighthouse` werden bewusst manuell gestartet. Dadurch entstehen bei normalen Inhalts-Commits keine roten Actions durch externe Links, HTML-Bestandsfehler oder Performance-Schwankungen.

## Unverändert

- `.github/workflows/deploy.yml`
- SFTP-Secrets
- IONOS-Zielverzeichnis
- Website-Inhalte
