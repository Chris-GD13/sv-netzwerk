# Datenbankschema – Fensterbeschlagsprüfung BMVg Bonn

## Übersicht

13 Tabellen, MySQL 8.0 mit InnoDB, Zeichensatz utf8mb4_unicode_ci.

---

## ER-Diagramm (vereinfacht)

```
users ─────────────────────────────────────────────┐
  │                                                 │
password_resets (1:1)                               │
                                                    │
projects ──────── buildings ──── floors ──── rooms  │
    │                                        │      │
    └──── windows ───────────────────────────┘      │
              │                                     │
              ├── window_sashes                     │
              ├── record_locks ─────────────────────┘
              ├── audit_logs
              ├── photos
              └── export_logs
              
calculation_parameters (global/projektspezifisch)
```

---

## Tabellen

### 1. `users` – Benutzer und Authentifizierung

| Feld | Typ | Null | Beschreibung |
|------|-----|------|--------------|
| id | INT UNSIGNED AUTO_INCREMENT | PK | Benutzer-ID |
| email | VARCHAR(255) UNIQUE | NOT NULL | E-Mail-Adresse (Login) |
| full_name | VARCHAR(255) | NOT NULL | Anzeigename |
| role | ENUM('administrator','projektleiter','sachverstaendiger','pruefer','auswertung','gast') | NOT NULL, Default 'pruefer' | Benutzerrolle |
| password_hash | VARCHAR(255) | NOT NULL | Argon2ID-Hash |
| is_active | TINYINT(1) | NOT NULL, Default 1 | Konto aktiv? |
| last_login_at | DATETIME | NULL | Letzter Login |
| created_at | DATETIME | NOT NULL, Default CURRENT_TIMESTAMP | Erstellt |
| updated_at | DATETIME | NOT NULL, Auto-Update | Geändert |

### 2. `password_resets` – Passwort-Reset-Token

| Feld | Typ | Null | Beschreibung |
|------|-----|------|--------------|
| user_id | INT UNSIGNED | PK, FK→users.id (CASCADE) | Benutzer |
| token | VARCHAR(64) | NOT NULL | Reset-Token |
| expires_at | DATETIME | NOT NULL | Gültig bis |
| created_at | DATETIME | NOT NULL | Erstellt |

### 3. `projects` – Prüfprojekte

| Feld | Typ | Null | Beschreibung |
|------|-----|------|--------------|
| id | INT UNSIGNED AUTO_INCREMENT | PK | Projekt-ID |
| project_code | VARCHAR(64) UNIQUE | NOT NULL | URL-Slug |
| title | VARCHAR(255) | NOT NULL | Projekttitel |
| object_name | VARCHAR(255) | NOT NULL | Gebäudekomplex |
| address | VARCHAR(255) | NOT NULL | Adresse |
| planned_window_count | INT | NULL | Geplante Fensteranzahl |
| is_active | TINYINT(1) | NOT NULL, Default 1 | Aktiv? |
| created_at | DATETIME | NOT NULL | Erstellt |
| updated_at | DATETIME | NOT NULL | Geändert |
| archived_at | DATETIME | NULL | Archiviert am |

### 4. `windows` – Fenster-Datensätze

| Feld | Typ | Null | Beschreibung |
|------|-----|------|--------------|
| id | INT UNSIGNED AUTO_INCREMENT | PK | Fenster-ID |
| project_id | INT UNSIGNED | NOT NULL, FK→projects.id | Projekt |
| room_id | INT UNSIGNED | NULL | Raum-Zuordnung |
| record_id | VARCHAR(64) UNIQUE | NOT NULL | Prüfnummer (BMVG-XXXXXXXX) |
| inspection_number | INT | NULL | Inspektionsnummer |
| window_number | VARCHAR(64) | NOT NULL, Default '' | Fensternummer lt. Plan |
| object_label | VARCHAR(255) | NULL | Objekt-Bezeichnung |
| building_label | VARCHAR(255) | NULL | Gebäude |
| section_label | VARCHAR(255) | NULL | Abschnitt |
| floor_label | VARCHAR(255) | NULL | Etage |
| room_label | VARCHAR(255) | NULL | Raum |
| room_number | VARCHAR(64) | NULL | Raumnummer |
| accessibility_status | VARCHAR(64) | NULL | Zugänglichkeit |
| status | VARCHAR(64) | NOT NULL, Default 'nicht begonnen' | Prüfstatus |
| overall_rating | VARCHAR(128) | NULL | Gesamtbewertung |
| priority | VARCHAR(32) | NULL | Priorität |
| assigned_to | INT UNSIGNED | NULL, FK→users.id | Zugewiesen an |
| assigned_name | VARCHAR(255) | NULL | Name des Zugewiesenen |
| special_inspection_required | TINYINT(1) | NOT NULL, Default 0 | Spezialpüfung nötig? |
| urgent_action_required | TINYINT(1) | NOT NULL, Default 0 | Dringend? |
| has_defect | TINYINT(1) | NOT NULL, Default 0 | Mangel vorhanden? |
| danger_immediate | TINYINT(1) | NOT NULL, Default 0 | Unmittelbare Gefahr? |
| progress_percent | TINYINT UNSIGNED | NOT NULL, Default 0 | Fortschritt 0-100 |
| form_data | LONGTEXT | NULL | JSON: Formulardaten |
| calculated_data | TEXT | NULL | JSON: Berechnungswerte |
| last_edited_at | DATETIME | NULL | Letzte Bearbeitung |
| completed_at | DATETIME | NULL | Abgeschlossen am |
| released_at | DATETIME | NULL | Freigegeben am |
| released_by | INT UNSIGNED | NULL | Freigegeben durch |
| release_reason | TEXT | NULL | Freigabegrund |
| version | INT UNSIGNED | NOT NULL, Default 1 | Versionszähler |
| created_at | DATETIME | NOT NULL | Erstellt |
| updated_at | DATETIME | NOT NULL | Geändert |
| deleted_at | DATETIME | NULL | Soft-Delete |

