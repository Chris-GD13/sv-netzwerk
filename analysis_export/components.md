# Komponentenübersicht – Fensterbeschlagsprüfung BMVg Bonn

## Architektur-Überblick

```
┌─────────────────────────────────────────────────────────────────┐
│                        FRONTEND                                   │
│                                                                   │
│  Astro (Static Site Generator)                                   │
│  ├── InternalLayout.astro (Layout-Wrapper)                       │
│  ├── InternalAppShell.astro (SPA-Container)                      │
│  └── Astro-Seiten (je Route eine .astro Datei)                   │
│                                                                   │
│  Client-SPA (TypeScript)                                         │
│  ├── client.ts (~2900 Zeilen, Router + alle Views)              │
│  ├── php-api.ts (API-Client-Funktionen)                          │
│  ├── types.ts (TypeScript-Interfaces)                            │
│  ├── schema.ts (Prüfformular-Felddefinitionen)                  │
│  ├── calculations.ts (Gewichtsberechnungen)                      │
│  └── offline.ts (Offline-Speicher / IndexedDB)                   │
│                                                                   │
│  CSS                                                              │
│  └── internal-portal.css (Portal-Styles)                         │
└─────────────────────────────────────────────────────────────────┘
                           │ HTTP/JSON
                           ▼
┌─────────────────────────────────────────────────────────────────┐
│                        BACKEND (PHP 8.x)                          │
│                                                                   │
│  config.php (Konfiguration, DB-Verbindung, Hilfsfunktionen)     │
│  auth.php (Login/Logout/Session)                                 │
│  windows.php (Fenster CRUD + Dashboard)                          │
│  sashes.php (Flügel CRUD + Prüfdaten)                           │
│  hierarchy.php (Gebäude/Etagen/Räume CRUD)                      │
│  photos.php (Foto-Upload + Verwaltung)                           │
│  locks.php (Record Locking)                                      │
│  users.php (Benutzerverwaltung)                                  │
│  exports.php (CSV-Export)                                         │
│  parameters.php (Berechnungsparameter)                           │
│  ai-import.php (KI-Dokumentenimport)                             │
│  demo.php (Demo-Daten Seeding)                                   │
│  setup.php (Schema-Installation)                                 │
│                                                                   │
│  services/                                                        │
│  └── AiService.php (Zentraler KI-Service / OpenAI)              │
└─────────────────────────────────────────────────────────────────┘
                           │ PDO/MySQL
                           ▼
┌─────────────────────────────────────────────────────────────────┐
│                     DATENBANK (MySQL 8.0)                          │
│  13 Tabellen (siehe database_schema.md)                           │
└─────────────────────────────────────────────────────────────────┘
```

---

## Frontend-Komponenten

### 1. `InternalLayout.astro`
- **Verantwortung:** HTML-Grundgerüst, Meta-Tags, CSS-Einbindung
- **Abhängigkeiten:** internal-portal.css
- **Wiederverwendung:** Alle Portal-Seiten

### 2. `InternalAppShell.astro`
- **Verantwortung:** SPA-Container mit `data-route` Attribut, Script-Einbindung
- **Abhängigkeiten:** client.ts
- **Wiederverwendung:** Alle SPA-Routen
- **Props:** `route: PortalRoute`

### 3. `client.ts` (Hauptkomponente, ~2900 Zeilen)
- **Verantwortung:** Router, View-Rendering, Event-Handling, State-Management
- **Abhängigkeiten:** php-api.ts, types.ts, schema.ts, calculations.ts, offline.ts
- **Unterkomponenten (Funktionen):**
  - `renderDashboard()` – Dashboard mit Statistiken
  - `renderBuildings()` – Gebäudeliste
  - `renderFloors()` – Etagenliste
  - `renderRooms()` – Raumliste
  - `renderWindowsInRoom()` – Fensterliste
  - `renderSashes()` – Flügelliste
  - `renderSash()` – Prüfformular
  - `renderAnalysis()` – Auswertung/Filter
  - `renderExport()` – Export
  - `renderAiImport()` – KI-Import
  - `renderAdmin()` – Benutzerverwaltung
  - `renderHeader()` – Navigation/Header
  - `bindEntityActions()` – Aktionsmenü-Handler

