# Projektanalyse – Fensterbeschlagsprüfung BMVg Bonn

## Projektübersicht

| Eigenschaft | Wert |
|------------|------|
| **Projektname** | Fensterbeschlagsprüfung BMVg Bonn |
| **Auftraggeber** | Bundesministerium der Verteidigung |
| **Auftragnehmer** | SV-Büro Marc Schütt e.K. |
| **Objekt** | 1. Dienstsitz BMVg, Fontainengraben 150, 53123 Bonn |
| **Umfang** | ca. 450 Fenster mit je 1-4 Flügeln |
| **URL** | https://www.sv-netzwerk.eu/intern/fensterpruefung-bonn/ |
| **Hosting** | IONOS Webhosting (PHP-FPM + MySQL 8.0) |
| **Framework Frontend** | Astro 4.x (Static Site Generator) + Vanilla TypeScript SPA |
| **Framework Backend** | PHP 8.x (ohne Framework, custom MVC-artig) |
| **Datenbank** | MySQL 8.0 (InnoDB, utf8mb4) |
| **KI-Integration** | OpenAI GPT-4o (Vision) |
| **CI/CD** | GitHub Actions → SFTP Deploy auf IONOS |

---

## Verzeichnisbaum

```
sv-netzwerk/
├── .github/
│   └── workflows/
│       ├── deploy.yml              # CI/CD: Build + SFTP-Deploy auf IONOS
│       ├── build-check.yml         # PR-Check: TypeScript-Build
│       └── setup-portal.yml        # Einmalig: .env + Schema + Admin anlegen
│
├── sv-netzwerk/                    # Astro-Projekt (Hauptverzeichnis)
│   ├── public/
│   │   ├── .htaccess              # Apache Rewrite-Regeln
│   │   └── intern/
│   │       ├── api/               # PHP-Backend
│   │       │   ├── config.php     # Konfiguration, DB, Hilfsfunktionen
│   │       │   ├── auth.php       # Login/Logout/Session
│   │       │   ├── windows.php    # Fenster CRUD + Dashboard
│   │       │   ├── sashes.php     # Flügel CRUD + Prüfdaten
│   │       │   ├── hierarchy.php  # Gebäude/Etagen/Räume CRUD
│   │       │   ├── photos.php     # Foto-Upload/Verwaltung
│   │       │   ├── locks.php      # Record Locking
│   │       │   ├── users.php      # Benutzerverwaltung
│   │       │   ├── exports.php    # CSV-Export
│   │       │   ├── parameters.php # Berechnungsparameter
│   │       │   ├── ai-import.php  # KI-Dokumentenimport
│   │       │   ├── demo.php       # Demo-Daten Seeding
│   │       │   ├── setup.php      # Schema-Installation
│   │       │   ├── schema.sql     # Datenbankschema (13 Tabellen)
│   │       │   ├── .htaccess      # API: PHP-FPM Routing
│   │       │   └── services/
│   │       │       └── AiService.php  # Zentraler KI-Service
│   │       └── photos/            # Foto-Uploads (Dateisystem)
│   │
│   ├── src/
│   │   ├── components/
│   │   │   └── internal/
│   │   │       └── InternalAppShell.astro  # SPA-Container
│   │   ├── layouts/
│   │   │   └── InternalLayout.astro        # Portal-Layout
│   │   ├── lib/
│   │   │   └── internal/
│   │   │       ├── client.ts      # SPA-Hauptdatei (~2900 Zeilen)
│   │   │       ├── php-api.ts     # API-Client (40+ Funktionen)
│   │   │       ├── types.ts       # TypeScript-Interfaces
│   │   │       ├── schema.ts      # Prüfformular-Definitionen
│   │   │       ├── calculations.ts # Gewichtsberechnungen
│   │   │       └── offline.ts     # Offline-Speicher (IndexedDB)
│   │   ├── pages/
│   │   │   └── intern/
│   │   │       ├── index.astro           # Portal-Einstieg
│   │   │       ├── login/index.astro     # Login
│   │   │       └── fensterpruefung-bonn/
│   │   │           ├── index.astro       # Dashboard
│   │   │           ├── gebaeude/index.astro  # Gebäude
│   │   │           ├── auswertung/index.astro # Auswertung
│   │   │           ├── fenster/index.astro    # Fenster/Flügel
│   │   │           ├── fenster/record/index.astro # Einzelfenster
│   │   │           ├── export/index.astro     # Export
│   │   │           ├── import/index.astro     # KI-Import
│   │   │           └── admin/index.astro      # Benutzerverwaltung
│   │   └── styles/
│   │       └── internal-portal.css  # Portal-CSS (~850 Zeilen)
│   │
│   ├── astro.config.mjs           # Astro-Konfiguration
│   ├── tsconfig.json              # TypeScript-Konfiguration
│   └── package.json               # NPM-Dependencies
```

---