**Indizes:** project_id, status, assigned_to, deleted_at

### 5. `window_sashes` – Fensterflügel (Prüfobjekte)

| Feld | Typ | Null | Beschreibung |
|------|-----|------|--------------|
| id | INT UNSIGNED AUTO_INCREMENT | PK | Flügel-ID |
| window_id | INT UNSIGNED | NOT NULL, FK→windows.id (CASCADE) | Eltern-Fenster |
| sash_number | INT | NOT NULL, Default 1 | Flügelnummer |
| sash_label | VARCHAR(64) | NOT NULL, Default '' | Bezeichnung |
| opening_type | VARCHAR(64) | NULL | Öffnungsart (Dreh/Kipp/DK/Fest) |
| position | VARCHAR(64) | NULL | Position (links/rechts/mitte) |
| status | VARCHAR(64) | NOT NULL, Default 'nicht begonnen' | Prüfstatus |
| form_data | LONGTEXT | NULL | JSON: Inspektionsdaten |
| progress_percent | TINYINT UNSIGNED | NOT NULL, Default 0 | Fortschritt |
| has_defect | TINYINT(1) | NOT NULL, Default 0 | Mangel? |
| urgent_action | TINYINT(1) | NOT NULL, Default 0 | Dringend? |
| overall_rating | VARCHAR(128) | NULL | Bewertung |
| inspector_id | INT UNSIGNED | NULL | Prüfer-ID |
| inspector_name | VARCHAR(255) | NULL | Prüfername |
| inspected_at | DATETIME | NULL | Geprüft am |
| completed_at | DATETIME | NULL | Abgeschlossen |
| created_at | DATETIME | NOT NULL | Erstellt |
| updated_at | DATETIME | NOT NULL | Geändert |
| deleted_at | DATETIME | NULL | Soft-Delete |

### 6. `record_locks` – Datensatzsperren

| Feld | Typ | Null | Beschreibung |
|------|-----|------|--------------|
| window_id | INT UNSIGNED | PK, FK→windows.id (CASCADE) | Gesperrtes Fenster |
| owner_id | INT UNSIGNED | NOT NULL, FK→users.id (CASCADE) | Sperrender Benutzer |
| owner_name | VARCHAR(255) | NOT NULL | Name |
| expires_at | DATETIME | NOT NULL | Ablaufzeit der Sperre |

### 7. `audit_logs` – Änderungsprotokoll

| Feld | Typ | Null | Beschreibung |
|------|-----|------|--------------|
| id | INT UNSIGNED AUTO_INCREMENT | PK | Log-ID |
| window_id | INT UNSIGNED | NOT NULL, FK→windows.id (CASCADE) | Betroffenes Fenster |
| actor_id | INT UNSIGNED | NULL | Handelnder Benutzer |
| actor_name | VARCHAR(255) | NOT NULL | Name |
| action_type | VARCHAR(64) | NOT NULL | Aktionstyp |
| field_name | VARCHAR(128) | NULL | Geändertes Feld |
| old_value | TEXT | NULL | Alter Wert |
| new_value | TEXT | NULL | Neuer Wert |
| reason | TEXT | NULL | Begründung |
| created_at | DATETIME | NOT NULL | Zeitstempel |

### 8. `photos` – Prüffotos

| Feld | Typ | Null | Beschreibung |
|------|-----|------|--------------|
| id | INT UNSIGNED AUTO_INCREMENT | PK | Foto-ID |
| window_id | INT UNSIGNED | NOT NULL, FK→windows.id (CASCADE) | Fenster |
| sash_id | INT UNSIGNED | NULL | Flügel (optional) |
| category | VARCHAR(64) | NOT NULL | Kategorie |
| caption | VARCHAR(512) | NULL | Beschriftung |
| file_name | VARCHAR(255) | NOT NULL | Dateiname |
| storage_path | VARCHAR(512) | NOT NULL | Speicherpfad |
| inspector_id | INT UNSIGNED | NULL | Fotograf |
| inspector_name | VARCHAR(255) | NULL | Name |
| taken_at | DATETIME | NULL | Aufnahmedatum |
| created_at | DATETIME | NOT NULL | Erstellt |
| deleted_at | DATETIME | NULL | Soft-Delete |

