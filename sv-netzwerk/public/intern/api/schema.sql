-- MySQL-Schema – Fensterbeschlagsprüfung BMVg Bonn
-- SV-Büro Marc Schütt e.K. | Version 1.1 – 2026-07-25
-- Ausführung: mysql -u <user> -p <datenbank> < schema.sql
--
-- Migration von Version 1.0 auf 1.1: Neue Benutzerrollen
--   ALTER TABLE users MODIFY COLUMN role
--     ENUM('administrator','projektleiter','sachverstaendiger','pruefer','auswertung','gast')
--     NOT NULL DEFAULT 'pruefer';

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ============================================================
-- Benutzer und Authentifizierung
-- ============================================================

CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    email           VARCHAR(255)    NOT NULL UNIQUE,
    full_name       VARCHAR(255)    NOT NULL,
    role            ENUM('administrator','projektleiter','sachverstaendiger','pruefer','auswertung','gast') NOT NULL DEFAULT 'pruefer',
    password_hash   VARCHAR(255)    NOT NULL,
    is_active       TINYINT(1)      NOT NULL DEFAULT 1,
    last_login_at   DATETIME        NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
    user_id     INT UNSIGNED    NOT NULL,
    token       VARCHAR(64)     NOT NULL,
    expires_at  DATETIME        NOT NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Projekte (Bundeswehr-Auftrag)
-- ============================================================

