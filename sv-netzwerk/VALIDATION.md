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
- Main-Checks nach Merge erfolgreich:
  - Build Check (Run `29685401460`)
  - SV-Netzwerk bereitstellen / Deploy (Run `29685401502`)
- IONOS-Liveabgleich erfolgreich:
  - `https://sv-netzwerk.eu/deploy-version.txt` zeigt Commit `5e3610eb267c64c7db082f7e8983520560ecebda`
  - kritische Seiten liefern HTTP 200 (`/termin-vereinbaren/`, `/versicherer/`, `/gutachter-plattform/anfrage/`, `/schaden-melden/`, `/404.html`)
  - PHP-Endpunkte erreichbar (`/anfrage.php`, `/anfrage-versicherer.php`, `/anfrage-gutachter-plattform.php`, `/schadenmeldung.php`)
- Produktions-E2E gegen Form-Endpunkte ausgefuehrt:
  - Kontaktformular POST -> Redirect `?fehler=mail`
  - Schadenmeldung inkl. Datei-Upload POST -> Redirect `?fehler=mail`
  - Ergebnis: serverseitige Verarbeitung/Validierung/Redirect aktiv, aber produktiver Mailversand in Zielumgebung derzeit nicht erfolgreich.

## Recovery-spezifische Ergebnisse
- v7.05-Kernrouten gegen Astro abgeglichen; fehlende Kernrouten wiederhergestellt.
- Kontakt-, Termin-, Versicherer-, Plattform- und Schadenmeldung laufen serverseitig.
- Schadenmeldung besitzt Uploadpfad, Honeypot, Validierung, Logging und Bestaetigungsmail.
- Navigation und Mobile-Menue enthalten nun wieder den direkten Gutachter-Plattform-Einstieg.
- Canonical/OG/JSON-LD werden weiterhin zentral ueber `BaseLayout` + `SEO` ausgespielt.

## Offene Punkte
- Externer Abschlussnachweis fuer Mail/Upload:
  1. SMTP-/mail()-Konfiguration auf IONOS fuer den Webspace pruefen (Sender `info@sv-netzwerk.eu`).
  2. Testformular erneut senden und Eingang bei Zielpostfaechern (`info@sv-netzwerk.eu`, `info@gutachter-plattform.de`) bestaetigen.
  3. Upload-Verzeichnisrechte auf Webspace pruefen und gespeicherte Upload-Datei im Zielpfad verifizieren.
  4. Danach `?gesendet=1` als Soll-Ergebnis fuer Kontakt/Schadenmeldung dokumentieren.
- Search-Console-spezifische Meldungen koennen erst nach erneutem Crawl final geschlossen werden.
