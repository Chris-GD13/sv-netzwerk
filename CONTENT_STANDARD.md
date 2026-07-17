# Täglicher Fachwissensstandard

## Umfang nach Wochentag (aktiver Rhythmus)

| Tag | Kategorie | Wortumfang |
|---|---:|---:|
| Montag, Freitag | B | 800–1.200 |

## Pflichtbestandteile

Überschrift, SEO-Titel, Meta-Description, eindeutiger Slug, Kategorie, Tags, Teaser, vollständiger Fachtext, konkreter und nicht erfundener Praxisbezug, fachliche Bewertung, Fazit, CTA, interne Links, Autor, Veröffentlichungs- und Änderungsdatum, LinkedIn-Kurzfassung sowie Video-Skript für Wissen in 180 Sekunden.

Normen, Urteile, Messwerte, Schadenfälle und Zahlen werden nur genannt, wenn sie belastbar belegt sind. Methodische Beispiele müssen als solche erkennbar sein. Veröffentlichte Praxisfälle bleiben anonymisiert.

## Pflichtläufe

- 06:00 Uhr Europe/Berlin an Montag und Freitag: Planungs-, Dubletten- und Vollständigkeitsprüfung inklusive Build
- Nach erfolgreichem Lauf: Commit + Push auf `main`, anschließend Deployment per Push-Trigger

Die GitHub-Zeitpläne sind UTC-basiert und über Sommer-/Winterzeit abgesichert. Andere zeitgesteuerte Content-Läufe bleiben deaktiviert.

## Befehle

```text
npm run validate:knowledge
npm run build
npm run validate:knowledge -- --dist
```

Die zweite Prüfung bestätigt zusätzlich Route, Suchindex, Sitemap und interne Build-Ziele.
