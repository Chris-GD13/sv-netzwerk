# SOLL-REFERENZ (Verdeckt – NUR für Abnahmetest)
# ===============================================
# Diese Datei enthält die erwarteten Ergebnisse der KI-Dokumentenanalyse.
# Sie darf NICHT im KI-Importpaket enthalten sein!

## Erwartete Gesamtzahlen (aus allen Dokumenten zusammen)

| Objekt | Soll-Anzahl | Quelle |
|--------|-------------|--------|
| Gebäude | 7 | Gebäudeliste + Korrespondenz |
| Etagen (VGN) | 5 | Grundriss + Raumbuch |
| Etagen (VGS) | 4 | Fensterliste V1 |
| Etagen (KON) | 2 | Fensterliste V2 |
| Etagen (TZ) | 6 | Raumbuch + Grundriss |
| Etagen (NGW) | 3 | Nachtrag V3 |
| Etagen (PW) | 2 | Verdrehter Scan |
| Etagen (KAS) | 2 | Fensterliste V2 |
| **Etagen (gesamt)** | **24** | Summe: 5+4+2+6+3+2+2 |
| Räume (gesamt) | mindestens 45 (aus Dokumenten erkennbar) | Raumbuch + Listen |
| Fenster (explizit identifizierbar) | mindestens 64 | Einzeln in V1+V2+V3 aufgelistete Fenster |
| Fenster (Gesamtzahl laut Nachtrag) | 793 (nur als Zahl genannt, KEINE vollständigen Datensätze ableitbar) | Nachtrag Nr. 1, Seite 1 |

### Hinweis zur Fensteranzahl

Die Zahl 793 stammt ausschließlich aus dem Nachtrag Nr. 1 als Gesamtzahl des Objekts.
Aus dieser Zahl dürfen KEINE künstlichen vollständigen Fensterdatensätze erzeugt werden.
Nur die 64 in den Fensterlisten V1, V2 und V3 explizit aufgeführten Fenster
(mit Nummer, Typ, Maßen und/oder Hersteller) gelten als identifizierbare Datensätze.
Die KI-Erkennung wird ausschließlich an diesen 64 Fenstern gemessen.

## Erwartete Gebäude

| Nr | Kürzel | Name | Baujahr | Quelle |
|----|--------|------|---------|--------|
| 1 | VGN | Verwaltungsgebäude Nord | 2005 | Gebäudeliste |
| 2 | VGS | Verwaltungsgebäude Süd | 1998 | Gebäudeliste |
| 3 | KON | Konferenzzentrum | 2018 | Gebäudeliste |
| 4 | TZ | Technisches Zentrum | 1985 | Gebäudeliste |
| 5 | NGW | Nebengebäude West | 1990 | Gebäudeliste |
| 6 | PW | Pforte und Wache | 2010 | Gebäudeliste |
| 7 | KAS | Kantine und Sozialgebäude | 2001 | Gebäudeliste |

## Erwartete Konflikte (KI muss diese erkennen!)

| Nr | Typ | Beschreibung | Dokument 1 | Dokument 2 |
|----|-----|-------------|-----------|-----------|
| K1 | Hersteller-Widerspruch | VGN 1.OG: FM sagt "Roto NT 2005", Begehung sagt "Siegenia 2012" | V1 Bestand | V2 Aktualisierung |
| K2 | Hersteller-Widerspruch | TZ-F-0106: FM sagt "GU UNI-JET", Begehung sagt "Maco Multi-Matic" | V1 Bestand | V2 Aktualisierung |
| K3 | Raum-Widerspruch | VGN-109: FM sagt "WC Damen", Nachtrag sagt "Teeküche (seit 2020)" | Raumbuch | Nachtrag V3 |
| K4 | Maße-Widerspruch | VGN-F-0004: V1 sagt 1000x1400, V2 sagt 900x1200 (korrigiert) | V1 | V2 |
| K5 | Summen-Fehler | PW: Scan sagt "13+5=18", tatsächlich nur 13 in Liste | Verdrehter Scan | - |
| K6 | Fehlende Daten | KAS: Komplett fehlend in FM-System (V1) | V1 | V2 (neu erfasst) |
| K7 | Versionsstände | Gesamtzahl: V1 sagt "34 (unvollständig)", Nachtrag sagt 793 | V1 | Nachtrag |
| K8 | Baujahr-Widerspruch | Entwurf VGN: "Roto NX 2010" vs. Begehung "Siegenia 2012" | Fehlerhafter Entwurf | V2 |

