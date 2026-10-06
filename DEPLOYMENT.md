# Deployment – sv-netzwerk.eu

## Verbindliche Produktionsumgebung (Stand 05.10.2026)

- Prüfportal (`/intern/*`) und Importfunktionen laufen auf dem neuen
  IONOS-Server; die öffentliche Website ist davon getrennt.
- `G:` und `/intern` auf alten Laufwerken sind keine Produktionsquelle.
- ClaimsForce-Bridge-Ziele: `https://www.sv-netzwerk.eu/intern/*`. Die Bridge
  wird automatisch aktualisiert; manuelle Neueingaben oder alte `G:`-Pfade sind
  unzulässig.
- Der Workflow muss das tatsächliche IONOS-SFTP-Dokumentenstammverzeichnis
  verwenden; der Pfad wird nicht geraten.
- Nach jedem Deployment muss `/intern/deploy-version.txt` exakt den aktuellen
  Git-Commit ausweisen.
- Erfolg ist erst bestätigt, wenn der aktuelle Commit live per HTTPS gelesen
  wird und Login sowie Dashboard funktionieren. Ein grüner Build genügt nicht.

### Portal-Deployment (`.github/workflows/deploy-portal.yml`)

- Zielverzeichnis ist ausschließlich das Secret `PORTAL_SFTP_REMOTE_DIR`; es gibt
  keine Fallback-Pfade. Vor jeder Übertragung (und vor dem Schreiben der
  `.env`) lädt der Workflow eine zufällige Probedatei nach
  `$PORTAL_SFTP_REMOTE_DIR/intern/` und liest sie per HTTPS unter
  `https://www.sv-netzwerk.eu/intern/` zurück. Nur wenn der Inhalt exakt passt,
  ist die Zuordnung SFTP-Stamm → Domain-Dokumentenstamm bestätigt; sonst bricht
  der Lauf ohne Deployment ab. Der Wert des Secrets muss der reale IONOS-Pfad
  sein und wird nicht geraten.
- Das ClaimsForce-Bridge-Paket
  (`public/intern/downloads/svnet-claimsforce-bridge.zip`) wird bei jedem
  Portal-Deployment aus `sv-netzwerk/browser-extension/claimsforce-bridge/`
  neu erzeugt (Version gegen `manifest.json` geprüft, Ziele
  `https://www.sv-netzwerk.eu/intern/*`, keine `G:`-Pfade). Nach dem Upload
  muss das live ausgelieferte Paket byte-identisch sein.
- Erfolg erst nach: `/intern/deploy-version.txt` = aktueller Commit,
  `/intern/login/` = 200, `/intern/tagescockpit/` ohne Sitzung = 302 zum Login,
  Anmeldung (Secrets `ADMIN_EMAIL`/`ADMIN_PASSWORD`, ein Versuch pro Lauf) und
  danach `/intern/tagescockpit/` = 200, Bridge-Paket live identisch.

### Zugang zum Portal-Server

Das Portal wird auf den neuen IONOS-VPS (`217.160.143.102`) ausgeliefert;
Apache stellt `www.sv-netzwerk.eu` aus `/var/www/sv-netzwerk` bereit (bestätigt
per SSH durch Christian am 05.10.2026). Der Portal-Workflow nutzt ausschließlich
eigene Secrets: `PORTAL_SFTP_HOST`, `PORTAL_SFTP_USERNAME`, `PORTAL_SFTP_PORT`,
`PORTAL_SFTP_REMOTE_DIR` und `PORTAL_SSH_KEY` (SSH-Key-Anmeldung). Die alten
`SFTP_*`-Secrets der öffentlichen Website bleiben unverändert und werden vom
Portal-Workflow nicht verwendet. Die Probedatei prüft vor jedem Deployment, dass
`PORTAL_SFTP_REMOTE_DIR` wirklich unter `https://www.sv-netzwerk.eu/intern/`
ausgeliefert wird.

### Offener Blocker (Stand 05.10.2026)

Die `PORTAL_*`-Secrets müssen im Repository gesetzt sein, bevor der
Portal-Workflow erfolgreich laufen kann.
## Öffentliche Website (`.github/workflows/deploy.yml`)

Stand 06.10.2026: Die auf `fix/website-deploy-vps` erfolgreich geprüfte
Serverzuordnung ist auch im Website-Workflow auf `main` hinterlegt. Beide
Deployments verwenden den neuen IONOS-VPS mit `PORTAL_SFTP_*` und
`PORTAL_SSH_KEY`; ihre Upload-Pakete und Marker bleiben getrennt. Der
Website-Upload schließt `intern/`, `.env` und `.htaccess` weiterhin aus.
Vor dem Upload muss eine zufällige Probedatei aus `PORTAL_SFTP_REMOTE_DIR`
unter der öffentlichen Domain mit identischem Inhalt erreichbar sein.
Nach dem Upload muss `/deploy-version.txt` exakt den Deployment-Commit
ausweisen und der jeweils neueste Fachbeitrag HTTP 200 liefern.

Die kanonische Adresse ist `https://www.sv-netzwerk.eu`; die `.htaccess` leitet
HTTP und den Host ohne `www` auf HTTPS mit `www` um. Der Website-Workflow baut
das Astro-Projekt, überträgt den Website-Anteil von `dist/` (ohne `intern/`) per
SFTP und nutzt das Secret `PORTAL_SFTP_REMOTE_DIR`. Das Portal ist davon getrennt
(eigener Workflow, eigener Marker `/intern/deploy-version.txt`). Der Website-
Workflow prüft die SFTP-Zuordnung per Probedatei; ein Upload allein ist kein Erfolg.

Zugangsdaten kommen ausschließlich aus den GitHub-Actions-Secrets `PORTAL_SFTP_HOST`,
`PORTAL_SFTP_USERNAME`, `PORTAL_SSH_KEY`, `PORTAL_SFTP_PORT`, `PORTAL_SFTP_REMOTE_DIR`. Niemals Werte
in Logs, Quellcode oder Dokumentation schreiben.

## IONOS-Laufzeit

PHP läuft über die globale IONOS-PHP-FPM-Konfiguration. In `.htaccess` keinen
`SetHandler` ergänzen: Das kann PHP-FPM überschreiben und PHP-Quelltext
offenlegen. `.env` und `intern/photos` werden vom Deployment nie überschrieben.
## Fachwissensprüfung

Der Website-Build führt vor der statischen Erzeugung den täglichen
Fachwissensstandard aus. Ein separater GitHub-Workflow prüft den vorhandenen
Tagesbeitrag planmäßig; er erzeugt oder veröffentlicht keine Inhalte
automatisch.
