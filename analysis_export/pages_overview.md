# Seitenübersicht – Fensterbeschlagsprüfung BMVg Bonn

## Portalseiten (SPA-Routen)

Das Portal ist eine Single-Page-Application (SPA) basierend auf Astro + TypeScript.
Der Client-Router (client.ts) steuert alle Ansichten über das `data-route` Attribut.

---

### 1. Landing Page
- **URL:** `/intern/fensterpruefung-bonn/`
- **Route:** `landing`
- **Zweck:** Startseite mit Projektinfo, Login-Weiterleitung
- **Rollen:** Alle (auch unangemeldet)
- **Komponenten:** Projektbeschreibung, Login-Button
- **Datenquellen:** Keine (statisch)
- **Aktionen:** Weiterleitung zum Login

### 2. Login
- **URL:** `/intern/login/`
- **Route:** `login`
- **Zweck:** Benutzeranmeldung
- **Rollen:** Alle (unangemeldet)
- **Komponenten:** Login-Formular (E-Mail + Passwort)
- **Datenquellen:** API `/api/auth.php?action=login`
- **Formulare:** E-Mail, Passwort
- **Aktionen:** Login, Fehlermeldung bei falschen Daten
- **Verlinkung:** → Dashboard nach Erfolg

### 3. Dashboard
- **URL:** `/intern/fensterpruefung-bonn/` (nach Login)
- **Route:** `dashboard`
- **Zweck:** Projektübersicht mit Statistiken und Schnellzugriff
- **Rollen:** Alle angemeldeten Benutzer
- **Komponenten:** Statistik-Karten, letzte Änderungen, Prüfer-Übersicht
- **Datenquellen:** API `/api/windows.php?action=dashboard`
- **Aktionen:** Navigation zu Gebäuden, Fenstern, Export

### 4. Gebäude
- **URL:** `/intern/fensterpruefung-bonn/gebaeude/`
- **Route:** `buildings`
- **Zweck:** Gebäudeliste des Projekts
- **Rollen:** Alle angemeldeten
- **Komponenten:** Gebäude-Karten mit Fortschrittsbalken, Aktionsmenü (⋮)
- **Datenquellen:** API `/api/hierarchy.php?action=buildings`
- **Formulare:** Gebäude anlegen (Name + Kürzel)
- **Aktionen:** Anlegen, Bearbeiten, Löschen (Admin), Öffnen → Etagen
- **Verlinkung:** → Etagen des gewählten Gebäudes

### 5. Etagen
- **URL:** `/intern/fensterpruefung-bonn/gebaeude/?building={id}`
- **Route:** `floors`
- **Zweck:** Etagenliste eines Gebäudes
- **Rollen:** Alle angemeldeten
- **Komponenten:** Etagen-Karten, Breadcrumb, Aktionsmenü
- **Datenquellen:** API `/api/hierarchy.php?action=floors&building_id={id}`
- **Formulare:** Etage anlegen (Name + Geschoss)
- **Aktionen:** Anlegen, Bearbeiten, Löschen, Öffnen → Räume
- **Verlinkung:** ← Gebäude, → Räume

### 6. Räume
- **URL:** `/intern/fensterpruefung-bonn/gebaeude/?floor={id}`
- **Route:** `rooms`
- **Zweck:** Raumliste einer Etage
- **Rollen:** Alle angemeldeten
- **Komponenten:** Raum-Karten, Breadcrumb, Aktionsmenü
- **Datenquellen:** API `/api/hierarchy.php?action=rooms&floor_id={id}`
- **Formulare:** Raum anlegen (Name + Raumnummer)
- **Aktionen:** Anlegen, Bearbeiten, Löschen, Öffnen → Fenster
- **Verlinkung:** ← Etagen, → Fenster

### 7. Fenster (in Raum)
- **URL:** `/intern/fensterpruefung-bonn/gebaeude/?room={id}`
- **Route:** `windows`
- **Zweck:** Fensterliste eines Raums
- **Rollen:** Alle angemeldeten
- **Komponenten:** Fenster-Karten mit Status-Badge, Aktionsmenü
- **Datenquellen:** API `/api/hierarchy.php?action=windows&room_id={id}`
- **Formulare:** Fenster anlegen (Fensternummer)
- **Aktionen:** Anlegen, Löschen, Öffnen → Flügel
- **Verlinkung:** ← Räume, → Flügel