## Erwartete Rückfragen der KI

Die KI sollte bei folgenden Stellen nachfragen:

1. **VGN 1.OG Beschlag**: Welche Info ist korrekt? V1 (Roto NT, 2005) oder V2 (Siegenia, 2012)?
   → Erwartet: KI erkennt den Konflikt und fragt nach
   → Korrekte Antwort: V2 ist korrekt (Sanierung 2012)

2. **TZ-F-0106 Hersteller**: GU oder Maco?
   → Erwartet: KI erkennt Widerspruch
   → Korrekte Antwort: Maco (Korrektur in V2)

3. **VGN-109 Raumbezeichnung**: WC oder Teeküche?
   → Erwartet: KI bemerkt Inkonsistenz
   → Korrekte Antwort: Teeküche (Umbau 2020)

4. **PW Gesamtzahl**: 13 oder 18?
   → Erwartet: KI erkennt fehlerhafte Summe im Scan
   → Korrekte Antwort: 13 (aus Einzelaufzählung)

5. **Fehlerhafter Entwurf VGN**: Soll importiert werden?
   → Erwartet: KI erkennt veraltete/fehlerhafte Daten
   → Korrekte Antwort: NICHT importieren (veraltet, Begehungsdaten bevorzugen)

6. **NGW Beschläge "Weru, unbekannt"**: System identifizierbar?
   → Erwartet: KI kennzeichnet als "unbekannt"
   → Info: Eigenentwicklung, nicht katalogisiert

## Bewusst eingebaute Fehler (für Validierung)

| Nr | Fehler | Wo | Erwartete KI-Reaktion |
|----|--------|----|-----------------------|
| F1 | Falscher Hersteller in V1 (Roto statt Siegenia) | V1: VGN 1.OG | Konflikt melden |
| F2 | Falscher Hersteller (GU statt Maco) | V1: TZ-F-0106 | Konflikt melden |
| F3 | Veraltete Raumbez. (WC statt Teeküche) | Raumbuch: VGN-109 | Konflikt/Rückfrage |
| F4 | Falsche Maße (1000x1400 statt 900x1200) | V1: VGN-F-0004 | Korrektur aus V2 |
| F5 | Inkorrekte Summe (13+5=18 statt 13) | PW Scan | Plausibilitätsfehler |
| F6 | Fehlende Daten (KAS nicht in V1) | V1 | Als "neu" klassifizieren |
| F7 | Schlechter Scan (zerstückelter Text) | KAS Grundriss | OCR-Challenge |
| F8 | Verdrehter Scan (PW Liste) | PW Diverses | OCR-Challenge |
| F9 | Handschriftliche Notiz | TZ Zettel | Freitext-Extraktion |
| F10 | Veralteter Entwurf mit falschen Daten | Fehlerhafter Entwurf V3 | Nicht übernehmen |
| F11 | Englischsprachige E-Mail | Roto Spare Parts | Mehrsprachigkeit |
| F12 | Fenster "VGN-F-0020" in V2 als "NICHT in FM-Liste" | V2 | Neuen Datensatz erkennen |

## Bewertungskriterien für KI-Abnahme

| Kriterium | Gewicht | Beschreibung |
|-----------|---------|-------------|
| Gebäude-Erkennung | 10% | Alle 7 Gebäude korrekt erkannt |
| Etagen-Erkennung | 10% | Korrekte Zuordnung Etage→Gebäude |
| Raum-Erkennung | 15% | Raumnummern, Bezeichnungen, Nutzung |
| Fenster-Erkennung | 20% | Fensternummern, Typen, Maße, Hersteller |
| Konflikt-Erkennung | 20% | Widersprüche zwischen Dokumentversionen |
| OCR-Qualität | 10% | Schlechte Scans, Handschrift, Verdrehung |
| Mehrsprachigkeit | 5% | Englische E-Mail korrekt verarbeitet |
| Plausibilität | 5% | Fehlerhafte Summen, unrealistische Werte |
| Rückfragen | 5% | Sinnvolle Rückfragen bei Unklarheiten |

## Mindestanforderungen (Bestanden ab)

- Gebäude: 7/7 erkannt (100%)
- Fenster: mindestens 50/64 aus Listen korrekt (78%)
- Konflikte: mindestens 5/8 erkannt (63%)
- Rückfragen: mindestens 3/6 gestellt (50%)
- OCR: mindestens 2/3 Dokumente lesbar verarbeitet (67%)
- Gesamt-Score: mindestens 70%
