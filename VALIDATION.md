# Validierung – SV-Netzwerk v5.0.1

## Installation und Build

- `npm ci`: erfolgreich
- installierte Pakete: 279
- gemeldete Schwachstellen: 0
- `npm run build`: erfolgreich
- Astro-Prüfung: 98 Dateien, 0 Fehler, 0 Warnungen, 0 Hinweise
- statischer Build: 85 Seiten
- `dist/deploy-version.txt`: vorhanden, mit Git-Commit, UTC-Buildzeit und `Homepage-v5`
- kein Unterordner `dist/dist/`

## Erzeugte Pflichtseiten

- `/leistungen/`
- `/schadenarten/`
- `/fachwissen/`
- `/praxisfaelle/`
- `/downloads/`
- `/netzwerk/`
- `/svos/`
- `/ueber-uns/`
- `/kontakt/`

## Interaktionsprüfung

Desktop bei 1440 × 1000 Pixel:

- alle neun Hauptnavigationspunkte sichtbar
- Mega-Menü „Leistungen“ öffnet und schließt korrekt
- `aria-expanded` wechselt zwischen `false` und `true`
- ESC schließt das Mega-Menü und führt den Fokus zum Auslöser zurück

Mobil bei 390 × 844 Pixel:

- Hamburger-Schaltfläche sichtbar
- Off-Canvas-Menü öffnet mit `aria-hidden="false"`
- Scroll-Lock über `html.has-open-menu` aktiv
- Fokus wird auf die Schließen-Schaltfläche geführt
- ESC schließt das Menü mit `aria-hidden="true"`
- Overlay- und Link-Schließen sind verdrahtet
- Untermenüs sind als native, tastaturbedienbare `details`-Elemente umgesetzt

## Visuelle Prüfung

- Desktop-Screenshot der gebauten Startseite erstellt
- Mobile-Screenshot mit geöffnetem Off-Canvas-Menü erstellt
- keine Browser-Konsolenfehler festgestellt

## Deployment-Prüfung

Der Workflow verwendet ausschließlich `/sv-netzwerk` als IONOS-Document-Root, überträgt den Inhalt von `dist/` direkt dorthin, verhindert `/sv-netzwerk/dist/` und vergleicht die live gelesene Commit-ID aus `deploy-version.txt` mit `github.sha`.
