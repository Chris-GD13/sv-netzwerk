# API-Dokumentation – Fensterbeschlagsprüfung BMVg Bonn

## Basis-URL

```
https://www.sv-netzwerk.eu/intern/api/
```

## Authentifizierung

Session-basiert (PHP-Session-Cookie). Alle Endpunkte (außer Login) erfordern eine aktive Session.

**Login:**
```
POST /api/auth.php?action=login
Content-Type: application/json
{"email": "...", "password": "..."}
→ 200: {"ok": true, "user": {...}}
→ 401: {"error": "Ungültige Anmeldedaten."}
```

---

## Endpunkte

### auth.php – Authentifizierung

| Methode | Action | Beschreibung | Auth | Rollen |
|---------|--------|--------------|:----:|--------|
| POST | login | Anmelden | – | Alle |
| POST | logout | Abmelden | ✓ | Alle |
| GET | me | Aktuelle Session prüfen | ✓ | Alle |

**Login Request:**
```json
{"email": "user@example.com", "password": "secret"}
```
**Login Response (200):**
```json
{"ok": true, "user": {"id": 1, "email": "...", "full_name": "...", "role": "administrator"}}
```

---

### windows.php – Fenster-Datensätze

| Methode | Action | Beschreibung | Auth | Rollen |
|---------|--------|--------------|:----:|--------|
| GET | list | Alle Fenster (paginiert, filterbar) | ✓ | Alle |
| GET | get | Einzelnes Fenster | ✓ | Alle |
| GET | dashboard | Dashboard-Statistiken | ✓ | Alle |
| POST | create | Fenster anlegen | ✓ | canEdit |
| POST | update | Fenster aktualisieren | ✓ | canEdit |
| POST | release | Fenster freigeben | ✓ | Admin/PL/SV |
| POST | delete | Fenster löschen (soft) | ✓ | Admin |

**Dashboard Response:**
```json
{
  "total": 450, "notStarted": 200, "inProgress": 150, "completed": 100,
  "withDefect": 25, "urgent": 5, "specialInspection": 12, "inaccessible": 8,
  "touchedToday": 15,
  "byInspector": [{"id": "1", "name": "...", "total": 50, "completed": 30}],
  "recentChanges": [{"id": "1", "label": "...", "updatedAt": "...", "status": "..."}]
}
```

---

### sashes.php – Fensterflügel

| Methode | Action | Beschreibung | Auth | Rollen |
|---------|--------|--------------|:----:|--------|
| GET | list | Flügel eines Fensters | ✓ | Alle |
| GET | get | Einzelner Flügel | ✓ | Alle |
| POST | create | Flügel anlegen | ✓ | canEdit |
| POST | save | Flügel-Prüfdaten speichern | ✓ | canEdit |
| POST | delete | Flügel löschen | ✓ | Admin |

**Flügel speichern:**
```json
{
  "id": 5,
  "form_data": {"beschlag_typ": "Dreh-Kipp", "hersteller": "Roto", ...},
  "status": "in Bearbeitung",
  "progress_percent": 75,
  "has_defect": false
}
```

---

### hierarchy.php – Gebäude/Etagen/Räume

| Methode | Action | Beschreibung | Auth | Rollen |
|---------|--------|--------------|:----:|--------|
| GET | buildings | Gebäudeliste | ✓ | Alle |
| GET | floors | Etagen eines Gebäudes | ✓ | Alle |
| GET | rooms | Räume einer Etage | ✓ | Alle |
| GET | windows | Fenster eines Raums | ✓ | Alle |
| POST | create_building | Gebäude anlegen | ✓ | canEdit |
| POST | update_building | Gebäude bearbeiten | ✓ | canEdit |
| POST | delete_building | Gebäude löschen | ✓ | Admin |
| POST | create_floor | Etage anlegen | ✓ | canEdit |
| POST | update_floor | Etage bearbeiten | ✓ | canEdit |
| POST | delete_floor | Etage löschen | ✓ | Admin |
| POST | create_room | Raum anlegen | ✓ | canEdit |
| POST | update_room | Raum bearbeiten | ✓ | canEdit |
| POST | delete_room | Raum löschen | ✓ | Admin |
| POST | delete_window | Fenster löschen | ✓ | Admin |

**Gebäude anlegen:**
```json
{"name": "Hauptgebäude A", "code": "HGA"}
```
**Response:**
```json
{"ok": true, "id": 3}
```

---

### photos.php – Fotos

