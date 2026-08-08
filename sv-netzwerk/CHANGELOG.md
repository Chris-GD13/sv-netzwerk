# Changelog

## 3.4.10 – 2026-08-08
- historischer Fach-Backfill für 02.08.2026 veröffentlicht: "Frostbedingte Leitungswasserschäden: Rohrbruch, Auftauen und Vorschäden fachlich abgrenzen"
- LinkedIn- und Wissen-in-180-Sekunden-Begleitdateien vorbereitet, nicht extern veröffentlicht
- ohne öffentliches Beitragsbild veröffentlicht
- verbindlichen Fachbeitragsstandard unter `docs/FACHBEITRAG_STANDARD.md` hinterlegt

## 3.4.8 – 2026-08-03
- historischer Fach-Backfill veröffentlicht: „Starkregen und Rückstau: Eintrittswege, Rückstauebene und Schadenbereiche sauber trennen“
- Fachbeitrag als eigenständige Detailroute, Suchindex- und Sitemap-Eintrag ergänzt
- Fachwissenübersicht um genau einen neuen Library-Eintrag erweitert
- redaktioneller Entwurf für Fachbeitrag „Starkregen und Rückstau: Schadenaufnahme und Regulierung im Kumulereignis (2026-08-03)" vorbereitet, nicht veröffentlicht

## 3.4.7 – 2026-08-02
- Pilotbeitrag „Schneedruck und Winterschäden“ in redaktioneller und fachlicher Prüfung, nicht final veröffentlicht

## 3.4.9 – 2026-08-08
- Pilotbeitrag „Schneedruck und Winterschäden: Dächer fachlich prüfen, Risiken sauber abgrenzen“ veröffentlicht
- Veröffentlichung ohne Beitragsbild; typografische Platzhaltergrafik bleibt unveröffentlicht im Entwurfsbereich

## 3.4.6 – 2026-08-01
- automatischer morgens Fachbeitrag veröffentlicht: „Brandschaden mit mehreren betroffenen Gebäuden: Struktur für Erstaufnahme und Regulierung"
- LinkedIn- und Wissen-in-180-Sekunden-Begleitdateien automatisch erstellt
- Beitragsbild unter /assets/images/linkedin/brandschaden-mehrere-gebaeude-koordination.svg erzeugt

## 3.4.5 – 2026-07-28
- automatischer nachmittags Fachbeitrag veröffentlicht: „Großflächige Leitungswasserschäden: Sanierungssteuerung unter hoher Schadenfrequenz"
- LinkedIn- und Wissen-in-180-Sekunden-Begleitdateien automatisch erstellt
- Beitragsbild unter /assets/images/linkedin/grossflaechige-leitungswasserschaeden-sanierungssteuerung.svg erzeugt
- Bugfix: author-Feld im Automation-Script auf Slug `christian-waechter` korrigiert
- Bugfix: CRLF-Zeilenenden bei CHANGELOG.md und library.ts im Automation-Script korrekt behandelt
- Neues Hilfsskript `count-daily-articles.mjs` für dynamische Pflichtbeitragszählung im Workflow
- knowledge-standard.yml: Tageszählung dynamisch statt hart codierter `--expected-daily-count=2`
- deploy.yml: Build-Prüfung um `grossflaechige-leitungswasserschaeden-sanierungssteuerung` erweitert

## 3.4.4 – 2026-07-27
- Fachbeitrags-Automation auf regionale öffentliche Meldungen (Mo–Fr) als Standardquelle priorisiert; anonymisierte Kalenderfälle nur noch optional per `ALLOW_CALENDAR_CASE_CONTEXT=true`
- LinkedIn-/Zap-Payload-Erstellung in eigenes Skript ausgelagert und als JSON-Datei unter `.automation/linkedin-payloads/{publication_id}.json` versioniert erzeugt
- Workflow um explizite Linkprüfung des veröffentlichten Beitrags (`lychee`) erweitert
- Slot-abhängige Pflichtbeitragsvalidierung ergänzt (`--expected-daily-count=1` morgens, `=2` nachmittags)

