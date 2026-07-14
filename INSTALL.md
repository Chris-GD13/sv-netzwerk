# Installation – SV-Netzwerk v1.4.1 CI & Stabilisierung

1. Laufende, festhängende GitHub-Actions-Läufe bei Bedarf abbrechen.
2. ZIP entpacken.
3. Den vollständigen Inhalt des enthaltenen Ordners in den lokalen Repository-Stamm `...\GitHub\sv-netzwerk\` kopieren.
4. Vorhandene Dateien ersetzen.
5. Den lokalen Ordner `.git` nicht löschen oder ersetzen; er ist im Paket bewusst nicht enthalten.
6. GitHub Desktop öffnen und die Änderungen prüfen.
7. Commit-Nachricht verwenden: `SV-Netzwerk v1.4.1 CI & Stabilisierung`.
8. Commit und Push ausführen.
9. GitHub Actions `Build Check` und `SV-Netzwerk bereitstellen` kontrollieren.

## Erwarteter Ablauf

1. Checkout
2. Node.js 22 LTS
3. Umgebungsdiagnose
4. `npm ci`
5. `npm run build`
6. Prüfung der `dist`-Ausgabe
7. SFTP-Deployment zu IONOS

## Lokale Prüfung

```bash
cd sv-netzwerk
npm ci
npm run build
```
