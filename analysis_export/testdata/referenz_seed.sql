-- ============================================================
-- REFERENZ-SEED (aktuelles Portal-Schema)
-- Kompatibel mit sv-netzwerk/public/intern/api/schema.sql
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- Aufräumen nur der Testdaten (idempotent)
DELETE FROM window_sashes WHERE window_id IN (10001, 10002, 10003);
DELETE FROM windows WHERE id IN (10001, 10002, 10003);
DELETE FROM rooms WHERE id IN (1001, 1002);
DELETE FROM floors WHERE id IN (101);
DELETE FROM buildings WHERE id IN (11);

DELETE FROM users
WHERE email IN (
  'admin@testprojekt.local',
  'cw@sv-schuett.eu',
  'hr@sv-schuett.eu',
  'ms@sv-schuett.eu',
  'gast@testprojekt.local'
);

-- Projekt 1 existiert per schema.sql standardmäßig bereits.

-- Benutzer (Passwort für alle: Test2026!)
INSERT INTO users (id, email, full_name, role, password_hash, is_active, created_at, updated_at)
VALUES
  (1001, 'admin@testprojekt.local', 'Administrator Test', 'administrator', '$argon2id$v=19$m=65536,t=4,p=1$dGVzdHNhbHQxMjM0NTY$K2y7RzPjGz7Xv+FhBbQHJI5/lLH3z8dTqNOA+y3w4Ck', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
  (1002, 'cw@sv-schuett.eu', 'Christian Wächter', 'administrator', '$argon2id$v=19$m=65536,t=4,p=1$dGVzdHNhbHQxMjM0NTY$K2y7RzPjGz7Xv+FhBbQHJI5/lLH3z8dTqNOA+y3w4Ck', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
  (1003, 'hr@sv-schuett.eu', 'Holger Roth', 'sachverstaendiger', '$argon2id$v=19$m=65536,t=4,p=1$dGVzdHNhbHQxMjM0NTY$K2y7RzPjGz7Xv+FhBbQHJI5/lLH3z8dTqNOA+y3w4Ck', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
  (1004, 'ms@sv-schuett.eu', 'Marc Schütt', 'projektleiter', '$argon2id$v=19$m=65536,t=4,p=1$dGVzdHNhbHQxMjM0NTY$K2y7RzPjGz7Xv+FhBbQHJI5/lLH3z8dTqNOA+y3w4Ck', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
  (1005, 'gast@testprojekt.local', 'Gast Test', 'gast', '$argon2id$v=19$m=65536,t=4,p=1$dGVzdHNhbHQxMjM0NTY$K2y7RzPjGz7Xv+FhBbQHJI5/lLH3z8dTqNOA+y3w4Ck', 1, UTC_TIMESTAMP(), UTC_TIMESTAMP());

-- Gebäude / Etage / Räume
INSERT INTO buildings (id, project_id, name, code, notes, sort_order, created_at, updated_at)
VALUES
  (11, 1, 'Integrationstest Gebäude A', 'ITA', 'Seed für Portal-Tests', 110, UTC_TIMESTAMP(), UTC_TIMESTAMP());

INSERT INTO floors (id, building_id, name, level, notes, sort_order, created_at, updated_at)
VALUES
  (101, 11, 'Erdgeschoss', 0, NULL, 10, UTC_TIMESTAMP(), UTC_TIMESTAMP());

INSERT INTO rooms (id, floor_id, name, room_number, notes, sort_order, created_at, updated_at)
VALUES
  (1001, 101, 'Empfang', 'EG-01', NULL, 10, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
  (1002, 101, 'Büro 1', 'EG-02', NULL, 20, UTC_TIMESTAMP(), UTC_TIMESTAMP());

-- Fenster
INSERT INTO windows (
  id, project_id, room_id, record_id, inspection_number, window_number,
  object_label, building_label, section_label, floor_label, room_label, room_number,
  accessibility_status, status, overall_rating, priority,
  assigned_to, assigned_name, special_inspection_required, urgent_action_required,
  has_defect, danger_immediate, progress_percent,
  form_data, calculated_data, last_edited_at, created_at, updated_at
)
VALUES
  (
    10001, 1, 1001, 'IT-REC-10001', 1, 'F-001',
    'Portal-Test', 'Integrationstest Gebäude A', 'Nord', 'Erdgeschoss', 'Empfang', 'EG-01',
    'zugaenglich', 'nicht begonnen', 'ohne festgestellten Handlungsbedarf', 'normal',
    1002, 'Christian Wächter', 0, 0, 0, 0, 10,
    '{}', '{}', UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP()
  ),
  (
    10002, 1, 1001, 'IT-REC-10002', 2, 'F-002',
    'Portal-Test', 'Integrationstest Gebäude A', 'Nord', 'Erdgeschoss', 'Empfang', 'EG-01',
    'zugaenglich', 'in Bearbeitung', 'Wartung oder Nachstellung erforderlich', 'hoch',
    1003, 'Holger Roth', 1, 0, 1, 0, 55,
    '{}', '{}', UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP()
  ),
  (
    10003, 1, 1002, 'IT-REC-10003', 3, 'F-003',
    'Portal-Test', 'Integrationstest Gebäude A', 'Sued', 'Erdgeschoss', 'Büro 1', 'EG-02',
    'zugaenglich', 'abgeschlossen', 'ohne festgestellten Handlungsbedarf', 'normal',
    1004, 'Marc Schütt', 0, 0, 0, 0, 100,
    '{}', '{}', UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP()
  );

-- Flügel
INSERT INTO window_sashes (
  id, window_id, sash_number, sash_label, opening_type, position,
  status, form_data, progress_percent, has_defect, urgent_action,
  overall_rating, inspector_id, inspector_name, inspected_at, completed_at,
  created_at, updated_at
)
VALUES
  (20001, 10001, 1, 'Flügel links', 'Dreh-Kipp', 'links',  'nicht begonnen', '{}', 10, 0, 0, 'offen', 1002, 'Christian Wächter', UTC_TIMESTAMP(), NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
  (20002, 10002, 1, 'Flügel rechts','Dreh-Kipp', 'rechts', 'in Bearbeitung', '{}', 55, 1, 0, 'mangel', 1003, 'Holger Roth',      UTC_TIMESTAMP(), NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP()),
  (20003, 10003, 1, 'Flügel links', 'Dreh',      'links',  'abgeschlossen',  '{}', 100,0, 0, 'ok',     1004, 'Marc Schütt',      UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP());

SET FOREIGN_KEY_CHECKS = 1;
