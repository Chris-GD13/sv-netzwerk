# Quellen- und Rotationsprüfung 17.10.2026

Status: Website `published-verified`; LinkedIn `prepared-not-posted`.

## Tages- und Dublettenprüfung

- Berliner Datum vor Reservierung: 17.10.2026.
- `origin/main`, Tagesregister, CSV-Protokoll und sichtbare Live-Fachwissenübersicht geprüft; vor der Reservierung war kein Artikel für den 17.10.2026 vorhanden.
- Atomare Reservierung: `2026-10-17-drohnen-gebaeudeschaden-einsatzgrenzen-bildbefund-dokumentation`, Owner `codex-website-20261017-0600`, Commit `a843295843942bb9e8fa24eab5d0ac245d42d5dc`.
- Archivabgleich: kein bestehender Fachartikel mit dem Schwerpunkt rechtssicherer Drohneneinsatz, Bildbefund und Messgrenzen bei Gebäudeschäden. Der vorhandene Beitrag zur allgemeinen Fotodokumentation wird vertieft, nicht dupliziert.

## Expertenrotation

- Ausgewählte Person: Christian Wächter.
- Aktuelles Live-Profil am 17.10.2026 sichtbar vollständig geprüft und mit `src/data/experts.ts` abgeglichen: Sachverständiger mit belegten Schwerpunkten unter anderem Komplex- und Großschäden, Bauforensik, Thermografie, Drohnen sowie Fenster, Türen und Fassaden.
- Themenart: eigenständiges Sachverständigenthema zur drohnengestützten Gebäudedokumentation; kein künstlicher Versicherungsfall.
- Rotationsstand des aktuellen Zyklus: Carmen Gohl 12.10., Claudius Freiberg 13.10., Lenna Maria Walczok 14.10., Holger Roth 15.10., Marc Schütt 16.10. und Christian Wächter 17.10. Damit sind alle aktuell öffentlich als Sachverständige geführten Profile im Zyklus berücksichtigt; Backoffice-Profile wurden nicht als Sachverständigenprofile umgedeutet.
- Der Beitrag verlinkt Christian Wächters Profil, behauptet aber weder Autorenschaft noch eine konkrete Fallzuständigkeit.

## Geöffnete Primär- und Originalquellen

Abrufdatum sämtlicher Quellen: 17.10.2026.

1. Konsolidierter Text der Durchführungsverordnung (EU) 2019/947 im benötigten Umfang: Betriebskategorien, offene Kategorie, Sichtverbindung, Abstand und Höhenbegrenzung.
2. EASA Easy Access Rules for Unmanned Aircraft Systems, Revision Juni 2026, im benötigten Umfang: A1/A2/A3, Verantwortlichkeiten, Flugvorbereitung, geografische Gebiete und Betriebsgrenzen.
3. DIPUL „Schnell und sicher in die Luft“, Version 1.2, vollständig: Registrierung, e-ID, Kompetenznachweis, Haftpflichtversicherung und Kartenanwendung für geografische Gebiete.
4. Amtliche Einzelnormen § 21h LuftVO und § 43 LuftVG vollständig: besondere Bedingungen in geografischen Gebieten und Haftpflichtversicherung.
5. DSK-Positionspapier zur Nutzung von Kameradrohnen durch nicht-öffentliche Stellen vollständig: Rechtsgrundlage, Interessenabwägung, Informationspflichten und Rechte Dritter.
6. Murtiyoso et al., ISPRS Annals 2017, im benötigten Umfang: Kalibrierung, Flugplanung, Aufnahmegeometrie, Datenauswertung und Qualitätskontrolle bei Nahbereichs-UAV-Photogrammetrie.
7. Live-Expertenprofil Christian Wächter sichtbar gelesen und mit dem Repository abgeglichen.

## Aussagegrenzen

- Keine individuelle Flugfreigabe, Rechtsberatung, Datenschutzfreigabe oder Beurteilung eines konkreten Gebäudeschadens.
- Keine erfundenen Flüge, Fälle, Messwerte, Genauigkeiten, Flächen oder Kosten.
- Keine Behauptung, dass eine Eigentümerzustimmung allein jeden Einsatz zulässig macht.
- Keine Gleichsetzung eines Luftbilds mit Ursachenbeweis, vollständiger Bauteilprüfung oder präziser Messung.
- Photogrammetrische Messbarkeit nur bei dokumentierter Aufnahme-, Referenzierungs-, Auswertungs- und Kontrollkette.
- Kein öffentliches Beitragsbild: Ein fachlich passendes, geprüftes und freigegebenes Motiv lag nicht vor; keine SVG- oder Titelkarten-Platzhalter.

## Technischer und öffentlicher Nachweis

- Inhaltscommit: `79bb447e3569b8f26c843fba8b36f748a864fd6c`.
- Erfolgreicher Produktionslauf: <https://github.com/Chris-GD13/sv-netzwerk/actions/runs/39001969280>; Build Check <https://github.com/Chris-GD13/sv-netzwerk/actions/runs/39001969317> und Portal Tests <https://github.com/Chris-GD13/sv-netzwerk/actions/runs/39001969307> desselben Commits ebenfalls erfolgreich.
- Lokale Prüfkette: `npm ci`, Fachbeitrags-Preflight, Encoding- und Knowledge-Validierung, `npm run check` mit 0 Fehlern/0 Warnungen/0 Hinweisen, vollständiger `npm run build`, SEO-, Sitemap- und interne Linkprüfung bestanden. Der unter Windows nicht funktionsfähige Store-Alias `python3` wurde für dieselbe Buildkette durch den gebündelten Codex-Python-Pfad ersetzt.
- Zusätzliche HTML-Validierung von Artikel, Übersicht, Kategorie und Schlagwort bestanden. Dabei wurde ein bestehender ungültiger Prozentwert im HTML-`width`-Attribut des Podcast-Players standardskonform in CSS verlagert und die Prüfung wiederholt.
- Live sichtbar geprüft: vollständige kanonische Detailseite mit redaktioneller Organisationszeile, Datum, Quellen und verlinktem Profil Christian Wächter; cachefreie Fachwissenübersicht; Kategorie `Beweissicherung und Fotodokumentation`; Schlagwort `Drohne`.
- HTTP 200 geprüft: Detail, Übersicht, Kategorie, Schlagwort, `sitemap-index.xml`, `sitemap-0.xml`, `robots.txt` und `deploy-version.txt`. Die Sitemap enthält den Slug, Canonical ist exakt, `noindex` fehlt wie vorgesehen und der Marker wies exakt den Inhaltscommit aus.
- Tagesregister anschließend atomar durch Commit `41d15fbd1a7cf4d03dc845a33aed9d8454bd6dc4` auf `published-verified` gesetzt. LinkedIn blieb `not-posted` und der Begleittext nur vorbereitet.