### 4. `php-api.ts`
- **Verantwortung:** Typsichere API-Client-Funktionen
- **Abhängigkeiten:** types.ts
- **Exportiert:** ~40 Funktionen (CRUD für alle Entitäten)

### 5. `types.ts`
- **Verantwortung:** TypeScript-Interface-Definitionen
- **Exportiert:** 20+ Interfaces und Typen

### 6. `schema.ts`
- **Verantwortung:** Formular-Felddefinitionen für Prüfformular
- **Struktur:** Sektionen → Felder (Text, Number, Select, Checkbox)

### 7. `calculations.ts`
- **Verantwortung:** Glasgewicht- und Beschlagsberechnungen
- **Abhängigkeiten:** types.ts (CalculationParameterMap)

### 8. `offline.ts`
- **Verantwortung:** IndexedDB für Offline-Entwürfe
- **Funktionen:** Speichern, Laden, Sync-Status

---

## Backend-Komponenten

### 1. `config.php`
- **Verantwortung:** .env laden, DB-Verbindung, Session, Hilfsfunktionen
- **Exportiert:** `db()`, `env()`, `apiJson()`, `apiError()`, `requireAuth()`, `currentUser()`
- **Abhängigkeiten:** .env Datei (Umgebungsvariablen)

### 2. `auth.php`
- **Verantwortung:** Login, Logout, Session-Prüfung, Passwort-Hash
- **Endpunkte:** `?action=login`, `?action=logout`, `?action=me`
- **Sicherheit:** Argon2ID, Session-Cookie (HttpOnly, Secure, SameSite)

### 3. `hierarchy.php`
- **Verantwortung:** CRUD für Gebäude, Etagen, Räume, Fenster
- **Endpunkte:** `?action=buildings|floors|rooms|windows` + create/update/delete
- **Berechtigungen:** Lesen=alle, Schreiben=canEdit-Rollen, Löschen=Admin

### 4. `sashes.php`
- **Verantwortung:** CRUD für Fensterflügel + Prüfdaten
- **Endpunkte:** `?action=list|get|create|save|delete`

### 5. `photos.php`
- **Verantwortung:** Foto-Upload, Listing, Löschen
- **Speicher:** Dateisystem unter `/intern/photos/`
- **Sicherheit:** MIME-Typ-Prüfung, Größenbegrenzung

### 6. `locks.php`
- **Verantwortung:** Pessimistisches Locking (15-Min-Sperren)
- **Endpunkte:** `?action=acquire|release|check`

### 7. `services/AiService.php`
- **Verantwortung:** Zentraler KI-Service für alle OpenAI-API-Aufrufe
- **Methoden:** `analyzeDocument()`, `detectDefects()`, `ocrTypeLabel()`, `identifyHardware()`
- **Abhängigkeiten:** OPENAI_API_KEY (Umgebungsvariable)
- **Zukunft:** Erweiterbar für weitere KI-Funktionen

### 8. `ai-import.php`
- **Verantwortung:** Dokumentenimport mit KI-Analyse + Datenübernahme
- **Endpunkte:** `?action=analyze|apply`
- **Berechtigungen:** Nur Admin + Prüfer
- **Logik:** Analyse → Klassifizierung (new/update/conflict/exists) → Apply

---

## Abhängigkeitsgraph

```
client.ts
  ├── php-api.ts
  │     └── types.ts
  ├── schema.ts
  ├── calculations.ts
  │     └── types.ts
  └── offline.ts
        └── types.ts

ai-import.php
  ├── config.php
  └── services/AiService.php

hierarchy.php ── config.php
windows.php ─── config.php
sashes.php ──── config.php
photos.php ──── config.php
locks.php ───── config.php
users.php ───── config.php
exports.php ─── config.php
auth.php ────── config.php
```
