# Analysebereich 1: v7.05-Referenz vs. aktueller Astro-Stand

Stand: 2026-07-19

## Quellenbasis
- Verbindliche Referenzinhalte im Repository (v7.05): `index.html`, Ordner-Routen mit `index.html`, `anfrage.php`, `anfrage-versicherer.php`, `anfrage-gutachter-plattform.php`, `PROJEKTSTATUS_v7.05.md`
- Aktueller Stand: Astro-Quellstruktur unter `src/**`, Deployment über `dist/` (GitHub Actions `build-check.yml`, `deploy.yml`)
- Git-Historie ausgewertet (`git log --name-status`): Altversion als Upload am 2026-07-11 (`cad5d8d`, `fbb4913`), Astro-Neuaufbau ab 2026-07-14

## Technische Ist-Basis (Astro)

| Komponente | Stand |
| --- | --- |
| Astro | 7.0.9 |
| @astrojs/check | 0.9.9 |
| @astrojs/sitemap | 3.7.3 |
| TypeScript | 6.0.3 |
| Deployment-Ziel | `dist/` wird 1:1 nach `/sv-netzwerk/` gespiegelt |

## Funktionsmatrix (v7.05 vs. aktuell)

| Bereich | v7.05 Referenz | Aktueller Astro-Stand | Bewertung |
| --- | --- | --- | --- |
| Kernrouten (Start, Leistungen, Versicherer, Kontakt, Gutachter-Plattform) | vorhanden | vorhanden | teilweise gleichwertig |
| Unterseiten Gutachter-Plattform (`/anfrage/`, `/demo-buchen/`, `/fuer-*`) | vorhanden | weitgehend fehlend | **Regression (kritisch)** |
| Terminvereinbarung `/termin-vereinbaren/` | vorhanden | fehlt | **Regression (kritisch)** |
| `kompetenzzentrum`, `medienbibliothek`, `recht-compliance`, `seminare`, `versicherungen`, `wissen` | vorhanden | fehlen | **Regression** |
| Kontakt-/Termin-/Versicherer-/Plattform-Formulare (POST auf PHP) | produktiv serverseitig | überwiegend entfernt oder browser-only | **Regression (kritisch)** |
| Schadenmeldung produktiv | serverseitig im Altstand über Kontaktpfade | derzeit nur Browser-Wizard + mailto/tel | **Regression (kritisch)** |
| Navigation/Führung mit Versicherer- und Plattform-Einstiegen | klar vorhanden | teils vorhanden, teils Zielseiten fehlen | **Regression** |
| SEO/Structured Data | basis vorhanden (meta/og/canonical je Seite) | in Astro vorhanden, aber wegen fehlender Zielseiten inkonsistent | teilweise regressiv |
| Deployment-Datei `deploy-version.txt` | vorhanden | vorhanden (Script in prebuild) | ok |

## Routendifferenz (automatisierter Vergleich)

### In v7.05 vorhanden, in Astro fehlend
- `/kompetenzzentrum/`
- `/medienbibliothek/`
- `/recht-compliance/`
- `/seminare/`
- `/termin-vereinbaren/`
- `/versicherungen/`
- `/wissen/`

### In Astro neu (nicht Teil des v7.05-Kernumfangs)
- `/svos/**`, `/schadenarten/**`, `/praxisfaelle/**`, dynamische Fachwissen-Routen

## Git-Historie: zentrale Befunde
- 2026-07-11: v7.05-Inhalte inkl. produktiver Form-PHP-Endpunkte hinzugefügt (`cad5d8d`, `fbb4913`)
- 2026-07-14 ff.: Astro-Neuaufbau in mehreren Iterationen; Fokus auf UI/Content, jedoch Wegfall mehrerer produktiver Altfunktionen (insb. Formularpfade und Zielseiten)
- 2026-07-16: weitere Funktionskürzung (z. B. Downloads entfernt, Commit `683b360`)

## Priorisierte Regressionen für Recovery
1. **P0**: Serverseitige Formularstrecken (Kontakt, Termin, Versicherer, Gutachter-Plattform, Schadenmeldung) wieder produktiv herstellen.
2. **P0**: Fehlende Kern-Zielseiten (`/termin-vereinbaren/`, Gutachter-Plattform-Unterseiten) wieder bereitstellen.
3. **P1**: Navigations-/Footer-Ziele mit real existierenden Seiten konsolidieren.
4. **P1**: SEO-Konsistenz nach Seitenwiederherstellung (Canonicals, interne Links, Sitemap, Structured Data) nachziehen.
5. **P1**: Vollständige End-to-End-Prüfung der Hauptnutzerpfade inkl. Deployment-Artefakte.

## Baseline-Buildbefund
- `npm run build` bricht aktuell bereits im bestehenden `validate:knowledge`-Schritt ab (mehrere Pflichtbeiträge für 2026-07-17). Dieser Befund ist vor der finalen Abnahme zu bereinigen.
