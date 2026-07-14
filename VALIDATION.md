# Validation – SV-Netzwerk v1.4.1

- [x] vollständiger Original-Repository-Stand als Grundlage verwendet
- [x] `package.json` und `package-lock.json` strukturell konsistent
- [x] keine internen OpenAI-/CAAS-Registry-URLs mehr in `package-lock.json`
- [x] Node.js 22 LTS in Build Check und Deployment
- [x] npm-Cache mit korrektem Lockfile-Pfad eingerichtet
- [x] öffentliche npm-Registry in beiden Workflows ausdrücklich festgelegt
- [x] CI-Diagnose, Concurrency und Job-Timeouts eingerichtet
- [x] SFTP-Quellpfad repositoryweit eindeutig auf `sv-netzwerk/dist/` gesetzt
- [x] Workflow-YAML syntaktisch eingelesen
- [x] JSON-Dateien syntaktisch eingelesen
- [x] Astro-Buildbefehl und `dist`-Validierung in beiden Workflows vorhanden

## Umgebungsbedingter Hinweis

Die Laufzeitumgebung für die Paketerstellung besitzt keinen direkten DNS-Zugriff auf `registry.npmjs.org`. Ein erneuter Download sämtlicher npm-Pakete konnte deshalb hier nicht abgeschlossen werden. Der bestehende Windows-Abhängigkeitsstand kann unter Linux wegen plattformspezifischer nativer Bindings nicht als Ersatz verwendet werden. Die eigentliche GitHub-Actions-Ausführung erfolgt dagegen auf `ubuntu-latest` mit öffentlicher npm-Registry und erzeugt die Abhängigkeiten dort reproduzierbar aus dem bereinigten Lockfile.
