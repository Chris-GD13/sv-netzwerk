insert into public.projects (id, project_code, title, object_name, address, planned_window_count)
values (
  '11111111-1111-4111-8111-111111111111',
  'fensterpruefung-bonn',
  'Fensterbeschlagspruefung BMVg Bonn',
  '1. Dienstsitz des Bundesministeriums der Verteidigung',
  'Fontainengraben 150, 53123 Bonn',
  450
)
on conflict (id) do update
set title = excluded.title,
    object_name = excluded.object_name,
    address = excluded.address,
    planned_window_count = excluded.planned_window_count;

insert into public.buildings (project_id, code, name, sort_order)
values
  ('11111111-1111-4111-8111-111111111111', 'HAUS-1', 'Hauptgebaeude', 1)
on conflict (project_id, code) do update set name = excluded.name;

with building_cte as (
  select id from public.buildings where project_id = '11111111-1111-4111-8111-111111111111' and code = 'HAUS-1'
), section_cte as (
  insert into public.building_sections (building_id, code, name, sort_order)
  select id, 'A', 'Bauteil A', 1 from building_cte
  on conflict (building_id, code) do update set name = excluded.name
  returning id
), floor_cte as (
  insert into public.floors (building_section_id, code, name, sort_order)
  select id, 'EG', 'Erdgeschoss', 1 from section_cte
  on conflict (building_section_id, code) do update set name = excluded.name
  returning id
)
insert into public.rooms (floor_id, room_number, room_label, sort_order)
select id, '0.101', 'Besprechung', 1 from floor_cte
on conflict (floor_id, room_number) do update set room_label = excluded.room_label;

insert into public.calculation_parameters (project_id, parameter_key, parameter_value, description)
values
  (null, 'glassDensityKgPerM2Mm', 2.5, 'Glasdichte in kg pro m² und mm'),
  (null, 'frameWeightFactor', 0.18, 'Faktor fuer geschaetztes Rahmengewicht'),
  (null, 'safetyFactor', 1.10, 'Sicherheitszuschlag fuer das angesetzte Pruefgewicht')
on conflict (project_id, parameter_key) do update
set parameter_value = excluded.parameter_value,
    description = excluded.description;

insert into public.windows (
  id,
  project_id,
  record_id,
  inspection_number,
  window_number,
  building_label,
  section_label,
  floor_label,
  room_label,
  room_number,
  status,
  accessibility_status,
  assigned_name,
  overall_rating,
  priority,
  progress_percent,
  form_data,
  calculated_data
)
values
  (
    '22222222-2222-4222-8222-222222222221',
    '11111111-1111-4111-8111-111111111111',
    'BMVG-DEMO-001',
    1,
    'F-001',
    'Hauptgebaeude',
    'Bauteil A',
    'Erdgeschoss',
    'Besprechung',
    '0.101',
    'in Bearbeitung',
    'vollstaendig zugaenglich',
    'Pruefer 1',
    'Wartung oder Nachstellung erforderlich',
    'mittel',
    72,
    '{"window_number":"F-001","inspection_number":1,"building_label":"Hauptgebaeude","section_label":"Bauteil A","floor_label":"Erdgeschoss","room_label":"Besprechung","room_number":"0.101","inspector_name":"Pruefer 1","inspection_date":"2026-07-23","glass_structure":"4/16/4","glazing_width_mm":860,"glazing_height_mm":1420,"applied_test_weight_kg":54.1,"weight_method":"Berechnung + Zuschlag","overall_rating":"Wartung oder Nachstellung erforderlich","priority":"mittel","recommended_action":"Beschlag nachstellen und Schraubverbindungen nachziehen.","status":"in Bearbeitung"}'::jsonb,
    '{"glassWeightKg":30.5,"frameWeightKg":5.5,"totalWingWeightKg":36.0,"appliedTestWeightKg":54.1}'::jsonb
  ),
  (
    '22222222-2222-4222-8222-222222222222',
    '11111111-1111-4111-8111-111111111111',
    'BMVG-DEMO-002',
    2,
    'F-002',
    'Hauptgebaeude',
    'Bauteil A',
    'Erdgeschoss',
    'Besprechung',
    '0.101',
    'nicht begonnen',
    'vollstaendig zugaenglich',
    'Pruefer 2',
    null,
    'keine',
    14,
    '{"window_number":"F-002","inspection_number":2,"building_label":"Hauptgebaeude","section_label":"Bauteil A","floor_label":"Erdgeschoss","room_label":"Besprechung","room_number":"0.101","inspector_name":"Pruefer 2","inspection_date":"2026-07-23","status":"nicht begonnen"}'::jsonb,
    '{}'::jsonb
  ),
  (
    '22222222-2222-4222-8222-222222222223',
    '11111111-1111-4111-8111-111111111111',
    'BMVG-DEMO-003',
    3,
    'F-003',
    'Hauptgebaeude',
    'Bauteil A',
    'Erdgeschoss',
    'Besprechung',
    '0.101',
    'nicht zugaenglich',
    'nicht zugaenglich',
    'Pruefer 3',
    'nicht abschliessend pruefbar',
    'hoch',
    48,
    '{"window_number":"F-003","inspection_number":3,"building_label":"Hauptgebaeude","section_label":"Bauteil A","floor_label":"Erdgeschoss","room_label":"Besprechung","room_number":"0.101","inspector_name":"Pruefer 3","inspection_date":"2026-07-23","accessibility_status":"nicht zugaenglich","window_not_openable":true,"access_note":"Zugang durch Einbauten behindert.","overall_rating":"nicht abschliessend pruefbar","priority":"hoch","recommended_action":"Zugaenglichkeit herstellen und Nachpruefung einplanen.","status":"nicht zugaenglich","special_inspection_required":true}'::jsonb,
    '{"glassWeightKg":0,"frameWeightKg":0,"totalWingWeightKg":0,"appliedTestWeightKg":0}'::jsonb
  )
on conflict (id) do update
set form_data = excluded.form_data,
    calculated_data = excluded.calculated_data,
    updated_at = timezone('utc', now());
