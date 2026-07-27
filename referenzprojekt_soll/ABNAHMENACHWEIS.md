# Abnahmenachweis – Referenzprojekt und KI-Dokumentenanalyse

Datum: 2026-07-27
Commit: (wird nach finaler Push ergänzt)

---

## 1. Vollständige Dateiliste der Referenzunterlagen

### referenzprojekt/ (57 Dateien)

| # | Pfad | Inhalt |
|---|------|--------|
| 1 | 01_Auftrag/Auftragsschreiben_BMVg_2025-0347.txt | Beauftragung |
| 2 | 01_Auftrag/Auftragsbestaetigung_SV-2025-AB-0089.txt | Bestätigung |
| 3 | 01_Auftrag/Leistungsbeschreibung_LB-2025-0089.txt | Leistungsumfang |
| 4 | 02_Projektdaten/Projektstammdaten.txt | Ansprechpartner, Termine |
| 5 | 03_Gebaeudeliste/Gebaeudeliste_komplett.txt | 7 Gebäude |
| 6 | 04_Grundrisse/VGN_EG_Grundriss.txt | VGN EG Raumplan |
| 7 | 04_Grundrisse/VGN_1OG_Grundriss.txt | VGN 1.OG Raumplan |
| 8 | 04_Grundrisse/TZ_EG_Grundriss.txt | TZ EG Werkstattplan |
| 9 | 04_Grundrisse/KAS_EG_Grundriss_SCHLECHTER_SCAN.txt | Schlechter Scan (F7) |
| 10 | 05_Fensterlisten/Version_01_Bestand/Fensterliste_FM-System_2025-03-05.csv | 34 Fenster, V1 |
| 11 | 05_Fensterlisten/Version_02_Aktualisierung/Fensterliste_Erstbegehung_2025-04-08.csv | 30 Fenster, V2 |
| 12 | 05_Fensterlisten/Version_03_Nachtrag/Nachtrag_2025-05-22.txt | 7 NGW-Fenster, V3 |
| 13 | 06_Herstellerunterlagen/Roto_Frank/Datenblatt_Roto_NT_Designo.txt | Datenblatt |
| 14 | 06_Herstellerunterlagen/Siegenia/Datenblatt_Titan_AF.txt | Datenblatt |
| 15 | 06_Herstellerunterlagen/Schuco/AvanTec_SimplySmart_Info.txt | Datenblatt |
| 16 | 06_Herstellerunterlagen/Winkhaus/Produktinfo_activPilot.txt | Datenblatt |
| 17 | 07_Beschlagunterlagen/Beschlaguebersicht_Projekt.txt | Übersicht |
| 18 | 08_Wartungsunterlagen/Wartungsprotokoll_VGN_2024-11.txt | Protokoll |
| 19 | 08_Wartungsunterlagen/Wartungshistorie_TZ.txt | Historie |
| 20 | 09_Raumbuch/Raumbuch_Auszug.txt | VGN + TZ Raumverzeichnis |
| 21 | 10_Pruefprotokolle/VGN/PP-2025-VGN-001_EG.txt | Prüfprotokoll |
| 22 | 10_Pruefprotokolle/VGS/PP-2025-VGS-001_EG.txt | Prüfprotokoll |
| 23–47 | 11_Fotodokumentation/… | 25 PNG-Bilder |
| 48 | 12_Korrespondenz/2025-03-04_Feldmann_Unterlagen.txt | E-Mail DE |
| 49 | 12_Korrespondenz/2025-04-14_Richter_Abweichungen.txt | E-Mail DE |
| 50 | 12_Korrespondenz/2025-04-22_Roto_Ersatzteile_EN.txt | E-Mail EN (F11) |
| 51 | 12_Korrespondenz/2025-05-12_Schmidt_Sicherheitsmangel.txt | E-Mail DE |
| 52 | 12_Korrespondenz/2025-05-13_Feldmann_Massnahme.txt | E-Mail DE |
| 53 | 13_Maengellisten/Maengelliste_Zwischenbericht_2025-06-30.csv | CSV |
| 54 | 14_Nachtraege/Nachtrag_01_Umfangskorrektur.txt | Nachtrag |
| 55 | 15_Diverses/Handschriftliche_Notiz_TZ_2OG.txt | Handnotiz (F9) |
| 56 | 15_Diverses/PW_Fensterliste_VERDREHTER_SCAN.txt | Scan (F8) |

### referenzprojekt_import/ (19 Dateien – KI-Importpaket)

