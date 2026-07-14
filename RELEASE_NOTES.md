# Release Notes – SV-Netzwerk v1.4.1 CI & Stabilisierung

Dieses Wartungsrelease stabilisiert die GitHub-Actions-Pipeline der Version 1.4 und behebt die blockierte Abhängigkeitsinstallation.

## Wesentliche Änderungen

- Node.js in Build und Deployment auf die LTS-Hauptversion 22 umgestellt
- npm-Cache anhand von `sv-netzwerk/package-lock.json` aktiviert
- öffentliche npm-Registry für CI ausdrücklich festgelegt
- interne, außerhalb der Erstellungsumgebung nicht erreichbare Registry-URLs aus `package-lock.json` entfernt
- reproduzierbare Installation mit `npm ci --no-audit --no-fund`
- Diagnoseausgabe für Runner, Arbeitsverzeichnis, Node, npm und Registry ergänzt
- Zeitbegrenzung für Build- und Deploy-Jobs ergänzt
- Concurrency-Regel verhindert parallel festhängende Deployments
- SFTP-Uploadpfad auf `sv-netzwerk/dist/` eindeutig auf den Repository-Stamm bezogen
- Deployment erfolgt ausschließlich nach erfolgreichem Astro-Build und validierter `dist`-Ausgabe

## Unverändert

- Website-Design und Inhalte aus v1.4
- IONOS-Zielverzeichnis und vorhandene GitHub-Secrets
- Astro-Seiten- und Komponentenarchitektur
