# TESTBERICHT – Referenzprojekt & Portal-Gesamttest

**Projekt:** Fensterbeschlagsprüfung BMVg Dienstsitz Bonn  
**Datum:** 2025-07-26  
**Tester:** Automatisiert (Playwright + PHP Integration)

---

## 1. Playwright Screenshot-Test

| Test | Ergebnis |
|------|----------|
| Login als Admin | ✓ bestanden |
| Dashboard | ✓ bestanden |
| Gebäude | ✓ bestanden |
| Etagen | ✓ bestanden |
| Räume | ✓ bestanden |
| Fenster | ✓ bestanden |
| Flügel | ✓ bestanden |
| Fotos | ✓ bestanden |
| Export | ✓ bestanden |
| Benutzer | ✓ bestanden |
| KI-Import | ✓ bestanden |
| Dialoge | ✓ bestanden |
| Berechtigungsprotokoll | ✓ bestanden |

**Ergebnis: 13/13 Tests bestanden (100%)**

### Erfasste Artefakte pro Seite:
- Full-Page-Screenshot (PNG)
- PDF-Ausgabe (A4)
- HTML-Snapshot
- Berechtigungsprotokoll (alle Rollen × alle Seiten)

---

## 2. Portal-Erreichbarkeit

| URL | Status |
|-----|--------|
| https://www.sv-netzwerk.eu/intern/fensterpruefung-bonn/ | ✓ erreichbar |
| Login mit Admin-Credentials | ✓ funktional |
| SPA-Navigation (alle Routen) | ✓ funktional |
| API-Endpunkte | ✓ antworten |

---

## 3. Referenzprojekt – Vollständigkeit

### 3.1 Projektakte

| Dokument | Vorhanden | Inhalt |
|----------|-----------|--------|
| Auftragsschreiben | ✓ | Vollständiger Auftragsbrief BMVg |
| Auftragsbestätigung | ✓ | Bestätigung mit Zeitplan |
| Leistungsbeschreibung | ✓ | 7 Abschnitte, Normen, Qualifikation |
| Projektstammdaten | ✓ | Alle Kontakte, Termine, Volumen |
| Gebäudeliste | ✓ | 7 Gebäude mit Lageplan |
| Grundrisse | ✓ | VGN EG, VGN 1.OG, TZ EG, KAS (schlechter Scan) |
| Fensterlisten | ✓ | 3 Versionsstände mit Konflikten |
| Herstellerunterlagen | ✓ | 4 Hersteller (Roto, Siegenia, Winkhaus, Schüco) |
| Beschlagübersicht | ✓ | Gesamtprojekt mit Zustandsbewertung |
| Wartungsunterlagen | ✓ | VGN Protokoll + TZ Historie |
| Raumbuch | ✓ | 2 Gebäude mit >50 Räumen |
| Prüfprotokolle | ✓ | VGN EG + VGS EG (vollständig) |
| Fotodokumentation | ✓ | 25 Fotos mit Index |
| Korrespondenz | ✓ | 5 E-Mails (de + en) |
| Mängelliste | ✓ | 12 Mängel mit Status |
| Nachträge | ✓ | Umfangskorrektur + Kostenaktualisierung |
| Handschriftliche Notiz | ✓ | Simuliert |
| Verdrehter Scan | ✓ | OCR-Testdokument |

### 3.2 Fehlerfälle (bewusst eingebaut)

| Nr | Fehler | Implementiert |
|----|--------|--------------|
| F1 | Falscher Hersteller V1 (Roto statt Siegenia) | ✓ |
| F2 | Falscher Hersteller (GU statt Maco) | ✓ |
| F3 | Veraltete Raumbez. (WC statt Teeküche) | ✓ |
| F4 | Falsche Maße | ✓ |
| F5 | Inkorrekte Summe (13+5≠18) | ✓ |
| F6 | Fehlende Daten (KAS in V1) | ✓ |
| F7 | Schlechter Scan (KAS) | ✓ |
| F8 | Verdrehter Scan (PW) | ✓ |
| F9 | Handschriftliche Notiz | ✓ |
| F10 | Veralteter Entwurf | ✓ |
| F11 | Englischsprachige E-Mail | ✓ |
| F12 | Fenster nicht in FM-Liste | ✓ |

---

## 4. KI-Importpaket

| Eigenschaft | Status |
|-------------|--------|
| Eigener Ordner | ✓ referenzprojekt_import/ |
| Keine Sollwerte enthalten | ✓ |
| Keine internen Hinweise | ✓ |
| 18 Dokumente | ✓ |
| README vorhanden | ✓ |
| ZIP erstellt | ✓ referenzprojekt_import.zip |

---

## 5. Soll-Referenz

| Eigenschaft | Status |
|-------------|--------|
| Außerhalb Importpaket | ✓ referenzprojekt_soll/ |
| Erwartete Zahlen dokumentiert | ✓ |
| Alle 8 Konflikte beschrieben | ✓ |
| 6 erwartete Rückfragen | ✓ |
| 12 Fehlerfälle dokumentiert | ✓ |
| Bewertungskriterien definiert | ✓ |
| Mindestanforderungen festgelegt | ✓ (70%) |

---

## 6. Automatisierte Tests

| Test | Datei | Zweck |
|------|-------|-------|
| Datenintegrität | tests/data_integrity_test.php | DB-Konsistenz |
| API-Integration | tests/integration_test.php | Auth, CRUD, Berechtigungen |
| KI-Abnahme | tests/ki_abnahme_test.php | Vergleich KI-Ergebnis vs. Soll |
| Playwright | tests/portal_screenshot_test.js | Screenshots + Berechtigungen |
| CI/CD Workflow | .github/workflows/portal-tests.yml | Automatische Ausführung |

---

## 7. Liefergegenstände

| Datei | Inhalt | Größe |
|-------|--------|-------|
| analysis_export.zip | Analyse-Export + Screenshots + Testdaten | ~10 MB |
| referenzprojekt_import.zip | KI-Importpaket (18 Dokumente) | ~30 KB |
| referenzprojekt_soll.zip | Verdeckte Soll-Referenz | ~5 KB |
| testbericht.md | Dieses Dokument | - |

---

## 8. Deployment

- **Repository:** Chris-GD13/sv-netzwerk
- **Branch:** main
- **Portal-URL:** https://www.sv-netzwerk.eu/intern/fensterpruefung-bonn/
- **KI-Import-URL:** https://www.sv-netzwerk.eu/intern/fensterpruefung-bonn/import/

---

*Erstellt: 2025-07-26 21:00 CEST*