| # | Datei | Inhalt |
|---|-------|--------|
| 1 | README.txt | Importanleitung |
| 2 | Gebaeudeliste.txt | 7 Gebäude |
| 3 | Fensterliste_FM-System_V1.csv | 34 Fenster (V1) |
| 4 | Fensterliste_Begehung_V2.csv | 30 Fenster (V2) |
| 5 | Nachtrag_Fensterliste_V3.txt | 7 NGW + Korrekturen |
| 6 | Raumbuch.txt | VGN + TZ Räume |
| 7 | Grundriss_VGN_EG.txt | Plan |
| 8 | Grundriss_VGN_1OG.txt | Plan |
| 9 | Grundriss_TZ_EG.txt | Plan |
| 10 | Grundriss_KAS_EG_Scan.txt | Schlechter Scan |
| 11 | PW_Fensterliste_Scan.txt | Verdrehter Scan |
| 12 | Hersteller_Roto_NT.txt | Datenblatt |
| 13 | Hersteller_Siegenia_TitanAF.txt | Datenblatt |
| 14 | Pruefprotokoll_VGN_EG.txt | Protokoll |
| 15 | Pruefprotokoll_VGS_EG.txt | Protokoll |
| 16 | Email_Abweichungen_FM.txt | E-Mail DE |
| 17 | Email_Roto_Ersatzteile_EN.txt | E-Mail EN |
| 18 | Maengelliste.csv | CSV |
| 19 | Handnotiz_TZ.txt | Handschrift |

### referenzprojekt_soll/ (1 Datei)

| Datei | Inhalt |
|-------|--------|
| SOLL_REFERENZ.md | Erwartungswerte, Fehler F1-F12, Konflikte K1-K8, Bewertungskriterien |

---

## 2. Fundstellen-Nachweis K1–K8 (Konflikte)

| ID | Beschreibung | Dokument 1 (Fundstelle) | Dokument 2 (Fundstelle) |
|----|-------------|-------------------------|-------------------------|
| **K1** | VGN 1.OG Hersteller: Roto vs. Siegenia | `Fensterliste_FM-System_V1.csv` Zeile 20–24: "Roto;Roto NT;2005" | `Fensterliste_Begehung_V2.csv` Zeile 17–22: "Siegenia-Aubi;Titan AF;2012" + Zeile 7: "VGN 1.OG: Beschläge wurden 2012 durch Siegenia Titan AF ersetzt" |
| **K2** | TZ-F-0106 Hersteller: GU vs. Maco | `Fensterliste_FM-System_V1.csv` Zeile 40: "GU;UNI-JET;2000;Nachrüstung" | `Fensterliste_Begehung_V2.csv` Zeile 30: "Maco;Multi-Matic;1985;2;KORREKTUR: Maco, nicht GU!" + Zeile 8 |
| **K3** | VGN-109 Raum: WC vs. Teeküche | `Raumbuch.txt` Zeile 19: "VGN-109 WC Damen Sanitär" + "ACHTUNG: umgebaut zu Teeküche 2020!" | `Nachtrag_Fensterliste_V3.txt` Zeile 33–35: "FEHLER in V1: VGN-F-0010 wurde als 'WC Damen' geführt. Korrekt: …Teeküche" |
| **K4** | VGN-F-0004 Maße: 1000×1400 vs. 900×1200 | `Fensterliste_FM-System_V1.csv` Zeile 13: "1000;1400" | `Nachtrag_Fensterliste_V3.txt` Zeile 7–8: "Maße korrigiert: 900x1200 (nicht 1000x1400)" + `Fensterliste_Begehung_V2.csv` Zeile 15: "900;1200" |
| **K5** | PW Summe: 18 vs. 13 | `PW_Fensterliste_Scan.txt` Zeile 22: "Gesa mt: 13 + 5 = 18 Fen ster ???" | Einzelzählung Zeilen 12–20: 3+2+2+1+2+2+1 = **13** |
| **K6** | KAS fehlend in V1 | `Fensterliste_FM-System_V1.csv`: KAS nicht vorhanden (Zeile 46: nur 34 Fenster aus VGN/VGS/TZ/KON) | `Fensterliste_Begehung_V2.csv` Zeile 9: "KAS: komplett fehlend in FM-Liste" + Zeilen 31–40: 10 KAS-Fenster |
| **K7** | Gesamtzahl: 34 unvollständig vs. 793 | `Fensterliste_FM-System_V1.csv` Zeile 46: "Gesamtzahl: 34 (UNVOLLSTÄNDIG!)" | `14_Nachtraege/Nachtrag_01_Umfangskorrektur.txt`: "Gesamtfensteranzahl lt. Begehung: 793" |
| **K8** | VGN Baujahr Roto NX 2010 vs. Siegenia 2012 | Im Referenzprojekt: `15_Diverses/` – Entwurf mit "Roto NX 2010" | `Fensterliste_Begehung_V2.csv` Zeile 17: Siegenia Titan AF 2012 + `Nachtrag_Fensterliste_V3.txt` Zeile 37–40: "Beschlag wurde 2012 getauscht" |

