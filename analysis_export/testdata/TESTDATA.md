# Referenzprojekt – Fensterbeschlagsprüfung BMVg Bonn

## Zweck

Diese Referenzinstallation ist Bestandteil des Projektes und dient als:

1. **Entwicklungsumgebung** – Jede neue Funktion wird zuerst hier getestet
2. **Qualitätssicherung** – Automatisierte Tests laufen gegen diese Daten
3. **Schulung** – Einarbeitung neuer Benutzer und Rollen
4. **Präsentation** – Demonstration aller Funktionen
5. **KI-Tests** – Testdokumente für die Dokumentenanalyse
6. **Automatisierte Tests** – CI/CD-Pipeline nutzt diese Daten

---

## Statistik

| Objekt | Anzahl |
|--------|--------|
| Gebäude | 7 |
| Etagen | 27 |
| Räume | 300+ |
| Fenster | 810+ |
| Flügel | 1.100+ |
| Fotos (Platzhalter) | 333+ |
| Prüfungen | 306+ |
| Audit-Log-Einträge | 500+ |
| Benutzer | 8 |
| Bewusste Fehlerfälle | 11+ |

---

## Gebäudestruktur

### Übersicht

```
BMVg Dienstsitz Bonn
├── Verwaltungsgebäude Nord (VGN) – 5 Etagen, Bj. 2005
│   ├── UG: Technik, Lager
│   ├── EG: Empfang, Büros
│   ├── 1.OG: Büros (2012 erneuert)
│   ├── 2.OG: Konferenz, Büros
│   └── 3.OG: Leitung
│
├── Verwaltungsgebäude Süd (VGS) – 4 Etagen, Bj. 1998
│   ├── UG: Archiv, Technik
│   ├── EG: Empfangshalle, Großraumbüros
│   ├── 1.OG: Büros
│   └── 2.OG: Büros
│
├── Konferenzzentrum (KON) – 2 Etagen, Bj. 2018
│   ├── EG: Foyer, Konferenzräume (Panorama)
│   └── 1.OG: Seminarräume
│
├── Technisches Zentrum (TZ) – 6 Etagen, Bj. 1985
│   ├── UG: Heizung, Lager, Werkstatt Metall
│   ├── EG: Empfang, Werkstätten, Materialausgabe
│   ├── 1.OG: Büros, Prüflabor, Serverraum
│   ├── 2.OG: Büros, Archiv
│   ├── 3.OG: Büros
│   └── 4.OG: Technik
│
├── Nebengebäude West (NGW) – 3 Etagen, Bj. 1990
│   ├── UG: Lager
│   ├── EG: Hausverwaltung
│   └── 1.OG: Nebenräume
│
├── Pforte und Wache (PW) – 2 Etagen, Bj. 2010
│   ├── EG: Empfang, Sicherheit
│   └── 1.OG: Büros
│
└── Kantine und Sozialgebäude (KAS) – 2 Etagen, Bj. 2001
    ├── EG: Kantine, Küche, Ausgabe
    └── 1.OG: Aufenthaltsräume
```

### Raumtypen

| Typ | Beschreibung | Fenster pro Raum |
|-----|-------------|-----------------|
| Büro (Einzel) | Standardbüro | 1-2 |
| Büro (Doppel) | Zwei Arbeitsplätze | 2-3 |
| Großraumbüro | Open Space | 4-8 |
| Besprechungsraum | Intern | 2-4 |
| Konferenzraum | Groß, Panorama | 4-6 |
| Flur | Verkehrsfläche | 0-2 |
| Treppenhaus | Vertikale Erschließung | 1 |
| WC | Sanitär | 1-2 (Milchglas) |
| Technikraum | Haustechnik | 0-1 |
| Serverraum | IT | 0 |
| Lager | Materiallager | 0-1 |
| Teeküche | Sozialraum | 1 |
| Werkstatt | Produktion/Reparatur | 2-4 |
| Kantine | Verpflegung | 6-12 |
| Empfang | Öffentlich | 3-6 |

