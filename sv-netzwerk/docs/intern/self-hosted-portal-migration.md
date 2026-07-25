# Migrationsdokumentation: Supabase → Self-hosted PHP/MariaDB

**Status:** Planung abgeschlossen — Implementierung blockiert bis IONOS-Datenbankverbindung bestätigt ist  
**Erstellt:** 2026-07-25  
**Betrifft:** Internes Prüfportal `/intern/` auf sv-netzwerk.eu

---

## Inhaltsverzeichnis

1. [Phase-1-Ergebnis: Hosting-Verifikation](#1-phase-1-ergebnis-hosting-verifikation)
2. [Aktuelle Architektur (Supabase)](#2-aktuelle-architektur-supabase)
3. [Zielarchitektur (Self-hosted)](#3-zielarchitektur-self-hosted)
4. [Datenbankmodell-Mapping](#4-datenbankmodell-mapping)
5. [Routen-Mapping](#5-routen-mapping)
6. [Migrationsphasen](#6-migrationsphasen)
7. [Sicherheitskontrollen](#7-sicherheitskontrollen)
8. [Rollback-Verfahren](#8-rollback-verfahren)
9. [Deployment-Verfahren](#9-deployment-verfahren)
10. [Offene Risiken](#10-offene-risiken)
11. [Ausstehende manuelle Schritte](#11-ausstehende-manuelle-schritte)

---

## 1 Phase-1-Ergebnis: Hosting-Verifikation

### Aus dem Repository bestätigt

| Merkmal | Status | Quelle |
|---------|--------|--------|
| PHP vorhanden | ✅ Bestätigt | `public/form-handler-core.php`, `public/anfrage.php` sind live in Produktion |
| HTTPS | ✅ Bestätigt | `.htaccess` erzwingt Redirect auf `https://www.sv-netzwerk.eu` |
| `.htaccess`-Routing | ✅ Bestätigt | Komplexe RewriteRules inkl. `/intern/`-Pfade in `public/.htaccess` |
| SFTP-Dateizugriff | ✅ Bestätigt | Deploy-Workflow lädt via `lftp` nach `/sv-netzwerk` |
| PHP `mail()` | ✅ Bestätigt | Kontaktformular-Handler nutzen PHP-Mail-Versand aktiv |
| Persistenter Dateispeicher | ✅ Bestätigt | SFTP-Inhalt verbleibt zwischen Deployments |

### Nicht aus dem Repository bestätigbar

| Merkmal | Status | Warum unklar | Erforderliche Aktion |
|---------|--------|--------------|----------------------|
| PHP-Version (≥ 8.2) | ❌ Unbekannt | Kein `.php-version`, kein phpinfo, keine CI-Ausgabe | IONOS Control Panel → PHP-Version prüfen |
| MariaDB/MySQL | ❌ Unbekannt | Kein DB-Verbindungscode in keiner PHP-Datei | IONOS Control Panel → Datenbanken prüfen |
| PHP Sessions | ❓ Wahrscheinlich | PHP vorhanden; bestehende Handler nutzen keine Sessions | Einfacher Test: `<?php session_start(); echo session_id();` |
| Datei-Uploads via PHP | ❓ Wahrscheinlich | PHP-Upload-Handling ist Standard, aber `upload_max_filesize` unbekannt | `phpinfo()` im geschützten Bereich prüfen |
| Cronjobs | ❌ Unbekannt | Nicht dokumentiert | IONOS Control Panel → Cronjob-Sektion |
| Schreibbarer Upload-Pfad außerhalb Webroot | ❌ Unbekannt | Kein Hinweis auf Verzeichnisstruktur oberhalb `/sv-netzwerk` | SFTP: Elternverzeichnis erkunden |

**⛔ Phase 3–10 sind blockiert, bis MariaDB/MySQL-Verfügbarkeit und PHP-Version ≥ 8.2 durch den IONOS-Control-Panel-Nachweis bestätigt wurden.**

### Bestätigungsprozedur für den Administrator

1. IONOS Control Panel öffnen: [my.ionos.de](https://my.ionos.de)
2. Domain `sv-netzwerk.eu` → Hosting → PHP-Version ablesen
3. Datenbanken → MySQL-Datenbanken → vorhanden ja/nein
4. Wenn keine DB vorhanden: neue MySQL-Datenbank anlegen und Zugangsdaten notieren
5. Ergebnis in `docs/intern/ionos-hosting-nachweis.md` dokumentieren (keine Passwörter)

---

## 2 Aktuelle Architektur (Supabase)

### Übersicht

```
Browser (Astro static + TypeScript)
    │
    ├── Supabase Auth (JWT via localStorage / svjs-auth-storage)
    │       AUTH-Methoden: signInWithPassword, onAuthStateChange, signOut
    │
    ├── Supabase PostgREST (REST-API über anon key)
    │       Tabellenzugriffe: windows, profiles, audit_logs, photos,
    │                         record_locks, calculation_parameters,
    │                         export_logs, projects, buildings
    │
    ├── Supabase Storage (privater Bucket: window-photos-private)
    │       upload, remove, createSignedUrl
    │
    └── Supabase Realtime (WebSocket)
            Abonnements: windows, record_locks, audit_logs, photos
```

### Umgebungsvariablen (aktuell)

| Variable | Zweck |
|----------|-------|
| `PUBLIC_SUPABASE_URL` | Supabase-Projekt-URL (im Browser sichtbar) |
| `PUBLIC_SUPABASE_ANON_KEY` | Anon-Key (im Browser sichtbar, durch RLS gesichert) |

### Authentifizierungsfluss (aktuell)

1. Nutzer öffnet `/intern/login/`
2. `InternalAppShell.astro` lädt `client.ts` → `mountInternalPortal()`
3. `getSupabaseBrowserClient()` erzeugt Supabase-Client mit anon key
4. `supabase.auth.signInWithPassword({ email, password })` → JWT in localStorage
5. `supabase.auth.onAuthStateChange()` steuert Routenwechsel
6. Jede API-Anfrage trägt JWT als Bearer-Token; Supabase validiert via RLS
7. Logout: `supabase.auth.signOut()` löscht JWT aus localStorage

### Rollen

| Rolle | Rechte |
|-------|--------|
| `administrator` | Vollzugriff, Benutzerverwaltung, Freigabe |
| `pruefer` | Fenster lesen/schreiben, Fotos hochladen, eigene Datensätze |
| `auswertung` | Lesen + Status-/Bewertungsfelder schreiben, kein Delete |

### Supabase-Tabellen (vollständig)

| Tabelle | Zweck |
|---------|-------|
| `auth.users` | Supabase-eigene Nutzertabelle (UUID, E-Mail, Password-Hash) |
| `profiles` | Erweiterung von `auth.users`: Name, Rolle, is_active |
| `projects` | Projektdaten (BMVg Bonn) |
| `buildings` | Gebäude eines Projekts |
| `building_sections` | Bauabschnitte/Trakte |
| `floors` | Etagen |
| `rooms` | Räume |
| `windows` | Hauptdatensatz: Fensterdaten + `form_data` JSONB + `calculated_data` JSONB |
| `window_wings` | Flügeldaten (Maße, Gewicht) |
| `glazing_data` | Verglasungsdaten pro Fenster (1:1) |
| `hardware_components` | Beschlag-Komponenten (n:1 Fenster) |
| `functional_tests` | Funktionsprüfungsdaten pro Fenster (1:1) |
| `findings` | Befunde/Mängel |
| `recommendations` | Empfehlungen |
| `photos` | Foto-Metadaten (Dateiname, Kategorie, Prüfer, Zeitstempel) |
| `record_locks` | Optimistische Datensatzsperren (1:1 Fenster) |
| `audit_logs` | Unveränderliche Änderungshistorie |
| `calculation_parameters` | Globale Berechnungskonstanten |
| `export_logs` | Export-Protokoll |

### Supabase Storage

| Bucket | Sichtbarkeit | Inhalt |
|--------|-------------|--------|
| `window-photos-private` | Privat (RLS) | Fotos zu Fensterdatensätzen |

### Realtime-Abonnements

- `windows` – Sperranzeige, Live-Statusupdates
- `record_locks` – Sperrindikator in der Fensterliste
- `audit_logs` – Live-Audit-Feed in der Detailansicht
- `photos` – Live-Galerie-Aktualisierung

### RPC-Funktionen (serverseitig in Supabase)

| Funktion | Zweck |
|---------|-------|
| `acquire_record_lock(p_window_id, p_timeout_minutes)` | Datensatz sperren |
| `release_record_lock(p_window_id)` | Sperre freigeben |
| `is_admin()` | Rollenprüfung für RLS |
| `is_project_member()` | Projektmitgliedschaft für RLS |
| `log_window_changes()` | Trigger: Audit-Log-Eintrag bei Fenster-Update |
| `set_updated_at()` | Trigger: `updated_at` automatisch setzen |

### Export (aktuell)

- CSV-Export: clientseitig im Browser, Download via `Blob`-URL
- PDF: Browser-Druckfunktion (`window.print()`)
- QR-Code-Übersicht: clientseitig via `qrcode`-npm-Paket

### Bekannte Schwachstellen der aktuellen Architektur

- Anon-Key und Supabase-URL sind im Browser-Build sichtbar
- Auth-State liegt nur in localStorage (kein HttpOnly-Cookie)
- Kein CSRF-Schutz (Supabase nutzt JWT-Bearer, kein Cookie)
- Kein serverseitiges Rate-Limiting für Login-Versuche
- Passwort-Reset via Supabase-E-Mail (externe Abhängigkeit)
- Realtime erfordert WebSocket-Verbindung zu supabase.co

---

## 3 Zielarchitektur (Self-hosted)

### Übersicht

```
Browser (Astro static + TypeScript)
    │
    └── Same-Origin API: /intern-api/  (PHP 8.2+ auf IONOS)
            │
            ├── Authentifizierung: PHP Sessions (HttpOnly, Secure, SameSite=Lax)
            ├── Autorisierung: Rollen-Middleware (administrator, pruefer, auswertung)
            ├── Datenbank: MariaDB/MySQL (PDO, Prepared Statements)
            ├── Datei-Uploads: Server-seitig, außerhalb Webroot
            └── Exporte: CSV serverseitig, HTML/PDF über PHP-Bibliothek
```

### Verzeichnisstruktur (Ziel)

```
sv-netzwerk/public/intern-api/
├── index.php                  # Router / Front-Controller
├── .htaccess                  # Routing auf index.php, PHP-Direktiven
├── config/
│   ├── config.php             # Konfigurationsvalidierung und Konstanten
│   └── database.php           # PDO-Datenbankverbindung
├── middleware/
│   ├── auth.php               # Session-Prüfung
│   └── role.php               # Rollenprüfung
├── controllers/
│   ├── AuthController.php     # login, logout, session
│   ├── WindowController.php   # CRUD Fensterdatensätze
│   ├── PhotoController.php    # Upload, Download, Löschen
│   ├── ExportController.php   # CSV, HTML-Report
│   ├── UserController.php     # Benutzerverwaltung (admin only)
│   ├── AuditController.php    # Audit-Log-Abfragen
│   └── StatsController.php    # Dashboard-Statistiken
├── models/
│   ├── User.php
│   ├── Window.php
│   ├── Photo.php
│   └── AuditLog.php
├── services/
│   ├── AuthService.php        # Login-Logik, Session-Rotation
│   ├── UploadService.php      # MIME-Prüfung, Dateinamenerzeugung
│   └── ExportService.php      # CSV-Erzeugung, HTML-Report
├── migrations/
│   └── 001_initial_schema.sql # Vollständiges MariaDB-Schema
└── storage/                   # Konfigurationshinweis auf Upload-Pfad
```

### Neue Umgebungsvariablen (.env, nie committen)

```env
DB_HOST=localhost
DB_PORT=3306
DB_NAME=sv_intern
DB_USER=
DB_PASSWORD=
APP_SECRET=
UPLOAD_PATH=/pfad/ausserhalb/webroot/uploads
APP_ENV=production
SESSION_LIFETIME_MINUTES=480
SESSION_ABSOLUTE_HOURS=12
```

---

## 4 Datenbankmodell-Mapping

### Mapping Supabase → MariaDB

Supabase nutzt UUIDs als Primary Keys (PostgreSQL `gen_random_uuid()`).  
MariaDB nutzt `CHAR(36)` mit `UUID()` oder `BIN(16)` mit `UUID_TO_BIN()`.  
Für Einfachheit und Kompatibilität: `CHAR(36) NOT NULL DEFAULT (UUID())`.

| Supabase-Tabelle | MariaDB-Tabelle | Anmerkung |
|-----------------|----------------|-----------|
| `auth.users` | `users` | Vollständig neu implementiert; kein externer Auth-Provider |
| `profiles` | Felder in `users` integriert | `full_name`, `role`, `is_active`, `password_hash` |
| `projects` | `projects` | Identisch |
| `buildings` | `buildings` | Identisch |
| `building_sections` | `building_sections` | Identisch |
| `floors` | `floors` | Identisch |
| `rooms` | `rooms` | Identisch |
| `windows` | `windows` | `form_data` und `calculated_data` als `JSON`-Spalten |
| `window_wings` | `window_wings` | Identisch |
| `glazing_data` | `glazing_data` | Identisch |
| `hardware_components` | `hardware_components` | Identisch |
| `functional_tests` | `functional_tests` | Identisch |
| `findings` | `findings` | Identisch |
| `recommendations` | `recommendations` | Identisch |
| `photos` | `photos` | `storage_path` → lokaler Dateipfad |
| `record_locks` | `record_locks` | Identisch; RPC ersetzt durch PHP-Transaktion |
| `audit_logs` | `audit_logs` | `ip_address` wird hinzugefügt |
| `calculation_parameters` | `calculation_parameters` | Identisch |
| `export_logs` | `export_logs` | Identisch |

Zusätzliche Tabellen ohne Supabase-Äquivalent:

| Neue Tabelle | Zweck |
|-------------|-------|
| `login_attempts` | Rate-Limiting für Login-Versuche |
| `password_reset_tokens` | Passwort-Reset ohne externen Provider |
| `sessions` | Optional: serverseitige Session-Tabelle |

### Neue `users`-Tabelle (MariaDB)

```sql
CREATE TABLE users (
  id           CHAR(36)     NOT NULL DEFAULT (UUID()) PRIMARY KEY,
  email        VARCHAR(254) NOT NULL UNIQUE,
  full_name    VARCHAR(200) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role         ENUM('administrator','pruefer','auswertung') NOT NULL DEFAULT 'pruefer',
  is_active    TINYINT(1)   NOT NULL DEFAULT 1,
  created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

---

## 5 Routen-Mapping

### Frontend-Routen (unverändert)

| Route | Astro-Seite | Funktion |
|-------|-------------|----------|
| `/intern/` | `src/pages/intern/index.astro` | Weiterleitung Login/Dashboard |
| `/intern/login/` | `src/pages/intern/login/index.astro` | Anmeldeformular |
| `/intern/dashboard/` | `src/pages/intern/index.astro` | Dashboard-Shell |
| `/intern/fensterpruefung-bonn/` | `src/pages/intern/fensterpruefung-bonn/index.astro` | Fensterliste |
| `/intern/fensterpruefung-bonn/fenster/` | `src/pages/intern/fensterpruefung-bonn/fenster/index.astro` | Fensterdetails |
| `/intern/fensterpruefung-bonn/auswertung/` | `src/pages/intern/fensterpruefung-bonn/auswertung/index.astro` | Auswertung |
| `/intern/fensterpruefung-bonn/export/` | `src/pages/intern/fensterpruefung-bonn/export/index.astro` | Export |

### Neue Backend-API-Endpunkte

| Methode | Pfad | Zweck | Rolle |
|---------|------|-------|-------|
| POST | `/intern-api/auth/login` | Anmeldung | — |
| POST | `/intern-api/auth/logout` | Abmeldung | alle |
| GET | `/intern-api/auth/session` | Aktuelle Session | alle |
| GET | `/intern-api/users` | Benutzerliste | administrator |
| POST | `/intern-api/users` | Benutzer anlegen | administrator |
| PUT | `/intern-api/users/{id}` | Benutzer ändern | administrator |
| DELETE | `/intern-api/users/{id}` | Benutzer deaktivieren | administrator |
| GET | `/intern-api/projects` | Projektliste | alle |
| GET | `/intern-api/windows` | Fensterliste | alle |
| POST | `/intern-api/windows` | Fenster anlegen | pruefer, administrator |
| GET | `/intern-api/windows/{id}` | Fensterdetail | alle |
| PUT | `/intern-api/windows/{id}` | Fenster speichern | pruefer, auswertung, administrator |
| DELETE | `/intern-api/windows/{id}` | Fenster löschen | administrator |
| POST | `/intern-api/windows/{id}/lock` | Datensatz sperren | alle |
| DELETE | `/intern-api/windows/{id}/lock` | Sperre freigeben | alle |
| GET | `/intern-api/windows/{id}/audit` | Audit-Log | alle |
| GET | `/intern-api/windows/{id}/photos` | Fotoliste | alle |
| POST | `/intern-api/windows/{id}/photos` | Foto hochladen | pruefer, administrator |
| DELETE | `/intern-api/photos/{id}` | Foto löschen | pruefer, administrator |
| GET | `/intern-api/photos/{id}/file` | Foto abrufen (autorisiert) | alle |
| GET | `/intern-api/stats/dashboard` | Dashboard-Statistiken | alle |
| GET | `/intern-api/export/csv` | CSV-Export | alle |
| GET | `/intern-api/export/report` | HTML-Report | alle |
| GET | `/intern-api/calculation-parameters` | Berechnungsparameter | alle |

### Supabase-Aufrufe im Frontend (zu ersetzen)

Alle Aufrufe befinden sich in `sv-netzwerk/src/lib/internal/client.ts`.

| Supabase-Aufruf | Neuer API-Endpunkt |
|-----------------|-------------------|
| `supabase.auth.signInWithPassword()` | `POST /intern-api/auth/login` |
| `supabase.auth.signOut()` | `POST /intern-api/auth/logout` |
| `supabase.auth.getSession()` | `GET /intern-api/auth/session` |
| `supabase.auth.onAuthStateChange()` | Polling oder Session-Cookie-Prüfung |
| `supabase.from('windows').select()` | `GET /intern-api/windows` |
| `supabase.from('windows').upsert()` | `PUT /intern-api/windows/{id}` |
| `supabase.from('windows').insert()` | `POST /intern-api/windows` |
| `supabase.from('audit_logs').select()` | `GET /intern-api/windows/{id}/audit` |
| `supabase.from('photos').select()` | `GET /intern-api/windows/{id}/photos` |
| `supabase.from('photos').insert()` | Automatisch nach `POST /intern-api/windows/{id}/photos` |
| `supabase.from('record_locks').select()` | Eingebettet in `GET /intern-api/windows` |
| `supabase.from('calculation_parameters').select()` | `GET /intern-api/calculation-parameters` |
| `supabase.from('export_logs').insert()` | Automatisch beim Export |
| `supabase.storage.from('window-photos-private').upload()` | `POST /intern-api/windows/{id}/photos` |
| `supabase.storage.from('window-photos-private').remove()` | `DELETE /intern-api/photos/{id}` |
| `supabase.rpc('acquire_record_lock')` | `POST /intern-api/windows/{id}/lock` |
| `supabase.rpc('release_record_lock')` | `DELETE /intern-api/windows/{id}/lock` |
| `supabase.from('profiles').select()` | Eingebettet in Session-Response |

Realtime-Abonnements (kein direktes Äquivalent):

| Supabase Realtime | Ersatz |
|-------------------|--------|
| `windows`-Channel | Polling alle 30 s via `GET /intern-api/windows` |
| `record_locks`-Channel | Polling eingebettet in Fensterliste |
| `audit_logs`-Channel | Polling alle 15 s im Detaildatensatz |
| `photos`-Channel | Neu laden nach Upload/Löschen |

---

## 6 Migrationsphasen

### Schritt 1: Hosting-Bestätigung (manuell, vor Implementierung)

- PHP-Version ≥ 8.2 im IONOS Control Panel bestätigen
- MariaDB/MySQL-Datenbank anlegen; Host, Port, Name, User, Passwort notieren
- Schreibbaren Pfad außerhalb des Webroots für Foto-Uploads ermitteln
- Ergebnis in `docs/intern/ionos-hosting-nachweis.md` dokumentieren

### Schritt 2: Datenbankschema anlegen

- SQL-Migration `intern-api/migrations/001_initial_schema.sql` auf der neuen Datenbank ausführen
- Ersten Administrator-Nutzer anlegen (sicheres Verfahren, kein Passwort im Code)
- Schema durch `SELECT TABLE_NAME FROM information_schema.TABLES` verifizieren

### Schritt 3: PHP-Backend implementieren

- Verzeichnisstruktur unter `sv-netzwerk/public/intern-api/` anlegen
- `.env.example` bereitstellen; `.env` in `.gitignore` prüfen (bereits vorhanden)
- Konfigurationsvalidierung implementieren
- Session-Auth implementieren (Rotation nach Login, Timeout)
- CSRF-Token in alle POST/PUT/DELETE-Formulare einbinden
- JSON-API-Endpunkte implementieren (siehe Routen-Tabelle)
- Upload-Service mit MIME-Prüfung, Pfad-Traversal-Schutz
- Audit-Log-Trigger als PHP-Funktion (kein DB-Trigger erforderlich)

### Schritt 4: Staged Deployment unter `/intern-next/`

- PHP-Backend unter `/intern-next/intern-api/` bereitstellen
- Funktionstest ohne Produktionsdaten
- Alle Smoke-Tests durchführen (siehe Phase 9)

### Schritt 5: Datenmigration aus Supabase

- Aus Supabase-Konsole alle Tabellen als CSV/JSON exportieren
- Import-Skript `intern-api/migrations/import_from_supabase.php` ausführen
- Fotos aus Supabase Storage herunterladen und in IONOS-Pfad verschieben
- Datenkonsistenz prüfen (Fensteranzahl, Foto-Metadaten, Audit-Einträge)

### Schritt 6: Frontend-Umstellung

- `src/lib/internal/supabase.ts` → Stubs oder Entfernung
- `src/lib/internal/client.ts` → `fetch()`-Aufrufe auf `/intern-api/`
- `PUBLIC_SUPABASE_URL` und `PUBLIC_SUPABASE_ANON_KEY` aus Build entfernen
- `@supabase/supabase-js` aus `package.json` entfernen

### Schritt 7: Tests

- PHP-Syntax: `find public/intern-api -name '*.php' -exec php -l {} \;`
- Authentifizierung: Login, Logout, ungültige Anmeldedaten, Session-Timeout
- Autorisierung: Rolle `pruefer` kann nicht `/users` abrufen
- CSRF: POST ohne Token wird mit 403 abgelehnt
- Upload: ausführbare Datei (.php) wird abgelehnt
- Pfad-Traversal: `../../../etc/passwd` wird abgelehnt
- Unauthentifizierter Zugriff: alle API-Endpunkte liefern 401

### Schritt 8: Produktion umschalten

- Backup der aktuellen Supabase-Daten anlegen
- `.htaccess` anpassen: `/intern/` → neues Backend
- Deploy-Workflow aktualisieren: Supabase-Secrets entfernen
- Produktions-Smoke-Tests durchführen

---

## 7 Sicherheitskontrollen

### Authentifizierung

- Passwörter ausschließlich mit `password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12])` speichern
- Verifikation mit `password_verify()`
- Session-ID nach erfolgreichem Login rotieren: `session_regenerate_id(true)`
- Session-Cookie-Flags: `HttpOnly`, `Secure`, `SameSite=Lax`
- Inaktivitäts-Timeout: 480 Minuten (konfigurierbar)
- Absolutes Timeout: 12 Stunden
- Fehlermeldung generisch: „E-Mail oder Passwort ungültig" (kein Hinweis auf Existenz)
- Login-Versuche: nach 5 Fehlversuchen in 15 Minuten → 15 Minuten Sperre

### Autorisierung

- Jeder API-Endpunkt prüft Session und Rolle serverseitig
- Keine Rolle oder Berechtigung aus clientseitigem Code
- `administrator`-Endpunkte prüfen `role === 'administrator'` in der Middleware

### CSRF

- Doppeltes Cookie-Submit-Pattern oder synchronisiertes Token im Session
- Jedes POST/PUT/DELETE-Formular muss gültiges Token enthalten
- Token wird bei Session-Start erzeugt und mit der Session invalidiert

### Datei-Uploads

- Erlaubte MIME-Typen: `image/jpeg`, `image/png`, `image/webp`, `image/gif`
- MIME-Prüfung serverseitig via `finfo_file()` (nicht nur Content-Type-Header)
- Dateiname: `bin2hex(random_bytes(16)) . '.jpg'` (keine Original-Endung in Speicherung)
- Originaldateiname wird nur als Metadatum in der Datenbank gespeichert
- Maximale Dateigröße: 20 MB (konfigurierbar)
- Speicherort: außerhalb des Webroots, nicht von PHP ausführbar
- Download nur über autorisierten API-Endpunkt (`readfile()` + Access-Check)
- Pfad-Traversal-Prüfung: `realpath()` gegen Basisverzeichnis prüfen

### Datenbankzugriff

- Ausschließlich PDO Prepared Statements; kein direktes String-Interpolieren
- DB-Zugangsdaten nur in `.env` (serverseitig, nie im Build)
- Datenbankverbindungsfehler werden geloggt, nicht an den Client weitergegeben

### Audit-Log

- Unveränderliche Einträge (kein UPDATE/DELETE auf `audit_logs`)
- Gespeichert: Timestamp, User-ID, User-Name, Action, Entity-Typ, Entity-ID, IP-Adresse
- Nicht gespeichert: Passwörter, Session-Tokens

---

## 8 Rollback-Verfahren

### Sofortiger Rollback (innerhalb 24 Stunden nach Umstellung)

1. Supabase-Secrets (`PUBLIC_SUPABASE_URL`, `PUBLIC_SUPABASE_ANON_KEY`) wieder in GitHub-Secrets aktivieren
2. Deploy-Workflow auf den letzten Supabase-kompatiblen Commit zurücksetzen: `git revert <sha>`
3. Deploy-Workflow starten; Verifikation über `https://sv-netzwerk.eu/intern/login/`
4. Neues PHP-Backend deaktivieren: `.htaccess` entfernen oder umbenennen

### Voraussetzungen für Rollback

- Letzter Supabase-kompatibler Git-SHA muss bekannt und erreichbar sein
- Supabase-Projekt muss während der Migrationsphase aktiv bleiben (nicht löschen)
- `supabase/migrations/` und `supabase/seed/` bleiben im Repository erhalten

### Datenverlust-Risiko

- Zwischen Umstellung und Rollback erfasste Daten im neuen System gehen verloren
- Bei mehr als 1 Stunde Produktionsbetrieb des neuen Systems: bidirektionale Datensynchronisation erforderlich
- Risikomindierung: Migrationsfenster kurz halten, Rollback-Entscheidung innerhalb 2 Stunden treffen

---

## 9 Deployment-Verfahren

### Voraussetzungen

1. IONOS-Hosting-Nachweis dokumentiert (PHP ≥ 8.2, MariaDB)
2. Neue MariaDB-Datenbank angelegt
3. `.env`-Datei auf dem Server bereitgestellt (nicht im Repository)
4. Upload-Verzeichnis außerhalb Webroot angelegt und schreibbar
5. Schritt 7 (Tests) vollständig bestanden

### Deployment-Schritte

```bash
# 1. Build (ohne Supabase-Secrets, nach Phase 6)
cd sv-netzwerk
npm ci
npm run build

# 2. Upload via SFTP (wie bestehender Workflow)
# dist/ nach /sv-netzwerk/ spiegeln

# 3. PHP-Backend ist automatisch via dist/intern-api/ verfügbar

# 4. Verifizieren
curl -s https://www.sv-netzwerk.eu/intern-api/  # muss 401 zurückgeben
curl -s https://www.sv-netzwerk.eu/intern/login/ # muss 200 zurückgeben
```

### deploy.yml-Änderungen

- Schritte „Supabase-Secrets prüfen" und „Supabase-Umgebungsvariablen validieren" entfernen
- `PUBLIC_SUPABASE_URL` und `PUBLIC_SUPABASE_ANON_KEY` aus `env:` entfernen
- Harte Prüfung auf Supabase-URL `required_supabase_url` entfernen
- Neue Prüfung: `/intern-api/auth/session` liefert 401 (kein Anonymous-Zugriff)

### Neue Staging-Prüfung im Workflow

```yaml
- name: Intern-API verifizieren
  run: |
    status="$(curl -s -o /dev/null -w '%{http_code}' https://www.sv-netzwerk.eu/intern-api/)"
    [ "$status" = "401" ] || (echo "::error::intern-api liefert $status statt 401"; exit 1)
    echo "intern-api: HTTP $status OK"
```

---

## 10 Offene Risiken

| Risiko | Wahrscheinlichkeit | Auswirkung | Maßnahme |
|--------|--------------------|-----------|---------|
| MariaDB nicht verfügbar im IONOS-Plan | Mittel | Hoch – Architektur-Wechsel nötig | Control Panel sofort prüfen |
| PHP < 8.2 auf IONOS | Niedrig | Mittel – Code-Anpassungen | PHP-Version prüfen |
| Kein schreibbarer Pfad außerhalb Webroot | Mittel | Mittel – Uploads im Webroot riskanter | Verzeichnisstruktur via SFTP erkunden |
| Supabase-Realtime-Ersatz (Polling) unzureichend | Niedrig | Niedrig – Usability-Einschränkung | Polling-Intervall konfigurierbar machen |
| Datenverlust bei Supabase-Export | Niedrig | Hoch | Vollständigen Export vor Abschaltung durchführen |
| Parallelbetrieb > 3 Benutzer ohne Lock-Koordination | Niedrig | Mittel – Race Conditions | Record-Locking korrekt implementieren |

---

## 11 Ausstehende manuelle Schritte

Folgende Schritte können nicht automatisiert durch GitHub Copilot erfolgen und erfordern direkte Administrator-Handlung:

1. **IONOS Control Panel:** PHP-Version und MariaDB-Verfügbarkeit bestätigen → Ergebnis in `docs/intern/ionos-hosting-nachweis.md` eintragen
2. **IONOS Control Panel:** MySQL-Datenbank anlegen und Zugangsdaten sicher verwahren
3. **Supabase-Konsole:** Alle Tabellen als CSV/SQL exportieren, bevor Supabase-Projekt gelöscht wird
4. **Supabase Storage:** Alle Fotos aus `window-photos-private` herunterladen
5. **IONOS SFTP:** Upload-Verzeichnis außerhalb des Webroots anlegen (`/uploads/` parallel zu `/sv-netzwerk/`)
6. **Server:** `.env`-Datei im IONOS-Verzeichnis ablegen (niemals ins Repository committen)
7. **Initiales Admin-Konto:** Erstes Benutzerkonto nach Inbetriebnahme über sicheres Setup-Script anlegen

---

*Dieses Dokument enthält keine Passwörter, API-Keys oder Datenbankzugangsdaten.*  
*Erstellt auf Basis der Repository-Analyse vom 2026-07-25.*