## Verwendete Frameworks und Bibliotheken

| Komponente | Technologie | Version | Zweck |
|-----------|------------|---------|-------|
| Static Site Generator | Astro | 4.x | HTML-Generierung, Routing |
| Frontend-Sprache | TypeScript | 5.x | Typsicherheit |
| Backend | PHP | 8.x | API-Endpunkte, Business-Logik |
| Datenbank | MySQL | 8.0 | Persistenz |
| Passwort-Hashing | Argon2ID | (PHP built-in) | Sichere Passwortspeicherung |
| KI | OpenAI GPT-4o | API | Dokumentenanalyse |
| CI/CD | GitHub Actions | - | Automatisches Deployment |
| SFTP-Client | lftp | - | Dateitransfer zu IONOS |
| Offline-Speicher | IndexedDB | (Browser-API) | Offline-Entwürfe |

**Hinweis:** Kein PHP-Framework (Laravel, Symfony etc.) verwendet. Eigene Architektur.

---

## Datenfluss

```
┌──────────┐     ┌──────────────┐     ┌──────────┐     ┌──────────┐
│ Benutzer │────▶│   Frontend   │────▶│   API    │────▶│   MySQL  │
│ (Browser)│◀────│  (client.ts) │◀────│  (PHP)   │◀────│   8.0    │
└──────────┘     └──────────────┘     └──────────┘     └──────────┘
                        │                    │
                        │                    ▼
                        │              ┌──────────┐
                        │              │  OpenAI  │ (nur KI-Import)
                        │              │  GPT-4o  │
                        │              └──────────┘
                        │
                        ▼
                 ┌──────────────┐
                 │  IndexedDB   │ (Offline-Entwürfe)
                 └──────────────┘
```

---

## Offene TODOs und bekannte Einschränkungen

### Funktional
1. **Passwort-Reset:** Token-Tabelle existiert, aber kein Frontend/Mail-Versand implementiert
2. **Offline-Sync:** IndexedDB-Modul vorhanden, aber nicht vollständig integriert
3. **Mehrprojekt-Fähigkeit:** Schema unterstützt mehrere Projekte, UI ist auf Projekt-ID 1 fixiert
4. **Foto-Zuordnung zu Flügeln:** Backend unterstützt sash_id, Frontend nutzt es nur teilweise
5. **Benachrichtigungen:** Keine E-Mail-Benachrichtigungen bei Statusänderungen

### Technisch
1. **Kein PHP-Autoloader:** Alle Dateien laden config.php manuell per require_once
2. **Kein API-Versionierung:** Alle Endpunkte unter /api/ ohne Versionsprefix
3. **Keine Rate-Limiting:** API-Endpunkte haben kein Request-Limit
4. **Session-basiert:** Kein JWT/Token-Auth (erschwert mobile App-Anbindung)
5. **Monolithische client.ts:** ~2900 Zeilen in einer Datei (sollte modularisiert werden)

### Sicherheit
1. **CSRF-Token:** Nicht implementiert (Session-Cookie + SameSite=Strict als Schutz)
2. **Content Security Policy:** Nicht als Header gesetzt
3. **API-Key in .env:** Kein Key-Rotation-Mechanismus
4. **Keine IP-Beschränkung:** Admin-Bereich nicht IP-geschützt

---

## Technische Schulden

| Priorität | Beschreibung | Aufwand |
|-----------|-------------|---------|
| Hoch | client.ts in Module aufteilen (2900→~10 Dateien) | 2-3 Tage |
| Hoch | CSRF-Token implementieren | 1 Tag |
| Mittel | Rate-Limiting für Login/API | 1 Tag |
| Mittel | PHP-Autoloader (PSR-4) einführen | 0.5 Tage |
| Mittel | API-Versionierung (v1/) | 0.5 Tage |
| Niedrig | Content Security Policy Header | 0.5 Tage |
| Niedrig | E-Mail-Benachrichtigungen | 2-3 Tage |
| Niedrig | Unit-Tests für PHP-Backend | 3-5 Tage |

---

## Verbesserungsvorschläge

1. **Frontend-Modularisierung:** client.ts in einzelne View-Module aufteilen
2. **State-Management:** Reaktives State-Management statt DOM-Manipulation
3. **Testing:** PHPUnit für Backend, Vitest für Frontend
4. **API-Dokumentation:** OpenAPI/Swagger-Spec generieren
5. **Mobile App:** PWA mit Service Worker für echte Offline-Fähigkeit
6. **Backup-Strategie:** Automatisierte DB-Backups mit Retention
7. **Monitoring:** Error-Tracking (Sentry o.ä.) und Uptime-Monitoring
8. **Logging:** Strukturiertes Logging mit Log-Levels
9. **Deployment:** Blue-Green oder Canary statt direktem SFTP-Overwrite
10. **KI-Erweiterung:** Automatische Mängelerkennung aus Fotos
