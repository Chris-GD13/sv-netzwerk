# ADR-003: Zentrale Navigation

- Status: akzeptiert
- Datum: 14.07.2026

## Entscheidung

Header, Desktop-Navigation, Mega-Menüs, Mobile-Menü und Footer werden als gemeinsame Astro-Komponenten gerendert. Eine zentrale Datenquelle definiert die Hauptnavigation.

## Begründung

Doppelte Homepage- und Unterseiten-Navigationen führten zu abweichenden Inhalten und unsichtbaren Änderungen.

## Konsequenzen

Neue Hauptbereiche werden einmal in `src/data/navigation.ts` eingetragen. Mobile und Desktop nutzen dieselben Ziele, aktive Markierungen und Beschreibungen.