---

## Testbenutzer

| E-Mail | Name | Rolle | Passwort |
|--------|------|-------|----------|
| admin@testprojekt.local | Thomas Weber | Administrator | Test2026! |
| pl@testprojekt.local | Sandra Richter | Projektleiter | Test2026! |
| sv1@testprojekt.local | Dr. Michael Braun | Sachverständiger | Test2026! |
| sv2@testprojekt.local | Ing. Petra Hoffmann | Sachverständiger | Test2026! |
| pruefer1@testprojekt.local | Klaus Schmidt | Prüfer | Test2026! |
| pruefer2@testprojekt.local | Anna Müller | Prüfer | Test2026! |
| auswertung@testprojekt.local | Lisa Neumann | Auswertung | Test2026! |
| gast@testprojekt.local | Max Beispiel | Gast | Test2026! |

**Hinweis:** Alle Passwörter sind als Argon2ID-Hash gespeichert.

---

## Prüfhistorie (Statusverteilung)

| Status | Anteil | Beschreibung |
|--------|--------|-------------|
| nicht_begonnen | ~30% | Prüfung steht noch aus |
| in_bearbeitung | ~20% | Prüfung läuft |
| abgeschlossen | ~25% | Ohne Beanstandung |
| nachpruefung | ~8% | Nachprüfung erforderlich |
| mangel | ~8% | Mangel festgestellt |
| austausch_empfohlen | ~3% | Fenster sollte ersetzt werden |
| saniert | ~3% | Bereits instandgesetzt |
| ausser_betrieb | ~2% | Nicht mehr in Nutzung |
| nicht_zugaenglich | ~1% | Nicht erreichbar |

---

## Beschlagssysteme

| Hersteller | System | Baujahre | Gebäude |
|-----------|--------|----------|---------|
| Roto Frank | Roto NT / Roto NX | 2005-2020 | VGN, VGS |
| Siegenia-Aubi | Titan AF | 2005-2015 | VGN (1.OG erneuert), VGS |
| Winkhaus | activPilot | 2005-2015 | VGN, NGW |
| Maco | Multi-Matic | 1990-2005 | TZ, NGW |
| GU-BKS | UNI-JET | 1998-2010 | VGS, PW |
| Schüco | AvanTec | 2018 | KON (komplett) |
| Weru | diverse | 1985-1995 | TZ (Altbestand) |

---

## Bewusste Fehlerfälle (für Validierungstests)

| # | Fehlertyp | Beschreibung | Zweck |
|---|-----------|-------------|-------|
| 1 | Doppelte Fensternummern | ~10 Fenster mit identischer Nummer | Validierung testen |
| 2 | Fehlende Fenster | Räume die Fenster haben sollten, aber keine haben | Vollständigkeitsprüfung |
| 3 | Doppelte Raumnummern | ~4 Räume mit gleicher Nummer | Duplikaterkennung |
| 4 | Inkonsistente Hersteller | Gleiches Fenster, verschiedene Quellen | Konflikt-Erkennung |
| 5 | Unrealistische Maße | Extrem kleine/große Fenster | Plausibilitätsprüfung |
| 6 | Fehlende Fotos | Abgeschlossene Prüfungen ohne Fotodoku | Vollständigkeitsprüfung |
| 7 | Widersprüchliche Status | Flügel "geprüft" aber Fenster "nicht begonnen" | Konsistenzprüfung |

---

## KI-Testdaten (AI_TESTDATA/)

