# Changelog

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
