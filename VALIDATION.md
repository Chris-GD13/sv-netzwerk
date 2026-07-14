# Validierung – v1.6.2

## Strukturelle Prüfung
- Suchmodul von UI und Datenquelle getrennt
- gemeinsame Typen für Index und Treffer vorhanden
- bestehende Suchschnittstelle kompatibel gehalten
- statischer JSON-Endpunkt mit Facetten erweitert

## Technische Prüfung
Der Produktionsbuild wird mit `npm ci` und `npm run build` geprüft. Das Ergebnis ist zusätzlich in `VALIDATION.txt` dokumentiert.

## Ergebnis
- `npm ci --no-audit --no-fund`: erfolgreich
- `astro check`: 0 Fehler, 0 Warnungen, 0 Hinweise
- `astro build`: erfolgreich
- 75 statische Seiten einschließlich `/search-index.json` erzeugt