### 9. `calculation_parameters` – Berechnungsparameter

| Feld | Typ | Null | Beschreibung |
|------|-----|------|--------------|
| id | INT UNSIGNED AUTO_INCREMENT | PK | Parameter-ID |
| project_id | INT UNSIGNED | NULL | NULL=global |
| parameter_key | VARCHAR(64) | NOT NULL | Schlüssel |
| parameter_value | DECIMAL(15,6) | NOT NULL | Wert |
| updated_at | DATETIME | NOT NULL | Geändert |

### 10. `export_logs` – Export-Protokoll

| Feld | Typ | Null | Beschreibung |
|------|-----|------|--------------|
| id | INT UNSIGNED AUTO_INCREMENT | PK | Export-ID |
| project_id | INT UNSIGNED | NOT NULL, Default 1 | Projekt |
| export_type | VARCHAR(64) | NOT NULL | Exporttyp |
| exported_by | INT UNSIGNED | NULL | Benutzer |
| file_name | VARCHAR(255) | NULL | Dateiname |
| filter_snapshot | TEXT | NULL | JSON: Filter-Einstellungen |
| created_at | DATETIME | NOT NULL | Zeitstempel |

### 11. `buildings` – Gebäude

| Feld | Typ | Null | Beschreibung |
|------|-----|------|--------------|
| id | INT UNSIGNED AUTO_INCREMENT | PK | Gebäude-ID |
| project_id | INT UNSIGNED | NOT NULL, FK→projects.id | Projekt |
| name | VARCHAR(255) | NOT NULL | Gebäudename |
| code | VARCHAR(64) | NOT NULL, Default '' | Kürzel |
| notes | TEXT | NULL | Notizen |
| sort_order | INT | NOT NULL, Default 0 | Sortierung |
| created_at | DATETIME | NOT NULL | Erstellt |
| updated_at | DATETIME | NOT NULL | Geändert |

### 12. `floors` – Etagen

| Feld | Typ | Null | Beschreibung |
|------|-----|------|--------------|
| id | INT UNSIGNED AUTO_INCREMENT | PK | Etagen-ID |
| building_id | INT UNSIGNED | NOT NULL, FK→buildings.id (CASCADE) | Gebäude |
| name | VARCHAR(255) | NOT NULL | Etagenname |
| level | INT | NOT NULL, Default 0 | Geschoss-Nr. |
| notes | TEXT | NULL | Notizen |
| sort_order | INT | NOT NULL, Default 0 | Sortierung |
| created_at | DATETIME | NOT NULL | Erstellt |
| updated_at | DATETIME | NOT NULL | Geändert |

### 13. `rooms` – Räume

| Feld | Typ | Null | Beschreibung |
|------|-----|------|--------------|
| id | INT UNSIGNED AUTO_INCREMENT | PK | Raum-ID |
| floor_id | INT UNSIGNED | NOT NULL, FK→floors.id (CASCADE) | Etage |
| name | VARCHAR(255) | NOT NULL | Raumname |
| room_number | VARCHAR(64) | NOT NULL, Default '' | Raumnummer |
| notes | TEXT | NULL | Notizen |
| sort_order | INT | NOT NULL, Default 0 | Sortierung |
| created_at | DATETIME | NOT NULL | Erstellt |
| updated_at | DATETIME | NOT NULL | Geändert |

---

## Fremdschlüssel-Beziehungen

| Quelle | Ziel | Aktion bei Löschung |
|--------|------|---------------------|
| password_resets.user_id | users.id | CASCADE |
| windows.project_id | projects.id | RESTRICT |
| windows.assigned_to | users.id | SET NULL |
| window_sashes.window_id | windows.id | CASCADE |
| record_locks.window_id | windows.id | CASCADE |
| record_locks.owner_id | users.id | CASCADE |
| audit_logs.window_id | windows.id | CASCADE |
| photos.window_id | windows.id | CASCADE |
| buildings.project_id | projects.id | RESTRICT |
| floors.building_id | buildings.id | CASCADE |
| rooms.floor_id | floors.id | CASCADE |

---

## Kaskaden-Effekte

- Gebäude löschen → alle Etagen + Räume werden kaskadiert gelöscht
- Etage löschen → alle Räume werden gelöscht
- Fenster löschen → Flügel, Sperren, Audit-Log, Fotos werden kaskadiert gelöscht
- Benutzer löschen → Sperren und Reset-Token werden gelöscht, Zuweisungen auf NULL gesetzt
