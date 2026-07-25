# SVOS Inspection Platform – Installationsanleitung

## Voraussetzungen

| Komponente | Mindestversion | Verifiziert |
|---|---|---|
| PHP | 8.4 | ✅ |
| MySQL | 8.0 | ✅ |
| Apache | 2.4 | (IONOS Standard) |
| mod_rewrite | — | (IONOS Standard) |
| SSH / SFTP | — | ✅ |

---

## 1. Datenbankdatenbank anlegen

Erstellen Sie auf dem IONOS-Server eine neue MySQL 8.0-Datenbank:

```sql
CREATE DATABASE svnetzwerk_intern
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;
```

Notieren Sie Host, Port, Datenbankname, Benutzername und Passwort.

---

## 2. `.env`-Datei erstellen

Kopieren Sie `env.example` zu `.env` im Verzeichnis `intern-api/`:

```bash
cp env.example .env
chmod 600 .env
```

Füllen Sie alle Pflichtfelder aus:

```env
DB_HOST=your-mysql-host
DB_PORT=3306
DB_NAME=svnetzwerk_intern
DB_USER=your-db-user
DB_PASSWORD=your-secure-password

APP_SECRET=min_32_zufaellige_zeichen_hier_eintragen
APP_ENV=production
UPLOAD_PATH=../uploads/photos
SESSION_INACTIVITY_TIMEOUT=480
SESSION_ABSOLUTE_TIMEOUT=720
```

**Die `.env`-Datei darf niemals in Git eingecheckt werden.**  
Sie ist in `.gitignore` ausgeschlossen.

Generieren Sie `APP_SECRET` mit:
```bash
php -r "echo bin2hex(random_bytes(32)) . PHP_EOL;"
```

---

## 3. Verzeichnisstruktur auf dem Server

Nach dem SFTP-Upload (über GitHub Actions oder manuell) sollte folgende Struktur vorliegen:

```
/www/                          ← Web-Root
  intern-api/
    .env                       ← Manuell via SFTP ablegen (NIE deployen)
    .htaccess
    index.php
    bootstrap.php
    config/
    contracts/
    controllers/
    middleware/
    migrations/
    models/
    modules/
    registry/
    services/
  uploads/
    .htaccess                  ← Denies all HTTP access
    photos/
```

---

## 4. Schema anlegen

Verbinden Sie sich per SSH mit dem Server:

```bash
ssh user@sv-netzwerk.eu
cd /www/intern-api
php migrations/install.php --migrate
```

Erwartete Ausgabe:
```
[OK] Datenbankverbindung hergestellt.
[INFO] Schema wird angewendet...
[OK] Schema erfolgreich angewendet.
[FERTIG]
```

---

## 5. Ersten Administrator anlegen

```bash
php migrations/install.php --create-admin
```

Das Skript fragt interaktiv:
- E-Mail-Adresse
- Vollständiger Name
- Passwort (min. 12 Zeichen, Groß-/Kleinbuchstaben, Ziffer)
- Passwort-Wiederholung

Das Passwort wird mit Argon2ID gehasht. Es wird nach der Einrichtung nicht erneut angezeigt.

---

## 6. Upload-Verzeichnis sichern

Das Upload-Verzeichnis `uploads/` enthält eine `.htaccess` mit `Require all denied`.  
Prüfen Sie nach dem ersten Upload, dass direkter HTTP-Zugriff verweigert wird:

```bash
curl -I https://sv-netzwerk.eu/uploads/photos/
# Erwartete Antwort: 403 Forbidden
```

---

## 7. Staging-Test (`/intern-next/`)

Vor der Produktivschaltung auf `/intern/` testen Sie unter:

```
https://sv-netzwerk.eu/intern-next/login/
```

Smoke-Tests:
1. Login-Seite lädt
2. Ungültige Anmeldedaten → Fehlermeldung (keine Details)
3. Gültige Anmeldedaten → Dashboard
4. Dashboard zeigt Statistiken
5. Fensterliste lädt
6. Inspektion anlegen → Datensatz sichtbar
7. Inspektion bearbeiten → Speichern funktioniert
8. Foto hochladen → erscheint in der Liste
9. Direkter Foto-URL-Zugriff ohne Auth → 401 / 403
10. Export CSV → Datei wird heruntergeladen
11. Logout → Session beendet, Dashboard nicht mehr erreichbar

---

## 8. Produktivschaltung

1. **Backup der aktuellen Supabase-Daten** (CSV-Export aus Supabase-Dashboard)
2. **Backup des Upload-Verzeichnisses** (SFTP-Download)
3. Routing von `/intern/` auf den neuen Backend-Pfad umstellen
4. Smoke-Tests auf Produktiv-URLs wiederholen:
   - `https://sv-netzwerk.eu/intern/login/`
   - `https://sv-netzwerk.eu/intern/dashboard/`
   - `https://sv-netzwerk.eu/intern/fensterpruefung-bonn/`

---

## 9. Rollback

Falls die Produktivschaltung fehlschlägt:

1. Routing wieder auf das alte Supabase-Frontend zurückstellen
2. Die neue `intern-api/`-Konfiguration bleibt unangetastet
3. Fehler analysieren und beheben
4. Erneut testen, dann erneut schalten

Das alte System bleibt im Git-Verlauf und auf dem Server in `intern-api-old/` erhalten (falls Backup angelegt).

---

## 10. Datenmigration aus Supabase

### Erforderliche Exporte aus Supabase

Exportieren Sie folgende Tabellen als CSV aus dem Supabase-Dashboard:

| Supabase-Tabelle | Zieltabelle | Felder |
|---|---|---|
| `windows` | `inspections` + `window_records` | Alle Felder |
| `photos` | `photos` | Dateiname, Kategorie, Fenster-Bezug |
| `audit_logs` | `audit_logs` | Alle Felder |

### Migrationsskript

Ein angepasstes Migrationsskript kann nach Erhalt der CSV-Exporte erstellt werden.  
Die Supabase-UUID-Felder sind kompatibel mit VARCHAR(36) in MySQL.

**Wichtig:** Photos-Dateien müssen zusätzlich aus dem Supabase Storage heruntergeladen  
und in `uploads/photos/` abgelegt werden. Die Speichernamen müssen in `photos.storage_path` hinterlegt sein.

---

## 11. Backup-Prozedur

Tägliches Backup (IONOS Managed Backup oder Cron):

```bash
# Datenbank
mysqldump -h $DB_HOST -u $DB_USER -p$DB_PASSWORD $DB_NAME \
  --single-transaction --quick \
  > backup_$(date +%Y%m%d).sql

# Uploads
tar czf uploads_$(date +%Y%m%d).tar.gz /www/uploads/
```

---

## 12. Umgebungsvariablen – Referenz

| Variable | Pflicht | Beschreibung |
|---|---|---|
| `DB_HOST` | ✅ | MySQL-Hostname |
| `DB_PORT` | — | MySQL-Port (Standard: 3306) |
| `DB_NAME` | ✅ | Datenbankname |
| `DB_USER` | ✅ | Datenbankbenutzer |
| `DB_PASSWORD` | ✅ | Datenbankpasswort |
| `APP_SECRET` | ✅ | Min. 32 Zeichen, zufällig |
| `APP_ENV` | — | `production` oder `development` |
| `UPLOAD_PATH` | — | Pfad zum Upload-Verzeichnis |
| `SESSION_INACTIVITY_TIMEOUT` | — | Minuten (Standard: 480) |
| `SESSION_ABSOLUTE_TIMEOUT` | — | Minuten (Standard: 720) |

---

*Letzte Aktualisierung: 2026-07-25*
