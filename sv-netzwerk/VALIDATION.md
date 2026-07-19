# VALIDATION

Stand: 2026-07-19

## Durchgefuehrte Pruefungen
- `npm run check` erfolgreich (Astro/TypeScript Diagnostik ohne Fehler)
- `npm run build` erfolgreich inkl.:
  - `validate:knowledge`
  - `astro check`
  - `astro build`
  - `validate:public-copy`
- Dist-Pruefung:
  - `404.html`, `robots.txt`, `sitemap-index.xml`, `deploy-version.txt` vorhanden
  - serverseitige Formular-Handler in `dist/` vorhanden (`anfrage*.php`, `schadenmeldung.php`, `form-handler-core.php`)
- Interner Linkscan auf Build-Ausgabe: keine toten internen Links in HTML-Zielen
- PR-Checks auf `chris-gd13-website-recovery-masterprojekt` erfolgreich:
  - Build Check (GitHub Actions)
  - Fachbeitrags-Automation (GitHub Actions)

## Recovery-spezifische Ergebnisse
- v7.05-Kernrouten gegen Astro abgeglichen; fehlende Kernrouten wiederhergestellt.
- Kontakt-, Termin-, Versicherer-, Plattform- und Schadenmeldung laufen serverseitig.
- Schadenmeldung besitzt Uploadpfad, Honeypot, Validierung, Logging und Bestaetigungsmail.
- Navigation und Mobile-Menue enthalten nun wieder den direkten Gutachter-Plattform-Einstieg.
- Canonical/OG/JSON-LD werden weiterhin zentral ueber `BaseLayout` + `SEO` ausgespielt.

## Offene Punkte
- Echte produktive Mailzustellung und Upload-Schreibrechte sind in der Zielumgebung (IONOS) nach Deployment live zu verifizieren.
- Search-Console-spezifische Meldungen koennen erst nach erneutem Crawl final geschlossen werden.
- Produktives Deploy-Resultat des Workflows `SV-Netzwerk bereitstellen` folgt nach Merge auf `main`.