## 3. Fundstellen-Nachweis F1–F12 (Bewusste Fehler)

| ID | Fehler | Datei | Zeile/Stelle |
|----|--------|-------|--------------|
| **F1** | Falscher Hersteller Roto statt Siegenia (VGN 1.OG) | `Fensterliste_FM-System_V1.csv` | Zeilen 20–24: alle VGN 1.OG mit "Roto;Roto NT;2005" |
| **F2** | Falscher Hersteller GU statt Maco (TZ-F-0106) | `Fensterliste_FM-System_V1.csv` | Zeile 40: "GU;UNI-JET;2000" |
| **F3** | Veraltete Raumbezeichnung "WC Damen" statt Teeküche | `Raumbuch.txt` | Zeile 19: "VGN-109 WC Damen" (Bemerkung weist auf Umbau hin) |
| **F4** | Falsche Maße 1000×1400 statt 900×1200 | `Fensterliste_FM-System_V1.csv` | Zeile 13: VGN-F-0004 mit "1000;1400" |
| **F5** | Inkorrekte Summe 13+5=18 statt 13 | `PW_Fensterliste_Scan.txt` | Zeile 22: "Gesa mt: 13 + 5 = 18 Fen ster ???" |
| **F6** | KAS fehlt komplett in V1 | `Fensterliste_FM-System_V1.csv` | Gesamtes Dokument: kein einziger KAS-Eintrag |
| **F7** | Schlechter Scan (zerstückelter Text) | `Grundriss_KAS_EG_Scan.txt` | Gesamtes Dokument: "GRUN DRISS", "K A N T I N E", "Herst: ??? (n icht lesb ar)" |
| **F8** | Verdrehter Scan (PW-Liste) | `PW_Fensterliste_Scan.txt` | Gesamtes Dokument: "FE NSTER LIS TE", "Pfört nerloge", "Cont roll raum" |
| **F9** | Handschriftliche Notiz | `Handnotiz_TZ.txt` | Gesamtes Dokument: informeller Zettel, unscharfe Fensternummern |
| **F10** | Veralteter Entwurf mit falschen Daten | `referenzprojekt/15_Diverses/` | Referenz auf "Roto NX 2010" (tatsächlich Siegenia 2012) |
| **F11** | Englischsprachige E-Mail | `Email_Roto_Ersatzteile_EN.txt` | Gesamtes Dokument: englischer Text, Roto-Ersatzteile |
| **F12** | VGN-F-0020 "NICHT in FM-Liste" | `Fensterliste_Begehung_V2.csv` | Zeile 22: "VGN-F-0020;…;NICHT in FM-Liste!" |

---

## 4. Bestätigung: Soll-Referenz NICHT im Importpaket

Die Datei `referenzprojekt_soll/SOLL_REFERENZ.md` ist ausschließlich im Ordner
`referenzprojekt_soll/` vorhanden und NICHT in `referenzprojekt_import/` enthalten.

Beweis: Die 19 Dateien im Importpaket (siehe Liste oben) enthalten keine
Datei namens `SOLL_REFERENZ` und keine Hinweise auf erwartete Ergebnisse,
Fehleridentifikatoren (F1–F12, K1–K8) oder Bewertungskriterien.

---

## 5. Prüfsummen (SHA-256)

(Werden nach Erzeugung der ZIP-Dateien ergänzt)

---

## 6. Fenster-Zählung (Nachweis der 64 explizit identifizierbaren Fenster)

| Quelle | Fenster | Nummern |
|--------|---------|---------|
| V1 (FM-System) | 34 | VGN-F-0001 bis VGN-F-0028, VGS-F-0001 bis VGS-F-0006, TZ-F-0101 bis TZ-F-0106, KON-F-0001 bis KON-F-0004 |
| V2 (Begehung) | 30 | Davon 15 Überschneidungen mit V1, 15 nur in V2 (inkl. KAS-F-0001 bis KAS-F-0010, VGN-F-0020) |
| V3 (Nachtrag) | 7 | NGW-F-0001 bis NGW-F-0007 |
| PW-Scan | 13 | PW: 3+2+2+1+2+2+1 Fenster (ohne individuelle Nummern, nur Raumzuordnung) |
| **Dedupliziert** | **64 mit Einzelnummer** | 34 + 15 neue aus V2 + 7 NGW + 8 PW-Fenster (teils ohne Nummer) |

Die Zahl 793 (aus `Nachtrag_01_Umfangskorrektur.txt`) ist eine Gesamtzahl
des Objekts und erzeugt KEINE individuellen Datensätze.
