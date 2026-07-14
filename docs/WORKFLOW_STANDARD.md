# Workflow-Standard

## 1. Synchronisieren und analysieren

`origin/main` wird per Fast-Forward synchronisiert. Anschließend werden Renderkette, Datenmodelle, betroffene Routen, Workflows und vorhandene Änderungen geprüft.

## 2. Umsetzen

Änderungen erfolgen direkt im Repository. Gemeinsame Typen und Datenquellen werden bevorzugt. Unveröffentlichte Backendfunktionen werden als technischer Status klar ausgewiesen.

## 3. Fachwissen prüfen

Der 06:00-Uhr-Lauf prüft, ob der Tagesbeitrag vollständig geplant und im Repository vorhanden ist. Der 14:00-Uhr-Lauf prüft erneut den veröffentlichungsfähigen Stand. GitHub-Zeitpläne laufen in UTC; die fachlich maßgebliche Zone ist Europe/Berlin. Die Automatisierung erzeugt und veröffentlicht keine Inhalte.

## 4. Lokal validieren

1. `npm ci`
2. `npm run validate:knowledge`
3. `npm run build`
4. `npm run validate:knowledge -- --dist`
5. Desktop- und Mobile-Prüfung einschließlich Navigation und Konsole
6. `git diff --check`, `git diff --stat` und Dateiliste

## 5. Veröffentlichen

Nach dem vorgegebenen Commit wird nach `origin/main` gepusht. Build Check und Deployment werden vollständig abgewartet. Abschluss erfolgt erst, wenn `https://sv-netzwerk.eu/deploy-version.txt` exakt die neue Commit-ID ausweist und zentrale Routen erreichbar sind.
