# Architektur – SV-Netzwerk v5.1

## Renderkette

Astro erzeugt statische Seiten. `BaseLayout.astro` beziehungsweise `HomeLayout.astro` binden zentrale SEO-, Header-, Footer- und Suchkomponenten ein. Content Collections liefern Fachwissen, Praxisfälle, Downloads, Autoren und Kurzvideos.

## Plattformdaten

- `src/data/navigation.ts`: zentrale Hauptnavigation
- `src/data/damage-types.ts`: zwölf Schadenarten mit Prüfpunkten und Querverweisen
- `src/data/experts.ts`: Expertenprofile, Rollen, Regionen und fachliche Verknüpfungen
- `src/content/knowledge/`: Fachbeiträge mit Tagesstandard
- `src/content/practice-cases/`: anonymisierte Praxisfälle
- `src/content/downloads/`: versionierte Arbeitshilfen
- `src/content/videos-library/`: Wissen in 180 Sekunden

## SVOS

SVOS verbindet Schadenmeldung, Erstklassifizierung, technische Bewertung, Expertenzuordnung, Dokumentation, Regulierung und Abschluss. Version 5.1 ist eine öffentliche Core Platform ohne Authentifizierung oder Datei-Backend.

## Suche

Der Suchindex wird beim Build aus Seiten, Fachbeiträgen, Schadenarten, Praxisfällen, Downloads, Videos und Experten erzeugt. Ranking gewichtet exakten Titel, Titelanfang, Kategorie, Tags, Beschreibung und Aktualität.

## Deployment

GitHub Actions → Astro Build → SFTP-Inhalt von `dist/` → IONOS `/sv-netzwerk` → Live-SHA-Prüfung über `deploy-version.txt`.

Architekturentscheidungen: [`docs/adr/`](docs/adr/).
