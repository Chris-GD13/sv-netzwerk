# Release Notes – SV-Netzwerk v1.5.1 Fachwissensbibliothek

## Zweck

Dieses Release erweitert die in v1.5 vorbereitete Wissensstruktur zu einer statisch generierten, SEO-fähigen Fachwissensbibliothek.

## Enthalten

- eigenständige Astro-Übersichtsseite für `/fachwissen/`
- Kategorieseiten für sämtliche Fachgebiete
- Tag-Seiten für alle Schlagwörter
- einheitliches Kartenlayout mit Kategorie-, Typ-, Datums- und Tag-Angaben
- serverseitig erzeugte Pagination mit sechs Einträgen pro Seite
- clientseitige Suche innerhalb der aktuell dargestellten Ergebnisse
- alphabetische A–Z-Navigation und separates Gesamtverzeichnis
- kanonische, sprechende und statisch generierte URLs
- erweiterter Datenbestand für bestehende Fachbeiträge
- responsive und barrierearme Filter- und Navigationskomponenten

## Kompatibilität

Die vorhandenen Fachbeiträge und statischen Seiten bleiben erhalten. Neue Astro-Seiten ergänzen die bestehende Struktur und werden beim Build in das produktive `dist`-Verzeichnis übernommen.