| Methode | Action | Beschreibung | Auth | Rollen |
|---------|--------|--------------|:----:|--------|
| GET | list | Fotos eines Fensters/Flügels | ✓ | Alle |
| POST | upload | Foto hochladen | ✓ | canEdit |
| POST | delete | Foto löschen (soft) | ✓ | Admin/PL/SV |

**Upload (multipart/form-data):**
- `file`: Bilddatei (JPG/PNG, max 10MB)
- `window_id`: Fenster-ID
- `sash_id`: Flügel-ID (optional)
- `category`: Kategorie (übersicht/beschlag/mangel/detail)
- `caption`: Beschriftung (optional)

---

### locks.php – Datensatzsperren

| Methode | Action | Beschreibung | Auth | Rollen |
|---------|--------|--------------|:----:|--------|
| POST | acquire | Sperre anfordern (15 Min.) | ✓ | canEdit |
| POST | release | Sperre freigeben | ✓ | Owner/Admin |
| GET | check | Sperrstatus prüfen | ✓ | Alle |

**Sperre anfordern:**
```json
{"window_id": 42}
```
**Response (Erfolg):**
```json
{"ok": true, "lock_id": "42", "expires_at": "2026-07-26T20:15:00Z"}
```
**Response (gesperrt):**
```json
{"ok": false, "owner_name": "Max Mustermann", "expires_at": "2026-07-26T20:10:00Z"}
```

---

### users.php – Benutzerverwaltung

| Methode | Action | Beschreibung | Auth | Rollen |
|---------|--------|--------------|:----:|--------|
| GET | list | Benutzerliste | ✓ | Admin |
| POST | create | Benutzer anlegen | ✓ | Admin |
| POST | update | Benutzer bearbeiten | ✓ | Admin |
| POST | set_password | Passwort setzen | ✓ | Admin |
| POST | deactivate | Benutzer deaktivieren | ✓ | Admin |

---

### exports.php – Datenexport

| Methode | Action | Beschreibung | Auth | Rollen |
|---------|--------|--------------|:----:|--------|
| GET | csv | CSV-Export | ✓ | Alle (außer Gast) |

**Parameter:**
- `filter`: `all|completed|defects|urgent`
- `building_id`: Optional
- `floor_id`: Optional

---

### parameters.php – Berechnungsparameter

| Methode | Action | Beschreibung | Auth | Rollen |
|---------|--------|--------------|:----:|--------|
| GET | get | Parameter laden | ✓ | Alle |
| POST | set | Parameter setzen | ✓ | Admin |

---

### ai-import.php – KI-Dokumentenimport

| Methode | Action | Beschreibung | Auth | Rollen |
|---------|--------|--------------|:----:|--------|
| POST | analyze | Dokument analysieren (GPT-4o) | ✓ | Admin/Prüfer |
| POST | apply | Analyseergebnis anwenden | ✓ | Admin/Prüfer |

**Analyse (multipart/form-data):**
- `file`: Dokument (PDF/JPG/PNG/TIFF/CSV/Excel/Word, max 20MB)

**Response:**
```json
{
  "ok": true,
  "analysis": {
    "document_type": "fensterliste",
    "summary": "Fensterliste mit 45 Einträgen für Gebäude A",
    "items": [
      {
        "type": "window",
        "status": "new",
        "data": {"window_number": "F-001", "room_name": "Büro 101", ...},
        "confidence": 0.95,
        "change_description": null
      }
    ]
  },
  "file_name": "fensterliste_geb_a.pdf"
}
```

**Anwenden:**
```json
{
  "items": [
    {"type": "building", "status": "new", "data": {"name": "Gebäude B", "code": "GB"}},
    {"type": "window", "status": "update", "data": {"window_number": "F-001", ...}}
  ]
}
```
**Response:**
```json
{
  "ok": true,
  "created": [...],
  "updated": [...],
  "skipped": [...],
  "errors": [...],
  "summary": "5 angelegt, 2 ergänzt, 10 übersprungen, 0 Fehler"
}
```

---

## Fehlerformat

Alle Fehler als JSON:
```json
{"error": "Beschreibung des Fehlers"}
```

HTTP-Statuscodes:
- `400` – Ungültige Anfrage
- `401` – Nicht angemeldet
- `403` – Keine Berechtigung
- `404` – Nicht gefunden
- `409` – Konflikt (z.B. Datensatz gesperrt)
- `503` – Dienst nicht verfügbar (z.B. KI-API-Key fehlt)
