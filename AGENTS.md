# Repository guidance

## Verbindliche Produktionsumgebung (Vorgabe von Christian, 05.10.2026)

- Das Prüfportal (`/intern/*`) und die Importfunktionen laufen auf dem **neuen
  IONOS-Server**. Die öffentliche Website ist davon getrennt.
- `G:` und `/intern` auf alten Laufwerken sind **nie** Produktionsquelle.
- ClaimsForce-Bridge-Ziele bleiben `https://www.sv-netzwerk.eu/intern/*`. Die
  Bridge wird automatisch aktualisiert; keine manuellen Neueingaben, keine alten
  `G:`-Pfade.
- Der Workflow muss das **tatsächliche** IONOS-SFTP-Dokumentenstammverzeichnis
  verwenden. Pfade nie raten oder aus Annahmen ableiten; bei Unklarheit beim
  Product Owner nachfragen.
- Nach jedem Deployment muss `https://www.sv-netzwerk.eu/intern/deploy-version.txt`
  exakt den aktuellen Git-Commit ausweisen (kein `local-build`).
- Ein grüner Build ist kein Erfolg. Erfolg gilt erst, wenn der aktuelle Commit
  live per HTTPS gelesen wird und Login sowie Dashboard funktionieren.
- Dashboard: `https://www.sv-netzwerk.eu/intern/tagescockpit/`. Bridge-Quelle:
  `sv-netzwerk/browser-extension/claimsforce-bridge/`; Portal-Skript:
  `sv-netzwerk/public/intern/claimsforce-central.js`. Das Bridge-Paket wird in
  CI aus dem Quellcode erzeugt, nie von Hand ersetzt.
- `SFTP_REMOTE_DIR` kommt nur aus dem Secret und wird per Probedatei gegen die
  Domain verifiziert (siehe `DEPLOYMENT.md`); keine Fallback-Pfade einbauen.

## Allgemein

Vor jeder Veröffentlichung `DEPLOYMENT.md` und die Workflows
`.github/workflows/deploy-portal.yml` (Portal) bzw. `deploy.yml` (Website)
lesen. Produktion läuft bei IONOS, nicht auf GitHub Pages; Deployment per
GitHub Actions und SFTP. Kein verschachteltes `dist/` im Zielverzeichnis.

SFTP-Abgleiche können Dateien überschreiben oder löschen: vor Änderungen
an Upload-Inhalt oder Zielpfad prüfen, dass Laufzeitdateien (z. B.
`.env`, `intern/photos`) unangetastet bleiben. SFTP-Zugangsdaten bleiben in
GitHub-Actions-Secrets und gelangen nie ins Repository. PHP läuft über IONOS
PHP-FPM; kein `SetHandler` in `.htaccess` ergänzen.