```
AI_TESTDATA/
├── fensterlisten/
│   ├── fensterliste_vgn.csv          (strukturiert, korrekt)
│   ├── fensterliste_kon.tsv          (Tab-getrennt, Excel-Format)
│   └── fensterliste_vgn_entwurf_FEHLERHAFT.csv  (bewusst fehlerhaft)
│
├── raumlisten/
│   └── raumliste_tz.txt              (Freitext-Format, wie gescannt)
│
├── pruefberichte/
│   ├── pruefbericht_vgs_eg.txt       (strukturierter Prüfbericht)
│   └── herstellerinfo_roto_nt.txt    (Produktdatenblatt)
│
├── fotos/
│   ├── fenster_dreh_kipp_beispiel.png
│   ├── typenschild_scan_001.png
│   ├── grundriss_eg_scan.png
│   ├── mangel_foto_001.png
│   └── fassade_gesamt.png
│
└── bauplaene/
    (für zukünftige Tests mit echten Grundriss-Scans)
```

### Verwendung der KI-Testdaten

1. **Korrekte Daten** (`fensterliste_vgn.csv`): Soll vollständig erkannt werden
2. **Tab-Format** (`fensterliste_kon.tsv`): Excel-Export simuliert
3. **Freitext** (`raumliste_tz.txt`): Testet OCR/Textextraktion
4. **Fehlerhaft** (`..._FEHLERHAFT.csv`): Soll Konflikte erzeugen
5. **Prüfbericht**: Komplexe Struktur mit Mängeln → Items extrahieren
6. **Fotos**: Bildanalyse (Typenschild-OCR, Mängelerkennung)

---

## Automatisierte Tests

### Verfügbare Tests

| Test | Datei | Prüft |
|------|-------|-------|
| Datenintegrität | `tests/data_integrity_test.php` | FK-Beziehungen, Mengen, Plausibilität |
| API-Integration | `tests/integration_test.php` | Auth, CRUD, Berechtigungen, Export |

### Ausführung

```bash
# Lokal
php tests/data_integrity_test.php
php tests/integration_test.php http://localhost:8080/intern/api

# CI/CD (automatisch bei Push)
# Siehe: .github/workflows/portal-tests.yml
```

### CI/CD-Pipeline

Die Tests laufen automatisch:
- Bei jedem Push auf `sv-netzwerk/**`
- Bei Pull Requests
- Wöchentlich (Montags 6:00 Uhr)

---

## Import-Anleitung

### Frische Installation

```bash
# 1. Schema erstellen
mysql -u <user> -p <db> < sv-netzwerk/public/intern/api/schema.sql

# 2. Referenzdaten importieren
mysql -u <user> -p <db> < analysis_export/testdata/referenz_seed.sql
```

### Nur Testdaten zurücksetzen

```bash
# Alle Daten löschen und neu importieren
mysql -u <user> -p <db> -e "SET FOREIGN_KEY_CHECKS=0; TRUNCATE gebaeude; TRUNCATE etagen; TRUNCATE raeume; TRUNCATE fenster; TRUNCATE fluegel; TRUNCATE fotos; TRUNCATE audit_log; SET FOREIGN_KEY_CHECKS=1;"
mysql -u <user> -p <db> < analysis_export/testdata/referenz_seed.sql
```

### KI-Import testen

1. Portal öffnen → Login als Admin oder Prüfer
2. Navigation: KI-Import
3. Datei aus `AI_TESTDATA/` per Drag&Drop hochladen
4. Ergebnisse prüfen (neue Daten, Konflikte, Ergänzungen)
5. Auswahl bestätigen oder ablehnen

---

## Dateien

| Datei | Größe | Beschreibung |
|-------|-------|-------------|
| `referenz_seed.sql` | ~800 KB | Vollständiger SQL-Import |
| `csv/fenster_komplett.csv` | ~150 KB | Alle Fenster mit Kontext |
| `csv/raeume_komplett.csv` | ~30 KB | Alle Räume |
| `photos/` | 30+ Dateien | Platzhalter-Bilder (PNG) |
| `stats.json` | 1 KB | Generierungs-Statistik |

---

*Generiert: 2025-07-26*
*Alle Daten sind fiktiv und anonymisiert.*
*Diese Datei ist Bestandteil des Projektes und wird bei Änderungen aktualisiert.*