## 3.4.3 – 2026-07-21
- automatischer morgens Fachbeitrag veröffentlicht: „Sturm- und Hagel-Serienschäden: Prüffolge für belastbare Freigaben – fachliche Einordnung zur aktuellen Lage“
- LinkedIn- und Wissen-in-180-Sekunden-Begleitdateien automatisch erstellt
- Beitragsbild unter /assets/images/linkedin/sturm-hagel-serienschaeden-prueffolge-2026-07-21-morning.svg erzeugt

## 3.4.2 – 2026-07-20
- automatischer morgens Fachbeitrag veröffentlicht: „Hochwasser und Überflutung: Koordination im Großschadenbestand“
- LinkedIn- und Wissen-in-180-Sekunden-Begleitdateien automatisch erstellt
- Beitragsbild unter /assets/images/linkedin/hochwasser-ueberflutung-grossschadenkoordination-2026-07-20-morning.svg erzeugt

## 3.4.1 – 2026-07-20
- automatischer morgens Fachbeitrag veröffentlicht: „Starkregen und Rückstau: Schadenaufnahme und Regulierung im Kumulereignis“
- LinkedIn- und Wissen-in-180-Sekunden-Begleitdateien automatisch erstellt
- Beitragsbild unter /assets/images/linkedin/starkregen-rueckstau-schadenaufnahme-regulierung-2026-07-20-morning.svg erzeugt

## 3.3.1 – 2026-07-19
- Post-Merge-Validierung ergänzt: Build/Deploy auf `main` erfolgreich nachgewiesen
- Fachbeitrags-Preflight für Nicht-Cadence-Tage stabilisiert
- Produktiv-E2E-HTTP-Tests für Kontakt- und Schadenmeldung dokumentiert (aktuell `?fehler=mail`, Mailkonfiguration in Zielumgebung als Restblocker)

## 3.4.0 – 2026-07-19
- Fachbeitrags-Automation auf zwei tägliche Berlin-Zeitfenster umgestellt (05:15-06:40 und 16:15-17:30, DST-sicher über duale UTC-Crons plus Laufzeitprüfung)
- konkurrierenden alten Fachbeitrags-Workflow entfernt und auf eine aktive Automation konsolidiert
- neuer Generator für Fachbeiträge, Bilddatei, LinkedIn-Begleittext, Video-Skript, Library-Integration und Veröffentlichungsprotokoll ergänzt
- Doppelausführungsschutz über Concurrency, Slot-ID, publication_id und Protokollprüfung ergänzt
- LinkedIn-/Zap-Auslösung hinter Live-URL-Prüfung geschaltet
- Redaktions-/Workflow-Dokumentation und Veröffentlichungsprotokoll ergänzt

## 3.3.0 – 2026-07-19
- Recovery-Basis auf v7.05 abgeglichen und dokumentiert (`docs/recovery/analysis-v7.05-vs-current.md`)
- Serverseitige Formular-Architektur konsolidiert: `anfrage.php`, `anfrage-versicherer.php`, `anfrage-gutachter-plattform.php`, `schadenmeldung.php` plus gemeinsamer Core-Handler mit Honeypot, Validierung, Logging und Eingangsbestätigung
- Schadenmeldung auf produktives Formular mit Dateiupload umgestellt (browser-only Wizard entfernt)
- Kontakt- und Terminstrecke auf serverseitige Verarbeitung umgestellt
- Versicherer-Bereich für Groß- und Kumulschäden mit direktem Beauftragungsformular ausgebaut
- Gutachter-Plattform mit eigenem Hauptmenüpunkt, Landingpage, Anfrage- und Demo-Seiten sowie zielgruppenspezifischen Einstiegen ergänzt
- Fehlende Legacy-Kernrouten wiederhergestellt: `/termin-vereinbaren/`, `/kompetenzzentrum/`, `/medienbibliothek/`, `/recht-compliance/`, `/seminare/`, `/versicherungen/`, `/wissen/`
- Eigene 404-Seite im Astro-Build ergänzt
- Interne tote Links in Bibliotheks- und Fachwissensverlinkungen korrigiert
- Build-Blocker in der Fachwissensvalidierung beseitigt (doppelter Pflichtbeitrag am 2026-07-17 aufgelöst)

