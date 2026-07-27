# Arbeitsabläufe (Workflows) – Fensterbeschlagsprüfung BMVg Bonn

## 1. Hauptworkflow: Fensterprüfung

```
Login
  │
  ▼
Dashboard (Übersicht)
  │
  ├──────────────────────────────────────────┐
  ▼                                          ▼
Gebäude                                  Auswertung (Filteransicht)
  │                                          │
  ▼                                          ▼
Etagen                                   Export (CSV/Excel)
  │
  ▼
Räume
  │
  ▼
Fenster
  │
  ▼
Flügel (Fensterflügel)
  │
  ▼
Prüfformular
  │
  ├── Beschlagsdaten erfassen
  ├── Maße dokumentieren
  ├── Mängel markieren
  ├── Fotos hochladen
  ├── Berechnungen durchführen
  │
  ▼
Flügel abschließen
  │
  ▼
Alle Flügel geprüft?
  │
  ├── Nein → nächster Flügel
  │
  ▼ Ja
Fenster als „geprüft" markieren
  │
  ▼
Freigabe durch Sachverständigen
```

---

## 2. Workflow: Gebäude-Hierarchie anlegen

```
Dashboard
  │
  ▼
Gebäude → „+ Gebäude anlegen" (Name + Kürzel)
  │
  ▼
Etagen → „+ Etage anlegen" (Name + Geschoss-Nr.)
  │
  ▼
Räume → „+ Raum anlegen" (Name + Raumnummer)
  │
  ▼
Fenster → „+ Fenster anlegen" (Fensternummer)
  │
  ▼
Flügel → „+ Flügel anlegen" (Nummer + Typ)
```

---

## 3. Workflow: KI-Dokumentenimport (nur Admin/Prüfer)

```
KI-Import Seite
  │
  ▼
Datei hochladen (Drag&Drop oder Klick)
  │  Erlaubt: PDF, JPG, PNG, TIFF, Excel, CSV, Word
  │
  ▼
KI analysiert Dokument (GPT-4o Vision, ~10-30 Sek.)
  │
  ▼
Ergebnis-Vorschau wird angezeigt:
  ├── 📥 Neue Datensätze (vorausgewählt)
  ├── 🔄 Ergänzungen (vorausgewählt)
  ├── ⚠️ Konflikte (nicht vorausgewählt – bewusste Entscheidung nötig)
  ├── ✅ Bereits vorhanden (nur Info)
  │
  ▼
Benutzer wählt ab/an
  │
  ▼
„Ausgewählte übernehmen" klicken
  │
  ▼
Daten werden in DB angelegt/ergänzt
  │  NIEMALS: automatisches Überschreiben
  │
  ▼
Zusammenfassung: X angelegt, Y ergänzt, Z übersprungen, W Fehler
```

---

## 4. Workflow: Benutzerverwaltung (nur Admin)

```
Benutzerverwaltung
  │
  ├── Benutzer anlegen (Name, E-Mail, Rolle, Passwort)
  ├── Benutzer bearbeiten (Name, Rolle)
  ├── Passwort ändern
  ├── Benutzer deaktivieren
  │
  ▼
Änderungen sofort wirksam
```

---

## 5. Workflow: Datensatzsperre (Record Locking)

```
Flügel öffnen zur Bearbeitung
  │
  ▼
Lock anfordern (API: acquireLock)
  │
  ├── Erfolg → Bearbeitung möglich (15 Min. Sperre)
  │     │
  │     ▼
  │   Speichern → Lock wird erneuert
  │     │
  │     ▼
  │   Schließen → Lock wird freigegeben
  │
  ├── Fehlschlag → anderer Benutzer bearbeitet
  │     │
  │     ▼
  │   Hinweis: „Gesperrt durch [Name] bis [Zeit]"
  │   (Nur-Lese-Modus)
```

---

## 6. Workflow: Foto-Dokumentation

```
Flügel-Prüfformular
  │
  ▼
Foto-Bereich öffnen
  │
  ├── Foto hochladen (Kamera oder Datei)
  │     ├── Kategorie wählen (Übersicht, Beschlag, Mangel, Detail)
  │     ├── Beschriftung eingeben
  │     └── Hochladen
  │
  ├── Fotos anzeigen (Galerie)
  │
  └── Foto löschen
```

---

## 7. Workflow: Export

```
Export-Seite
  │
  ▼
Export-Vorlage wählen:
  ├── Alle Fenster
  ├── Nur abgeschlossene
  ├── Nur mit Mängeln
  ├── Nur dringende
  │
  ▼
Filter anwenden (optional)
  │
  ▼
CSV/Excel herunterladen
  │
  ▼
Export wird im Audit-Log protokolliert
```

---

## 8. Workflow: Bearbeiten/Löschen (Aktionsmenü ⋮)

```
Beliebiges Element (Gebäude/Etage/Raum/Fenster)
  │
  ▼
Aktionsmenü (⋮) klicken
  │
  ├── Bearbeiten → Dialog mit Formular → Speichern
  │
  └── Löschen → Bestätigungsdialog
       │  Warnung: Kaskadierende Löschung!
       │  (z.B. „Gebäude löschen entfernt alle Etagen, Räume und Fenster")
       │
       ▼
     Bestätigen → Element wird gelöscht
```

---

## Berechtigungsmatrix pro Workflow

| Workflow | Admin | PL | SV | Prüfer | Auswertung | Gast |
|----------|:-----:|:--:|:--:|:------:|:----------:|:----:|
| Login | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Dashboard ansehen | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Hierarchie anlegen | ✓ | ✓ | ✓ | ✓ | ✗ | ✗ |
| Hierarchie bearbeiten | ✓ | ✓ | ✓ | ✓ | ✗ | ✗ |
| Hierarchie löschen | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ |
| Fenster prüfen | ✓ | ✓ | ✓ | ✓ | ✗ | ✗ |
| Fotos hochladen | ✓ | ✓ | ✓ | ✓ | ✗ | ✗ |
| Export | ✓ | ✓ | ✓ | ✓ | ✓ | ✗ |
| KI-Import | ✓ | ✗ | ✗ | ✓ | ✗ | ✗ |
| Benutzerverwaltung | ✓ | ✗ | ✗ | ✗ | ✗ | ✗ |
