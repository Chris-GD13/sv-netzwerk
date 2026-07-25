-- =============================================================================
-- SVOS Inspection Platform – Initiales Datenbankschema
-- Version: 001
-- Ziel-DBMS: MySQL 8.0 / MariaDB 10.6+
-- Zeichensatz: utf8mb4, Kollation: utf8mb4_unicode_ci
-- Zeitzone: UTC (Verbindung setzt time_zone = '+00:00')
--
-- Architektur:
--   Core-Tabellen: users, login_attempts, inspection_modules,
--                  projects, buildings, building_sections, floors, rooms,
--                  inspections, record_locks, photos, documents,
--                  audit_logs, password_reset_tokens, export_logs
--   Modul-Tabellen: window_records (Modul "windows"),
--                   calculation_parameters
--
-- Jedes zuekuenftige Inspektionsmodul erhaelt eine eigene Modul-Tabelle
-- (z. B. water_damage_records, fire_damage_records) und einen Eintrag
-- in inspection_modules. Die Core-Tabellen bleiben unveraendert.
-- =============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;

-- ─────────────────────────────────────────────────────────────────────────────
-- CORE: Benutzer
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS users (
    id            VARCHAR(36)   NOT NULL,
    email         VARCHAR(255)  NOT NULL,
    full_name     VARCHAR(255)  NOT NULL,
    role          VARCHAR(50)   NOT NULL DEFAULT 'pruefer'
                                COMMENT 'administrator | pruefer | auswertung',
    password_hash VARCHAR(255)  NOT NULL,
    is_active     TINYINT(1)    NOT NULL DEFAULT 1,
    created_by    VARCHAR(36),
    created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    INDEX idx_users_role (role),
    INDEX idx_users_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- CORE: Login-Versuche (Rate-Limiting)
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS login_attempts (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email      VARCHAR(255)    NOT NULL,
    ip_address VARCHAR(45)     NOT NULL,
    success    TINYINT(1)      NOT NULL DEFAULT 0,
    created_at DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_login_ip_time  (ip_address, created_at),
    INDEX idx_login_email    (email, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- CORE: Passwort-Reset-Tokens
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id         VARCHAR(36)  NOT NULL,
    user_id    VARCHAR(36)  NOT NULL,
    token_hash VARCHAR(255) NOT NULL COMMENT 'sha256 des Tokens, nie Klartext',
    expires_at DATETIME     NOT NULL,
    used_at    DATETIME,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_prt_user    (user_id),
    INDEX idx_prt_expires (expires_at),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- CORE: Modul-Register
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS inspection_modules (
    id          VARCHAR(36)   NOT NULL,
    slug        VARCHAR(64)   NOT NULL COMMENT 'URL-sicherer Bezeichner, z. B. windows',
    name        VARCHAR(255)  NOT NULL,
    version     VARCHAR(20)   NOT NULL DEFAULT '1.0.0',
    description TEXT,
    is_active   TINYINT(1)    NOT NULL DEFAULT 1,
    created_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_modules_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- CORE: Projekte
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS projects (
    id          VARCHAR(36)  NOT NULL,
    name        VARCHAR(255) NOT NULL,
    description TEXT,
    location    VARCHAR(255),
    client_name VARCHAR(255),
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_by  VARCHAR(36),
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_projects_active (is_active),
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- CORE: Gebaeude
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS buildings (
    id          VARCHAR(36)  NOT NULL,
    project_id  VARCHAR(36)  NOT NULL,
    label       VARCHAR(255) NOT NULL,
    code        VARCHAR(50),
    description TEXT,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_buildings_project (project_id),
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- CORE: Gebaeudeteile
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS building_sections (
    id          VARCHAR(36)  NOT NULL,
    building_id VARCHAR(36)  NOT NULL,
    label       VARCHAR(255) NOT NULL,
    code        VARCHAR(50),
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_sections_building (building_id),
    FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- CORE: Etagen
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS floors (
    id           VARCHAR(36)  NOT NULL,
    building_id  VARCHAR(36)  NOT NULL,
    label        VARCHAR(255) NOT NULL,
    floor_number INT,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_floors_building (building_id),
    FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- CORE: Raeume
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS rooms (
    id          VARCHAR(36)  NOT NULL,
    building_id VARCHAR(36)  NOT NULL,
    floor_id    VARCHAR(36),
    section_id  VARCHAR(36),
    room_number VARCHAR(50),
    label       VARCHAR(255),
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_rooms_building (building_id),
    FOREIGN KEY (building_id) REFERENCES buildings(id) ON DELETE CASCADE,
    FOREIGN KEY (floor_id)   REFERENCES floors(id)    ON DELETE SET NULL,
    FOREIGN KEY (section_id) REFERENCES building_sections(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- CORE: Generische Inspektionen
--
-- Alle Inspektionsmodule schreiben ihre Datensaetze hierher (Kern-Felder).
-- Modulspezifische Felder liegen in eigenen Modul-Tabellen.
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS inspections (
    id                           VARCHAR(36)  NOT NULL,
    module_id                    VARCHAR(36)  NOT NULL,
    project_id                   VARCHAR(36)  NOT NULL,
    building_id                  VARCHAR(36),
    section_id                   VARCHAR(36),
    floor_id                     VARCHAR(36),
    room_id                      VARCHAR(36),
    record_id                    VARCHAR(50)  NOT NULL COMMENT 'Anzeige-Kennung, z. B. BMVG-XXXXXXXX',
    status                       VARCHAR(50)  NOT NULL DEFAULT 'nicht begonnen',
    overall_rating               VARCHAR(50),
    priority                     VARCHAR(20),
    has_defect                   TINYINT(1)   NOT NULL DEFAULT 0,
    danger_immediate             TINYINT(1)   NOT NULL DEFAULT 0,
    special_inspection_required  TINYINT(1)   NOT NULL DEFAULT 0,
    urgent_action_required       TINYINT(1)   NOT NULL DEFAULT 0,
    progress_percent             TINYINT UNSIGNED NOT NULL DEFAULT 0,
    assigned_to                  VARCHAR(36),
    assigned_name                VARCHAR(255),
    completed_at                 DATETIME,
    version                      INT UNSIGNED NOT NULL DEFAULT 0
                                 COMMENT 'Optimistisches Locking',
    created_by                   VARCHAR(36),
    deleted_at                   DATETIME     COMMENT 'Soft-Delete',
    created_at                   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_insp_module_project  (module_id, project_id),
    INDEX idx_insp_project         (project_id),
    INDEX idx_insp_status          (status),
    INDEX idx_insp_assigned        (assigned_to),
    INDEX idx_insp_deleted         (deleted_at),
    FOREIGN KEY (module_id)    REFERENCES inspection_modules(id),
    FOREIGN KEY (project_id)   REFERENCES projects(id),
    FOREIGN KEY (assigned_to)  REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (building_id)  REFERENCES buildings(id) ON DELETE SET NULL,
    FOREIGN KEY (section_id)   REFERENCES building_sections(id) ON DELETE SET NULL,
    FOREIGN KEY (floor_id)     REFERENCES floors(id)    ON DELETE SET NULL,
    FOREIGN KEY (room_id)      REFERENCES rooms(id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- CORE: Datensatz-Sperren (Record Locking)
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS record_locks (
    inspection_id VARCHAR(36)  NOT NULL,
    user_id       VARCHAR(36)  NOT NULL,
    user_name     VARCHAR(255) NOT NULL,
    locked_until  DATETIME     NOT NULL,
    PRIMARY KEY (inspection_id),
    INDEX idx_locks_user    (user_id),
    INDEX idx_locks_expires (locked_until),
    FOREIGN KEY (inspection_id) REFERENCES inspections(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)       REFERENCES users(id)       ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- CORE: Fotos (moduluebergreifend)
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS photos (
    id             VARCHAR(36)  NOT NULL,
    inspection_id  VARCHAR(36)  NOT NULL,
    category       VARCHAR(100) NOT NULL DEFAULT 'sonstiges',
    caption        TEXT,
    file_name      VARCHAR(255) NOT NULL COMMENT 'Original-Dateiname (nur Metadatum)',
    storage_path   VARCHAR(255) NOT NULL COMMENT 'Sicherer Speicherpfad (zufaellig generiert)',
    taken_at       DATETIME,
    inspector_id   VARCHAR(36),
    inspector_name VARCHAR(255),
    deleted_at     DATETIME,
    deleted_by     VARCHAR(36),
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_photos_inspection (inspection_id),
    INDEX idx_photos_deleted    (deleted_at),
    FOREIGN KEY (inspection_id) REFERENCES inspections(id) ON DELETE CASCADE,
    FOREIGN KEY (inspector_id)  REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- CORE: Dokumente / Anhaenge (moduluebergreifend)
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS documents (
    id             VARCHAR(36)  NOT NULL,
    inspection_id  VARCHAR(36)  NOT NULL,
    category       VARCHAR(100) NOT NULL DEFAULT 'sonstiges',
    description    TEXT,
    file_name      VARCHAR(255) NOT NULL,
    storage_path   VARCHAR(255) NOT NULL,
    mime_type      VARCHAR(100) NOT NULL,
    file_size      INT UNSIGNED NOT NULL DEFAULT 0,
    uploader_id    VARCHAR(36),
    uploader_name  VARCHAR(255),
    deleted_at     DATETIME,
    deleted_by     VARCHAR(36),
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_docs_inspection (inspection_id),
    FOREIGN KEY (inspection_id) REFERENCES inspections(id) ON DELETE CASCADE,
    FOREIGN KEY (uploader_id)   REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- CORE: Audit-Log (unveraenderlich – keine UPDATE/DELETE erlaubt)
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS audit_logs (
    id            VARCHAR(36)   NOT NULL,
    actor_id      VARCHAR(36),
    actor_name    VARCHAR(255)  NOT NULL,
    action_type   VARCHAR(100)  NOT NULL
                  COMMENT 'login|logout|failed_login|create|update|delete|upload|export|…',
    entity_type   VARCHAR(100)  NOT NULL,
    entity_id     VARCHAR(36),
    inspection_id VARCHAR(36)   COMMENT 'Optionaler Bezug zu einer Inspektion',
    field_name    VARCHAR(100),
    old_value     TEXT,
    new_value     TEXT,
    reason        TEXT,
    ip_address    VARCHAR(45),
    created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_audit_actor      (actor_id),
    INDEX idx_audit_inspection (inspection_id),
    INDEX idx_audit_type       (action_type),
    INDEX idx_audit_created    (created_at)
    -- Kein FK auf inspections: Log muss auch nach Loeschung erhalten bleiben
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────────────────────
-- CORE: Export-Log
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS export_logs (
    id          VARCHAR(36)  NOT NULL,
    module_id   VARCHAR(36),
    project_id  VARCHAR(36),
    export_type VARCHAR(50)  NOT NULL COMMENT 'csv | html_report | pdf',
    filter      VARCHAR(100),
    row_count   INT UNSIGNED NOT NULL DEFAULT 0,
    created_by  VARCHAR(36),
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_exports_module  (module_id),
    INDEX idx_exports_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- MODUL: Fensterbeschlagspruefung (windows)
-- =============================================================================

-- Modul-spezifische Felder der Fensterpruefung.
-- Alle Kern-Felder (Status, Flags, Zuweisung) liegen in `inspections`.
CREATE TABLE IF NOT EXISTS window_records (
    id                   VARCHAR(36)   NOT NULL,
    inspection_id        VARCHAR(36)   NOT NULL,
    inspection_number    VARCHAR(50)   COMMENT 'Pruefnummer gemaess Bestandsliste',
    window_number        VARCHAR(50)   COMMENT 'Fensternummer im Gebaeude',
    accessibility_status VARCHAR(50)   NOT NULL DEFAULT 'zugaenglich'
                         COMMENT 'zugaenglich | bedingt zugaenglich | nicht zugaenglich',
    building_label       VARCHAR(255)  COMMENT 'Gebaeude (Freitext fuer BMVg-Struktur)',
    section_label        VARCHAR(255)  COMMENT 'Gebaeudeteil',
    floor_label          VARCHAR(255)  COMMENT 'Etage',
    room_number          VARCHAR(50),
    room_label           VARCHAR(255),
    form_data            JSON          COMMENT 'Alle fenster-spezifischen Formularfelder',
    calculated_data      JSON          COMMENT 'Berechnete Werte (Fluegelmasse, Gewicht, …)',
    updated_at           DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_window_records_inspection (inspection_id),
    INDEX idx_window_number (window_number),
    FOREIGN KEY (inspection_id) REFERENCES inspections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Berechnungsparameter (z. B. Beschlaggewichte, Grenzwerte)
CREATE TABLE IF NOT EXISTS calculation_parameters (
    id          VARCHAR(36)  NOT NULL,
    module_id   VARCHAR(36)  NOT NULL,
    param_key   VARCHAR(100) NOT NULL,
    param_value VARCHAR(255) NOT NULL,
    unit        VARCHAR(50),
    description TEXT,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_calc_params_module_key (module_id, param_key),
    FOREIGN KEY (module_id) REFERENCES inspection_modules(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- SEED-DATEN
-- =============================================================================

-- Modul: Fensterbeschlagspruefung
INSERT IGNORE INTO inspection_modules (id, slug, name, version, description)
VALUES (
    '00000000-0000-4000-8000-000000000001',
    'windows',
    'Fensterbeschlagspruefung',
    '1.0.0',
    'BMVg Bonn – Inspektion und Bewertung von Fensterbeschlaegen'
);

-- Standardprojekt: BMVg Bonn
INSERT IGNORE INTO projects (id, name, description, location, client_name)
VALUES (
    '11111111-1111-4111-8111-111111111111',
    'Fensterbeschlagspruefung BMVg Bonn',
    '1. Dienstsitz des Bundesministeriums der Verteidigung',
    'Fontainengraben 150, 53123 Bonn',
    'Bundesministerium der Verteidigung'
);

-- Berechnungsparameter (Beispielwerte – vor Produktivsetzung pruefen)
INSERT IGNORE INTO calculation_parameters
    (id, module_id, param_key, param_value, unit, description)
VALUES
    ('c0000001-0000-4000-8000-000000000001',
     '00000000-0000-4000-8000-000000000001',
     'max_fluegel_gewicht_standard', '80', 'kg',
     'Maximales Fluegelgewicht fuer Standardbeschlag'),
    ('c0000002-0000-4000-8000-000000000001',
     '00000000-0000-4000-8000-000000000001',
     'max_fluegel_gewicht_schwer', '130', 'kg',
     'Maximales Fluegelgewicht fuer Schwerbeschlag'),
    ('c0000003-0000-4000-8000-000000000001',
     '00000000-0000-4000-8000-000000000001',
     'dichte_glas_standard', '2.5', 'kg/dm²',
     'Spezifisches Gewicht Standardverglasung'),
    ('c0000004-0000-4000-8000-000000000001',
     '00000000-0000-4000-8000-000000000001',
     'dichte_glas_dreischeibig', '3.0', 'kg/dm²',
     'Spezifisches Gewicht Dreischeibenverglasung');

SET foreign_key_checks = 1;
