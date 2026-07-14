# Validierung – SV-Netzwerk v5.1.2

Stand: 14.07.2026

## Installation und Build

- `npm ci`: erfolgreich
- installierte Pakete: 279
- gemeldete Schwachstellen: 0
- `npm run build`: erfolgreich
- Astro-/TypeScript-Prüfung: 120 Dateien, 0 Fehler, 0 Warnungen, 0 Hinweise
- statischer Build: 131 Seiten
- `dist/deploy-version.txt`: vorhanden
- kein Unterordner `dist/dist/`

## Fachwissens-Pflichtlauf

- 12.07.2026: Kategorie C, 347 Wörter, „Kontrollierter Rückbau bei Leitungswasserschäden“
- 13.07.2026: Kategorie B, 816 Wörter, „Technische Dokumentation bei komplexen Gebäudeschäden“
- 14.07.2026: Kategorie A, 1.527 Wörter, „Technische Schadenabgrenzung als Grundlage der Regulierung“
- `npm run validate:knowledge`: erfolgreich
- `npm run validate:knowledge -- --dist`: erfolgreich
- Build-Routen, Suchindex, Sitemap und interne Links: erfolgreich geprüft

## Erzeugte Kernbereiche

- `/experten/` mit sechs fachlichen Experten, zwei Backoffice-Profilen und acht dynamischen Detailseiten
- `/datenschutzerklaerung/`, `/impressum/`, `/versicherer/`, `/gutachter-plattform/`, `/komplexschaeden/` und `/grossschadenregulierung/` im zentralen Astro-Layout
- `/svos/` mit Modulen, Prozessmodell und Roadmap
- `/schaden-melden/` mit achtstufiger lokaler Erfassung
- `/schadenarten/` mit zwölf datengetriebenen Schadenarten und Detailseiten
- `/wissen-in-180-sekunden/` mit drei Beiträgen und Detailseiten
- `/fachwissen/` mit dynamischen Detailseiten für den täglichen Pflichtlauf
- `/praxisfaelle/` mit Schadenart-, Objekt-, Bereichs-, Regions- und Schadenhöhenfilter
- `/downloads/` mit Kategorie- und Formatfilter
- `/suche/` und `/search-index.json` mit Artikeln, Downloads, Videos, Seiten, Schadenarten, Fällen und Experten

## Deployment-Prüfung

Der Workflow verwendet ausschließlich `/sv-netzwerk` als IONOS-Document-Root, überträgt den Inhalt von `dist/` direkt dorthin, verhindert `/sv-netzwerk/dist/` und vergleicht die live gelesene Commit-ID aus `deploy-version.txt` mit `github.sha`.

GitHub-Actions-Prüfung und Live-Verifikation erfolgen anhand des veröffentlichten Commits.

## Visuelle und interaktive Prüfung

- Desktop 1440 × 1000: zentrale Navigation einschließlich „Experten“ sichtbar, kein horizontaler Überlauf
- Mobil 390 × 844: Hamburger sichtbar, Off-Canvas-Menü ohne horizontalen Überlauf
- Fokus beim Öffnen auf „Menü schließen“ geführt
- Scroll-Lock im geöffneten Zustand aktiv
- ESC schließt das Menü und führt den Fokus zu „Menü öffnen“ zurück
- keine Browser-Konsolenfehler oder -warnungen
- Desktop- und Mobile-Screenshot erzeugt
- alle acht Portraitbilder lokal ausgeliefert und erfolgreich geladen