## 3.2.0 – 2026-07-17
- weiteren Fachbeitrag im Bereich Fachwissen veröffentlicht: „Sturmschaden: Windwirkung, Vorschaden und Bauteilversagen technisch abgrenzen“
- Fachwissensübersicht um neuen Eintrag (Kategorie/Tags/Filter) ergänzt
- statische Sitemap um neue Fachwissensroute erweitert
- LinkedIn-Begleittext und Wissen-in-180-Sekunden-Skript für den Sturmschaden-Beitrag abgelegt

## 3.1.0 – 2026-07-17
- neuen Fachbeitrag veröffentlicht: „Brandschaden nach Erstmaßnahmen: Übergang zur Wiederherstellung sauber steuern“
- Fachwissensübersicht mit neuem Eintrag (Kategorie/Tags/Filter) als aktuellsten Beitrag ergänzt
- statische Sitemap auf neue Fachwissensroute und aktualisiertes Lastmod-Datum erweitert
- LinkedIn-Begleittext und Wissen-in-180-Sekunden-Skript für den 17.07.2026 abgelegt
- Automationsrhythmus verbindlich auf Montag/Freitag umgestellt (Europe/Berlin, DST-sicher über duale UTC-Crons mit Zeitfensterprüfung)
- automatische Fachbeitrags-Preflight-Prüfung für letzten Beitrag, Slug-/Titel-Dubletten, Übersichtsintegration und Companion-Dateien ergänzt
- alle übrigen zeitgesteuerten Automationen deaktiviert (u. a. Dependabot-Schedule), manuelle technische Workflows bleiben verfügbar

## 3.0.1 – 2026-07-14
- sichtbaren Header vollständig neu aufgebaut
- robuste Desktop-Navigation mit Mega-Menüs integriert
- mobile Navigation als Off-Canvas-Menü mit deutlich sichtbarer Menüschaltfläche umgesetzt
- Fokusführung, ESC-Schließen, Backdrop und Scroll-Lock ergänzt
- Header-CTA, Suche, Kontakt und aktive Navigation vereinheitlicht
- responsive Darstellung für Smartphone, Tablet und Desktop überarbeitet


## 3.0.0 – 2026-07-14
- vollständiges sichtbares Frontend-Redesign
- neuer Sticky Header mit Mega-Menüs
- funktionsfähiges Mobile-Off-Canvas-Menü
- neuer Hero mit SVOS-Prozessdarstellung
- neue Startseite mit Leistungen, Praxisfällen, Fachwissen, Plattform und Netzwerk
- neues Farb-, Typografie- und Kartensystem
- neuer Footer


## 1.6.0 – 2026-07-14

### SVOS Foundation
- Content Collections für Fachwissen, Downloads, Praxisfälle und Autoren eingeführt
- verbindliche Zod-Schemas für Metadaten, Veröffentlichungsstatus und SEO ergänzt
- zentrale TypeScript-Typen für SVOS, Taxonomien und Suchdokumente angelegt
- Utility-Module für Veröffentlichungsfilter, Sortierung, Taxonomien, Slugs und Routen ergänzt
- exemplarische, valide Inhalte für alle neuen Collections angelegt
- technische SVOS-Statusseite unter `/svos/` ergänzt
- Versionsstand auf 1.6.0 aktualisiert

## 1.5.4 – 2026-07-14
- Performance- und Accessibility-Grundlagen erweitert
