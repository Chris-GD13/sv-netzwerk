# Quellen- und Rotationsprüfung 16.10.2026

Status: Website `published-verified`; LinkedIn `prepared-not-posted`.

## Tages- und Dublettenprüfung

- Berliner Datum vor Reservierung: 16.10.2026.
- `origin/main`, Tagesregister, CSV-Protokoll und sichtbare Live-Fachwissenübersicht geprüft; vor der Reservierung war kein Artikel für den 16.10.2026 vorhanden.
- Atomare Reservierung: `2026-10-16-sachwertverfahren-immobilien-gebaeudewert-bodenwert-marktanpassung`, Owner `codex-website-20261016-0600`, Commit `5ba6e5e6d53e5a6dcb348c3251f857d06c78fd64`.
- Archivabgleich: kein bestehender Fachartikel mit dem Schwerpunkt Sachwertverfahren, Bodenwert, Sachwertfaktor und Modellkonformität. Die Fenster- und Innentürthemen vom 10./11.10. werden nicht wiederholt.

## Expertenrotation

- Ausgewählte Person: Marc Schütt.
- Aktuelles Live-Profil am 16.10.2026 sichtbar vollständig geprüft und mit `src/data/experts.ts` abgeglichen: öffentlich bestellter und vereidigter Sachverständiger im Tischlerhandwerk; belegte Schwerpunkte unter anderem Wertermittlung nach Sachwertverfahren, Fenster, Türen, Fassaden, Schlagregen- und Luftdichtheitsprüfung sowie Thermografie.
- Themenart: eigenständiges Sachverständigenthema zur Immobilienwertermittlung; kein künstlicher Versicherungsfall.
- Rotationsstand: Carmen Gohl am 12.10., Claudius Freiberg am 13.10., Lenna Maria Walczok am 14.10., Holger Roth am 15.10. und Marc Schütt am 16.10. Christian Wächter bleibt im laufenden Zyklus offen. Kein Profil wurde wiederholt.
- Der Beitrag verlinkt Marc Schütts Profil, behauptet aber weder Autorenschaft noch eine konkrete Fallzuständigkeit.

## Geöffnete Primär- und Originalquellen

Abrufdatum sämtlicher Quellen: 16.10.2026.

1. Amtlicher Volltext der ImmoWertV, Seiten 7 bis 10, 12 bis 18 sowie Anlage 4 im benötigten Umfang: Verfahrenswahl, Reihenfolge, besondere objektspezifische Grundstücksmerkmale, Dateneignung, Modellkonformität, Sachwertfaktoren, Rechenstufen des Sachwertverfahrens, Herstellungskosten, Alterswertminderung und Bodenwert.
2. Amtlicher Text des § 194 BauGB vollständig gelesen: Verkehrswertbegriff, Wertermittlungsstichtag, gewöhnlicher Geschäftsverkehr sowie rechtliche und tatsächliche Grundstücksmerkmale.
3. BMWSB-Seite zur Novellierung des Wertermittlungsrechts im benötigten Umfang gelesen: Zusammenführung der Regelungen in der ImmoWertV 2021 und Integration der NHK 2010 in Anlage 4.
4. Destatis-Seiten zur Umstellung der Baupreisindizes auf Basis 2021 und zu den aktuellen Baupreisindizes im benötigten Umfang gelesen: Basiswechsel, Verkettung und sachgerechte Auswahl des zur Gebäudeart passenden Index.
5. Live-Expertenprofil Marc Schütt sichtbar gelesen und mit dem Repository abgeglichen.

## Aussagegrenzen

- Keine individuelle Verkehrswertermittlung, keine Rechts- oder Steuerberatung.
- Keine erfundenen Bewertungsfälle, Grundstücksmerkmale, Flächen, Faktoren oder Eurobeträge.
- Kein Norminhalt aus kostenpflichtigen oder nicht eingesehenen Quellen.
- Sachwert ist nicht ohne Marktanpassung mit Verkehrswert gleichgesetzt.
- Regionale Faktoren, Bodenrichtwerte und Sachwertfaktoren müssen zum Stichtag, Teilmarkt und verwendeten Modell passen; es wurden keine pauschalen Werte behauptet.
- Kein öffentliches Beitragsbild: Ein fachlich passendes, geprüftes und freigegebenes Motiv lag nicht vor; keine SVG- oder Titelkarten-Platzhalter.

## Technischer und öffentlicher Nachweis

- Inhaltscommit: `74a1880f06fa82b9a76eded3ebee73ba9db7f986`.
- Erfolgreicher Produktionslauf: <https://github.com/Chris-GD13/sv-netzwerk/actions/runs/38849479289>; Build Check, Portal Tests und Prüfportal-Deployment desselben Commits ebenfalls erfolgreich.
- Lokale Prüfkette: `npm ci`, Fachbeitrags-Preflight, Encoding- und Knowledge-Validierung, `npm run check` mit 0 Fehlern/0 Warnungen/0 Hinweisen, vollständiger `npm run build` sowie SEO-, Sitemap- und interne Linkprüfung bestanden. Der unter Windows nicht funktionsfähige Store-Alias `python3` wurde für dieselbe Buildkette durch den gebündelten Codex-Python-Pfad ersetzt.
- Die zusätzliche HTML-Validierung deckte drei gemeinsame Templatefehler auf. Artikel-Schlagwörter verwenden deshalb keinen zweiten `footer`-Landmark mehr; der Cookie-Dialog ist kein funktionsloses Formular mehr; die Statistikoption hat ein valides beschriftetes Kontrollfeld. Die Prüfung wurde nach der Korrektur erneut ausgeführt.
- Live sichtbar geprüft: vollständige kanonische Detailseite mit redaktioneller Organisationszeile, Abrufdatum und Quellen; Fachwissenübersicht; Kategorie `Wertermittlung und Immobilienbewertung`; Schlagwort `Sachwertverfahren`; verlinktes Profil Marc Schütt.
- HTTP 200 geprüft: Detail, Übersicht, Kategorie, Schlagwort, `sitemap-index.xml`, `sitemap-0.xml`, `robots.txt` und `deploy-version.txt`. Die Sitemap enthält den Slug, Canonical ist exakt, `noindex` fehlt wie vorgesehen und der Marker wies beim initialen Livegang exakt den Inhaltscommit aus.
- Tagesregister anschließend atomar auf `published-verified` gesetzt; LinkedIn blieb `not-posted` und der Begleittext nur vorbereitet.
