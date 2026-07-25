# Geschütztes Prüfportal – Fensterbeschlagsprüfung BMVg Bonn

## Technischer Stand

Das interne Prüfportal ist vollständig auf PHP 8.4 + MySQL 8.0 umgestellt.
Supabase wird nicht mehr verwendet.

## Enthaltene Bestandteile

- geschützte Astro-Routen unter `/intern/`
- PHP-Session-Authentifizierung (passwortbasiert)
- Fensterliste, Dashboard, Datensatzeditor, Auswertung und Exportansicht
- IndexedDB-Zwischenspeicherung mit Synchronisierung nach Wiederverbindung
- MySQL-Schema (`public/intern/api/schema.sql`)
- Foto-Upload über PHP-Multipart-API
- Einrichtungsassistent unter `/intern/api/setup.php`

## Einrichtung

### 1. `.env`-Datei anlegen

Aus `.env.example` kopieren und ausfüllen:

```env
DB_HOST=localhost
DB_PORT=3306
DB_NAME=fensterpruefung
DB_USER=<datenbanknutzer>
DB_PASS=<datenbankpasswort>
PHOTOS_DIR=       # optional: absoluter Pfad außerhalb des Web-Roots
SETUP_KEY=        # zufälliges Passwort für den Einrichtungsassistenten
```

### 2. MySQL-Schema einrichten

Entweder direkt via mysql-Client:

```bash
mysql -u <user> -p <datenbank> < sv-netzwerk/public/intern/api/schema.sql
```

Oder per Einrichtungsassistenten:

```
GET https://sv-netzwerk.eu/intern/api/setup.php?key=<SETUP_KEY>
POST https://sv-netzwerk.eu/intern/api/setup.php?key=<SETUP_KEY>&action=install
```

### 3. Administratorkonto anlegen

```
POST https://sv-netzwerk.eu/intern/api/setup.php?key=<SETUP_KEY>&action=create_admin
Body: { "email": "admin@example.com", "full_name": "Name Vorname", "password": "sicheres-passwort" }
```

### 4. Foto-Verzeichnis absichern

Das Verzeichnis `public/intern/photos/` ist durch `.htaccess` gegen direkten
Browser-Zugriff gesperrt. Fotos werden ausschließlich über die PHP-API ausgeliefert
(`/intern/api/photos.php?window_id=…`).

## API-Endpunkte

| Methode | Pfad                        | Funktion                          |
|---------|-----------------------------|-----------------------------------|
| POST    | `/intern/api/auth.php?action=login`   | Anmeldung                        |
| POST    | `/intern/api/auth.php?action=logout`  | Abmeldung                        |
| GET     | `/intern/api/auth.php?action=session` | Aktuelle Session                 |
| POST    | `/intern/api/auth.php?action=reset`   | Passwort-Zurücksetzung           |
| GET     | `/intern/api/windows.php`             | Fensterliste                     |
| GET     | `/intern/api/windows.php?id={id}`     | Einzeldatensatz                  |
| POST    | `/intern/api/windows.php`             | Neuen Datensatz anlegen          |
| PUT     | `/intern/api/windows.php?id={id}`     | Datensatz aktualisieren          |
| GET     | `/intern/api/windows.php?action=locks`| Aktive Sperren                   |
| GET     | `/intern/api/windows.php?action=audit&id={id}` | Audit-Log              |
| POST    | `/intern/api/locks.php?action=acquire&id={id}` | Sperre setzen         |
| DELETE  | `/intern/api/locks.php?id={id}`       | Sperre freigeben                 |
| GET     | `/intern/api/photos.php?window_id={id}`| Fotos eines Fensters            |
| POST    | `/intern/api/photos.php?window_id={id}`| Foto hochladen (multipart)      |
| DELETE  | `/intern/api/photos.php?id={id}`      | Foto löschen                     |
| GET     | `/intern/api/parameters.php`          | Berechnungsparameter             |
| POST    | `/intern/api/exports.php`             | Export-Eintrag protokollieren    |

## Bekannte Einschränkungen

- Realtime-Aktualisierung (Supabase Channels) wurde durch seitenbasiertes Laden ersetzt.
- PDF-Erstellung nutzt die Browser-Druckfunktion der geschützten Seiten.
- Foto-Vorschaubilder zeigen Platzhalter (signierte URLs werden nicht benötigt).
- Benutzerverwaltung erfolgt über den Einrichtungsassistenten oder direkten MySQL-Zugriff.

