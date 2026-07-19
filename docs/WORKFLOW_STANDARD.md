# Workflow-Standard

## 1. Synchronisieren und analysieren

`origin/main` wird per Fast-Forward synchronisiert. Anschließend werden Renderkette, Datenmodelle, betroffene Routen, Workflows und vorhandene Änderungen geprüft.

## 2. Umsetzen

Änderungen erfolgen direkt im Repository. Gemeinsame Typen und Datenquellen werden bevorzugt. Unveröffentlichte Backendfunktionen werden als technischer Status klar ausgewiesen.

## 3. Fachbeitrag automatisieren

Die produktive Automation erzeugt täglich zwei Fachbeiträge im Master-Repository:

1. 05:15-06:40 Uhr Europe/Berlin
2. 16:15-17:30 Uhr Europe/Berlin

GitHub-Crons laufen in UTC; Sommer-/Winterzeit wird über duale UTC-Zeitpläne plus Laufzeitprüfung in `Europe/Berlin` abgesichert. LinkedIn wird erst nach erfolgreicher Live-URL-Prüfung ausgelöst.

## 4. Lokal validieren

1. `npm ci`
2. `npm run validate:knowledge`
3. `npm run build`
4. `npm run validate:knowledge -- --dist`
5. Desktop- und Mobile-Prüfung einschließlich Navigation und Konsole
6. `git diff --check`, `git diff --stat` und Dateiliste

## 5. Veröffentlichen

Nach Commit erfolgt Push nach `origin/main`. Der Workflow prüft Build, Link- und Integrationsstatus, validiert die Live-URL des neuen Beitrags und übergibt erst danach den Zap-kompatiblen LinkedIn-Datensatz.