CREATE TABLE IF NOT EXISTS projects (
    id                   INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    project_code         VARCHAR(64)     NOT NULL UNIQUE,
    title                VARCHAR(255)    NOT NULL,
    object_name          VARCHAR(255)    NOT NULL,
    address              VARCHAR(255)    NOT NULL,
    planned_window_count INT             NULL,
    is_active            TINYINT(1)      NOT NULL DEFAULT 1,
    created_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    archived_at          DATETIME        NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Fenster-Datensätze
-- ============================================================

CREATE TABLE IF NOT EXISTS windows (
    id                          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    project_id                  INT UNSIGNED    NOT NULL DEFAULT 1,
    record_id                   VARCHAR(64)     NOT NULL UNIQUE,
    inspection_number           INT             NULL,
    window_number               VARCHAR(64)     NOT NULL DEFAULT '',
    object_label                VARCHAR(255)    NULL,
    building_label              VARCHAR(255)    NULL,
    section_label               VARCHAR(255)    NULL,
    floor_label                 VARCHAR(255)    NULL,
    room_label                  VARCHAR(255)    NULL,
    room_number                 VARCHAR(64)     NULL,
    accessibility_status        VARCHAR(64)     NULL,
    status                      VARCHAR(64)     NOT NULL DEFAULT 'nicht begonnen',
    overall_rating              VARCHAR(128)    NULL,
    priority                    VARCHAR(32)     NULL,
    assigned_to                 INT UNSIGNED    NULL,
    assigned_name               VARCHAR(255)    NULL,
    special_inspection_required TINYINT(1)      NOT NULL DEFAULT 0,
    urgent_action_required      TINYINT(1)      NOT NULL DEFAULT 0,
    has_defect                  TINYINT(1)      NOT NULL DEFAULT 0,
    danger_immediate            TINYINT(1)      NOT NULL DEFAULT 0,
    progress_percent            TINYINT UNSIGNED NOT NULL DEFAULT 0,
    form_data                   LONGTEXT        NULL COMMENT 'JSON Formulardaten',
    calculated_data             TEXT            NULL COMMENT 'JSON Berechnungsdaten',
    last_edited_at              DATETIME        NULL,
    completed_at                DATETIME        NULL,
    released_at                 DATETIME        NULL,
    released_by                 INT UNSIGNED    NULL,
    release_reason              TEXT            NULL,
    version                     INT UNSIGNED    NOT NULL DEFAULT 1,
    created_at                  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at                  DATETIME        NULL,
    PRIMARY KEY (id),
    KEY idx_windows_project   (project_id),
    KEY idx_windows_status    (status),
    KEY idx_windows_assigned  (assigned_to),
    KEY idx_windows_deleted   (deleted_at),
    CONSTRAINT fk_win_project  FOREIGN KEY (project_id)  REFERENCES projects (id),
    CONSTRAINT fk_win_user     FOREIGN KEY (assigned_to) REFERENCES users    (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Datensatzsperren (Record Locking)
-- ============================================================

CREATE TABLE IF NOT EXISTS record_locks (
    window_id   INT UNSIGNED    NOT NULL,
    owner_id    INT UNSIGNED    NOT NULL,
    owner_name  VARCHAR(255)    NOT NULL,
    expires_at  DATETIME        NOT NULL,
    PRIMARY KEY (window_id),
    KEY idx_locks_expires (expires_at),
    CONSTRAINT fk_lock_window FOREIGN KEY (window_id) REFERENCES windows (id) ON DELETE CASCADE,
    CONSTRAINT fk_lock_user   FOREIGN KEY (owner_id)  REFERENCES users   (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Audit-Log
-- ============================================================

CREATE TABLE IF NOT EXISTS audit_logs (
    id          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    window_id   INT UNSIGNED    NOT NULL,
    actor_id    INT UNSIGNED    NULL,
    actor_name  VARCHAR(255)    NOT NULL,
    action_type VARCHAR(64)     NOT NULL,
    field_name  VARCHAR(128)    NULL,
    old_value   TEXT            NULL,
    new_value   TEXT            NULL,
    reason      TEXT            NULL,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_window  (window_id),
    KEY idx_audit_created (created_at),
    CONSTRAINT fk_audit_window FOREIGN KEY (window_id) REFERENCES windows (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Fotos
-- ============================================================

CREATE TABLE IF NOT EXISTS photos (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    window_id       INT UNSIGNED    NOT NULL,
    category        VARCHAR(64)     NOT NULL,
    caption         VARCHAR(512)    NULL,
    file_name       VARCHAR(255)    NOT NULL,
    storage_path    VARCHAR(512)    NOT NULL,
    inspector_id    INT UNSIGNED    NULL,
    inspector_name  VARCHAR(255)    NULL,
    taken_at        DATETIME        NULL,
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at      DATETIME        NULL,
    PRIMARY KEY (id),
    KEY idx_photos_window  (window_id),
    KEY idx_photos_deleted (deleted_at),
    CONSTRAINT fk_photo_window FOREIGN KEY (window_id) REFERENCES windows (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Berechnungsparameter (global, projektspezifisch überschreibbar)
-- ============================================================

CREATE TABLE IF NOT EXISTS calculation_parameters (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    project_id      INT UNSIGNED    NULL COMMENT 'NULL = global',
    parameter_key   VARCHAR(64)     NOT NULL,
    parameter_value DECIMAL(15,6)   NOT NULL,
    updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY idx_params_key (project_id, parameter_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Export-Log
-- ============================================================

CREATE TABLE IF NOT EXISTS export_logs (
    id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    project_id      INT UNSIGNED    NOT NULL DEFAULT 1,
    export_type     VARCHAR(64)     NOT NULL,
    exported_by     INT UNSIGNED    NULL,
    file_name       VARCHAR(255)    NULL,
    filter_snapshot TEXT            NULL COMMENT 'JSON',
    created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_export_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Gebäude
-- ============================================================

CREATE TABLE IF NOT EXISTS buildings (
    id          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    project_id  INT UNSIGNED    NOT NULL DEFAULT 1,
    name        VARCHAR(255)    NOT NULL,
    code        VARCHAR(64)     NOT NULL DEFAULT '',
    notes       TEXT            NULL,
    sort_order  INT             NOT NULL DEFAULT 0,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_buildings_project (project_id),
    CONSTRAINT fk_building_project FOREIGN KEY (project_id) REFERENCES projects (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Etagen
-- ============================================================

CREATE TABLE IF NOT EXISTS floors (
    id          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    building_id INT UNSIGNED    NOT NULL,
    name        VARCHAR(255)    NOT NULL,
    level       INT             NOT NULL DEFAULT 0,
    notes       TEXT            NULL,
    sort_order  INT             NOT NULL DEFAULT 0,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_floors_building (building_id),
    CONSTRAINT fk_floor_building FOREIGN KEY (building_id) REFERENCES buildings (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Räume
-- ============================================================

CREATE TABLE IF NOT EXISTS rooms (
    id          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    floor_id    INT UNSIGNED    NOT NULL,
    name        VARCHAR(255)    NOT NULL,
    room_number VARCHAR(64)     NOT NULL DEFAULT '',
    notes       TEXT            NULL,
    sort_order  INT             NOT NULL DEFAULT 0,
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_rooms_floor (floor_id),
    CONSTRAINT fk_room_floor FOREIGN KEY (floor_id) REFERENCES floors (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Raumzuordnung zu Fenstern (optional, rückwärtskompatibel)
-- ============================================================

ALTER TABLE windows ADD COLUMN IF NOT EXISTS room_id INT UNSIGNED NULL AFTER project_id;

-- ============================================================
-- Flügel (Fensterflügel – das eigentliche Prüfobjekt)
-- ============================================================

CREATE TABLE IF NOT EXISTS window_sashes (
    id               INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    window_id        INT UNSIGNED    NOT NULL,
    sash_number      INT             NOT NULL DEFAULT 1,
    sash_label       VARCHAR(64)     NOT NULL DEFAULT '',
    opening_type     VARCHAR(64)     NULL COMMENT 'Dreh, Dreh-Kipp, Kipp, Festverglasung',
    position         VARCHAR(64)     NULL COMMENT 'links, rechts, mitte, oben, unten',
    status           VARCHAR(64)     NOT NULL DEFAULT 'nicht begonnen',
    form_data        LONGTEXT        NULL COMMENT 'JSON Inspektionsdaten',
    progress_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
    has_defect       TINYINT(1)      NOT NULL DEFAULT 0,
    urgent_action    TINYINT(1)      NOT NULL DEFAULT 0,
    overall_rating   VARCHAR(128)    NULL,
    inspector_id     INT UNSIGNED    NULL,
    inspector_name   VARCHAR(255)    NULL,
    inspected_at     DATETIME        NULL,
    completed_at     DATETIME        NULL,
    created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at       DATETIME        NULL,
    PRIMARY KEY (id),
    KEY idx_sashes_window  (window_id),
    KEY idx_sashes_status  (status),
    KEY idx_sashes_deleted (deleted_at),
    CONSTRAINT fk_sash_window FOREIGN KEY (window_id) REFERENCES windows (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Flügelzuordnung für Fotos (optional, rückwärtskompatibel)
-- ============================================================

ALTER TABLE photos ADD COLUMN IF NOT EXISTS sash_id INT UNSIGNED NULL AFTER window_id;

-- ============================================================
-- Standard-Projektdatensatz
-- ============================================================

INSERT IGNORE INTO projects (id, project_code, title, object_name, address, planned_window_count, is_active)
VALUES (
    1,
    'fensterpruefung-bonn',
    'Fensterbeschlagsprüfung BMVg Bonn',
    '1. Dienstsitz des Bundesministeriums der Verteidigung',
    'Fontainengraben 150, 53123 Bonn',
    450,
    1
);

-- ============================================================
-- Standard-Berechnungsparameter
-- ============================================================

INSERT IGNORE INTO calculation_parameters (project_id, parameter_key, parameter_value)
VALUES
    (NULL, 'glassDensityKgPerM2Mm', 2.5),
    (NULL, 'frameWeightFactor',     0.18),
    (NULL, 'safetyFactor',          1.1);
