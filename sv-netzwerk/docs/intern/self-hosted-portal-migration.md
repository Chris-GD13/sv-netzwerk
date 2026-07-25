# Migration: Supabase → Self-hosted PHP/MySQL

**Projekt:** Fensterbeschlagsprüfung BMVg Bonn  
**Status:** Implementierung läuft – Backend vollständig, Frontend-Rewrite ausstehend  
**Erstellt:** 2026-07-25  
**Betrifft:** Internes Prüfportal `/intern/` auf sv-netzwerk.eu

---

## Inhaltsverzeichnis

1. [Verifikation Hosting-Umgebung](#1-verifikation-hosting-umgebung)
2. [Aktuelle Architektur (Supabase)](#2-aktuelle-architektur-supabase)
3. [Zielarchitektur](#3-zielarchitektur)
4. [Datenbankschema](#4-datenbankschema)
5. [API-Routen-Mapping](#5-api-routen-mapping)
6. [Sicherheitskonzept](#6-sicherheitskonzept)
7. [Migrationsphasen](#7-migrationsphasen)
8. [Rollback-Verfahren](#8-rollback-verfahren)
9. [Offene Risiken](#9-offene-risiken)
10. [Ausstehende manuelle Schritte](#10-ausstehende-manuelle-schritte)

---

## 1. Verifikation Hosting-Umgebung

| Merkmal | Status | Nachweis |
|---|---|---|
| PHP 8.4 | ✅ Verifiziert | Direktes Hosting-Check durch Auftraggeber |
| MySQL 8.0 | ✅ Verifiziert | Dedizierte Datenbank erstellt |
| HTTPS | ✅ Bestätigt | `.htaccess` erzwingt HTTPS-Redirect |
| `.htaccess`-Routing | ✅ Bestätigt | Komplexe RewriteRules in `public/.htaccess` |
| PHP-Sessions | ✅ Bestätigt | `session.cookie_httponly`, `SameSite=Lax` konfiguriert |
| SFTP / SSH | ✅ Verifiziert | Deployment via lftp SFTP |
| Persistenter Upload-Speicher | ✅ Bestätigt | `uploads/photos/` wird von lftp-Mirror ausgeschlossen |
| PHP `mail()` | ✅ Bestätigt | Kontaktformular-Handler nutzen PHP-Mail |
| Cronjobs | ❓ Nicht erforderlich | Session-Cleanup übernimmt PHP-GC |

---

## 2. Aktuelle Architektur (Supabase)

### Komponenten

- **Frontend:** Astro + TypeScript, vollständig client-seitig
- **Backend-Logik:** `src/lib/internal/client.ts` (1513 Zeilen)
- **Datenbank:** Supabase (PostgreSQL) mit Row Level Security
- **Auth:** Supabase Auth (`supabase.auth.signInWithPassword`)
- **Storage:** Supabase Storage für Foto-Uploads
- **Realtime:** Supabase Realtime-Subscriptions für Sperrbenachrichtigungen

### Probleme

- Öffentlicher API-Key (`PUBLIC_SUPABASE_ANON_KEY`) im Browser-Code
- Auth-Zustand nur im Browser; kein echtes Server-Session-Management
- RLS-Regeln in PostgreSQL schwer zu testen und zu auditieren
- Abhängigkeit von externem Drittanbieter (Supabase)
- Keine serverseitige Validierung vor DB-Schreiboperationen

---

## 3. Zielarchitektur

### Übersicht

```
Browser (Astro/TypeScript)
        │  fetch() – same-origin, Cookie-Session
        ▼
/intern-api/index.php         ← Front-Controller (Router)
        │
        ├─ Middleware: Auth, CSRF, Role
        ├─ Controllers: Auth, Window, Photo, Export, User, Stats, Audit
        ├─ Models: User, Window, Photo, AuditLog
        └─ Services: AuthService, UploadService, ExportService
                │
                ▼
        MySQL 8.0 (IONOS)
        uploads/photos/ (SFTP-persistent)
```

### Backend-Verzeichnisstruktur

```
public/intern-api/
  index.php               ← Einstiegspunkt (alle Anfragen)
  bootstrap.php           ← Autoloader, Fehlerbehandlung, Helfer
  .htaccess               ← Routing, Sicherheitsoptionen
  env.example             ← Konfigurationsvorlage
  config/
    config.php            ← .env-Leser, Config-Singleton
    database.php          ← PDO-Singleton (MySQL 8.0, UTC, utf8mb4)
  middleware/
    Auth.php              ← Session-Lifecycle, Timeouts, Cookie-Flags
    Role.php              ← Rollenhierarchie (administrator > pruefer > auswertung)
    Csrf.php              ← Synchronized-Token-Pattern
  models/
    User.php              ← Benutzerverwaltung, Rate-Limiting
    Window.php            ← Fenster-CRUD, Sperren, Berechnungsparameter
    Photo.php             ← Foto-Metadaten
    AuditLog.php          ← Unveränderliches Audit-Log
  services/
    AuthService.php       ← Login-Logik, Argon2ID, Passwortvalidierung
    UploadService.php     ← MIME-Prüfung, Pfad-Traversal-Schutz
    ExportService.php     ← CSV, HTML-Report
  controllers/
    AuthController.php
    WindowController.php
    PhotoController.php
    ExportController.php
    UserController.php
    StatsController.php
    AuditController.php
  migrations/
    001_initial_schema.sql
    install.php           ← CLI-Setup (Schema + erster Admin)

public/uploads/photos/
  .htaccess               ← Deny all (kein HTTP-Direktzugriff)
```

---

## 4. Datenbankschema

### Kernentitäten

| Tabelle | Zweck |
|---|---|
| `users` | Benutzer, Rollen (`administrator`, `pruefer`, `auswertung`) |
| `login_attempts` | Rate-Limiting, Sperrschutz |
| `password_reset_tokens` | Passwort-Reset-Workflow |
| `projects` | Prüfprojekte (initialer Seed: BMVg Bonn) |
| `buildings` | Gebäude |
| `building_sections` | Gebäudeteile |
| `floors` | Etagen |
| `windows` | **Kernentität**: Fensterdatensatz + Prüf-/Befunddaten |
| `record_locks` | Exklusive Datensatzsperren (15-Min-TTL) |
| `photos` | Fotodokumentation je Fenster |
| `documents` | Sonstige Anhänge |
| `audit_logs` | Unveränderliches Prüfprotokoll |
| `export_logs` | Protokoll der Exporte |
| `calculation_parameters` | Technische Grenzwerte und Kennwerte |

### Supabase → MySQL Feldmapping (windows)

| Supabase-Feld | MySQL-Spalte | Typ |
|---|---|---|
| `id` (uuid) | `id` | VARCHAR(36) |
| `project_id` | `project_id` | VARCHAR(36) FK |
| `record_id` | `record_id` | VARCHAR(50) |
| `inspection_number` | `inspection_number` | INT UNSIGNED |
| `window_number` | `window_number` | VARCHAR(50) |
| `status` | `status` | VARCHAR(50) |
| `form_data` (jsonb) | `form_data` | JSON |
| `calculated_data` | `calculated_data` | JSON |
| `has_defect` | `has_defect` | TINYINT(1) |
| `danger_immediate` | `danger_immediate` | TINYINT(1) |
| Supabase RLS | PHP-Middleware | Role::require() |

---

## 5. API-Routen-Mapping

| Supabase-Aufruf | Neuer API-Endpunkt |
|---|---|
| `supabase.auth.signInWithPassword()` | `POST /intern-api/auth/login` |
| `supabase.auth.signOut()` | `POST /intern-api/auth/logout` |
| `supabase.auth.getSession()` | `GET /intern-api/auth/session` |
| `supabase.from('windows').select()` | `GET /intern-api/windows` |
| `supabase.from('windows').insert()` | `POST /intern-api/windows` |
| `supabase.from('windows').upsert()` | `PUT /intern-api/windows/{id}` |
| `supabase.rpc('acquire_record_lock')` | `POST /intern-api/windows/{id}/lock` |
| `supabase.rpc('release_record_lock')` | `DELETE /intern-api/windows/{id}/lock` |
| `supabase.from('photos').select()` | `GET /intern-api/windows/{id}/photos` |
| `supabase.storage.upload()` | `POST /intern-api/windows/{id}/photos` |
| `supabase.storage.remove()` | `DELETE /intern-api/photos/{id}` |
| Foto-URL direkt | `GET /intern-api/photos/{id}/file` |
| `supabase.from('audit_logs')` | `GET /intern-api/windows/{id}/audit` |
| `supabase.from('calculation_parameters')` | `GET /intern-api/calculation-parameters` |
| Dashboard-Stats | `GET /intern-api/stats/dashboard` |
| Export | `GET /intern-api/export/csv` / `/report` |

---

## 6. Sicherheitskonzept

### Authentifizierung
- PHP-Session-Cookies: `HttpOnly`, `Secure`, `SameSite=Lax`
- Session-ID-Rotation nach Login
- Inaktivitäts-Timeout: 480 Minuten (konfigurierbar)
- Absoluter Session-Timeout: 720 Minuten
- Argon2ID-Passwort-Hashing (memory_cost=65536, time_cost=4)
- Login-Fehlversuche: Rate-Limiting nach IP (5 Versuche / 15 Min)
- Generische Fehlermeldungen (kein Hinweis auf E-Mail-Existenz)

### CSRF-Schutz
- Synchronized-Token-Pattern
- Token im `X-CSRF-Token`-Header oder `_csrf`-POST-Feld
- Timing-sicherer Vergleich via `hash_equals()`
- Login-Endpunkt ohne CSRF (kein Session-Kontext vor Login)

### Upload-Sicherheit
- Allowlist-MIME-Typen (JPEG, PNG, WebP, GIF)
- Serverseitige MIME-Verifikation via `finfo_file()` (nicht Content-Type-Header)
- Zufällig generierte Speichernamen (kein Bezug zum Originalnamen)
- `.htaccess: Require all denied` im Upload-Verzeichnis
- Autorisierungsprüfung vor `readfile()`-Auslieferung

### Rollen
| Rolle | Berechtigungen |
|---|---|
| `administrator` | Alle Aktionen + Freigabe + Benutzerverwaltung + Admin-Sperren |
| `pruefer` | Fenster erstellen/bearbeiten, Fotos hochladen |
| `auswertung` | Nur lesen + exportieren |

---

## 7. Migrationsphasen

| Phase | Status | Beschreibung |
|---|---|---|
| 1 – Hosting-Verifikation | ✅ Abgeschlossen | PHP 8.4, MySQL 8.0, SSH/SFTP |
| 2 – Migrationsplan | ✅ Abgeschlossen | Dieses Dokument |
| 3 – Backend-Implementierung | ✅ Abgeschlossen | PHP-Backend vollständig (22 Dateien, alle Syntax-OK) |
| 4 – Frontend-Rewrite | 🔄 Ausstehend | `client.ts` Supabase → fetch() |
| 5 – Exports | 🔄 Ausstehend | CSV und HTML-Report implementiert; PDF via Browser-Druck |
| 6 – Supabase-Entfernung | ⏳ Warten | Nach Frontend-Rewrite |
| 7 – Sicherheitscheck | ⏳ Warten | PHP-Syntax ✅; API-Tests folgen |
| 8 – DB-Installation | ⏳ Warten | Schema fertig, Credentials vom Auftraggeber |
| 9 – Staged Deployment | ⏳ Warten | `/intern-next/` vor Produktivschaltung |
| 10 – Produktivschaltung | ⏳ Warten | Nach erfolgreichem Staging |

---

## 8. Rollback-Verfahren

Da das alte Supabase-Frontend nicht entfernt wird, bis das neue Backend verifiziert ist:

1. `.htaccess`-Routing auf `/intern/` zurückstellen (auf Supabase-Frontend)
2. Das neue `/intern-api/` bleibt unangetastet
3. Supabase-Umgebungsvariablen sind noch gesetzt (bis Phase 6)
4. Daten sind in Supabase noch vorhanden (kein Löschvorgang bis Phase 6)

---

## 9. Offene Risiken

| Risiko | Wahrscheinlichkeit | Maßnahme |
|---|---|---|
| Daten-Export aus Supabase unvollständig | Mittel | Vollständigen CSV-Export vor Phase 6 durchführen |
| Foto-Migration Supabase-Storage | Hoch | Manuelle SFTP-Übertragung erforderlich |
| lftp-Mirror löscht Uploads | Hoch | `--exclude uploads` in deploy.yml (noch ausstehend) |
| PHP `upload_max_filesize` zu niedrig | Niedrig | `.htaccess` setzt 25M; prüfen nach erstem Upload |
| Session-Konflikte bei parallelen Prüfern | Niedrig | Record-Locking implementiert |

---

## 10. Ausstehende manuelle Schritte

1. **Datenbankverbindung:** `.env`-Datei manuell via SFTP auf Server ablegen
2. **Schema:** `php migrations/install.php --migrate` auf dem Server ausführen
3. **Admin-Account:** `php migrations/install.php --create-admin` ausführen
4. **Frontend-Rewrite:** `src/lib/internal/client.ts` von Supabase auf fetch() umschreiben
5. **deploy.yml:** `--exclude uploads` zu lftp-Mirror hinzufügen
6. **Supabase-Daten-Export:** Fensterdaten, Fotos und Audit-Log aus Supabase exportieren
7. **Foto-Migration:** Foto-Dateien aus Supabase Storage nach `uploads/photos/` übertragen
8. **Staging-Test:** Portal unter `/intern-next/` verifizieren vor Produktivschaltung