### 8. Flügel (Sashes)
- **URL:** `/intern/fensterpruefung-bonn/fenster/?window={id}`
- **Route:** `sashes`
- **Zweck:** Flügelliste eines Fensters
- **Rollen:** Alle angemeldeten
- **Komponenten:** Flügel-Karten, Datensatzsperre, Aktionsmenü
- **Datenquellen:** API `/api/sashes.php?action=list&window_id={id}`
- **Formulare:** Flügel anlegen
- **Aktionen:** Anlegen, Öffnen zur Prüfung
- **Verlinkung:** ← Fenster, → Prüfformular (Sash)

### 9. Prüfformular (Flügel)
- **URL:** `/intern/fensterpruefung-bonn/fenster/?sash={id}`
- **Route:** `sash`
- **Zweck:** Detaillierte Prüfung eines Fensterflügels
- **Rollen:** Administrator, Projektleiter, Sachverständiger, Prüfer
- **Komponenten:** Mehrstufiges Formular, Foto-Upload, Berechnungen, Status
- **Datenquellen:** API `/api/sashes.php?action=get&id={id}`
- **Formulare:** Beschlag-Prüfung (10+ Sektionen), Fotodokumentation
- **Aktionen:** Speichern, Flügel abschließen, Fotos hochladen/löschen
- **Verlinkung:** ← Flügel-Liste

### 10. Auswertung
- **URL:** `/intern/fensterpruefung-bonn/auswertung/`
- **Route:** `analysis`
- **Zweck:** Filterbare Gesamtübersicht aller Fenster
- **Rollen:** Alle angemeldeten
- **Komponenten:** Filter (Gebäude, Status, Priorität), Tabelle, Statistiken
- **Datenquellen:** API `/api/windows.php?action=list`
- **Aktionen:** Filtern, Sortieren, Fenster öffnen
- **Verlinkung:** → Einzelnes Fenster (record)

### 11. Export
- **URL:** `/intern/fensterpruefung-bonn/export/`
- **Route:** `export`
- **Zweck:** Datenexport als CSV/Excel
- **Rollen:** Alle angemeldeten
- **Komponenten:** Export-Vorlagen, Filter-Optionen
- **Datenquellen:** API `/api/exports.php`
- **Aktionen:** CSV-Download auslösen
- **Verlinkung:** -

### 12. KI-Dokumentenimport
- **URL:** `/intern/fensterpruefung-bonn/import/`
- **Route:** `ai-import`
- **Zweck:** Automatische Datenerfassung aus Dokumenten via GPT-4o
- **Rollen:** Nur Administrator, Prüfer
- **Komponenten:** Drag&Drop-Upload, KI-Analyse-Status, Ergebnis-Vorschau, Übernahme-Bestätigung
- **Datenquellen:** API `/api/ai-import.php`
- **Formulare:** Datei-Upload (PDF/JPG/PNG/TIFF/Excel/CSV/Word)
- **Aktionen:** Hochladen, Analyse starten, Vorschau prüfen, Daten übernehmen
- **Verlinkung:** -

### 13. Benutzerverwaltung
- **URL:** `/intern/fensterpruefung-bonn/admin/`
- **Route:** `admin`
- **Zweck:** Benutzer anlegen, bearbeiten, deaktivieren
- **Rollen:** Nur Administrator
- **Komponenten:** Benutzertabelle, Anlegen-Dialog, Rollen-Dropdown
- **Datenquellen:** API `/api/users.php`
- **Formulare:** Benutzer anlegen/bearbeiten (Name, E-Mail, Rolle, Passwort)
- **Aktionen:** Anlegen, Bearbeiten, Passwort ändern, Deaktivieren
- **Verlinkung:** -

---

## Statische Seiten (Astro)

| URL | Zweck |
|-----|-------|
| `/intern/` | Portal-Einstieg (Weiterleitung zum Login) |
| `/intern/login/` | Login-Seite |
| `/intern/fensterpruefung-bonn/` | SPA-Container (alle dynamischen Routen) |
| `/intern/fensterpruefung-bonn/gebaeude/` | SPA: Gebäude |
| `/intern/fensterpruefung-bonn/auswertung/` | SPA: Auswertung |
| `/intern/fensterpruefung-bonn/fenster/` | SPA: Fenster/Flügel |
| `/intern/fensterpruefung-bonn/fenster/record/` | SPA: Einzelnes Fenster |
| `/intern/fensterpruefung-bonn/export/` | SPA: Export |
| `/intern/fensterpruefung-bonn/import/` | SPA: KI-Import |
| `/intern/fensterpruefung-bonn/admin/` | SPA: Benutzerverwaltung |
