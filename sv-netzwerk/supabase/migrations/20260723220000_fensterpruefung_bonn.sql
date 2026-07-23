create extension if not exists pgcrypto;

create table if not exists public.profiles (
  id uuid primary key references auth.users (id) on delete cascade,
  email text unique,
  full_name text not null,
  role text not null check (role in ('administrator', 'pruefer', 'auswertung')) default 'pruefer',
  is_active boolean not null default true,
  created_at timestamptz not null default timezone('utc', now()),
  updated_at timestamptz not null default timezone('utc', now())
);

create table if not exists public.projects (
  id uuid primary key default gen_random_uuid(),
  project_code text not null unique,
  title text not null,
  object_name text not null,
  address text not null,
  planned_window_count integer,
  is_active boolean not null default true,
  created_by uuid references public.profiles (id),
  created_at timestamptz not null default timezone('utc', now()),
  updated_at timestamptz not null default timezone('utc', now()),
  archived_at timestamptz
);

create table if not exists public.buildings (
  id uuid primary key default gen_random_uuid(),
  project_id uuid not null references public.projects (id) on delete cascade,
  code text not null,
  name text not null,
  sort_order integer not null default 0,
  created_at timestamptz not null default timezone('utc', now()),
  updated_at timestamptz not null default timezone('utc', now()),
  archived_at timestamptz,
  unique (project_id, code)
);

create table if not exists public.building_sections (
  id uuid primary key default gen_random_uuid(),
  building_id uuid not null references public.buildings (id) on delete cascade,
  code text not null,
  name text not null,
  sort_order integer not null default 0,
  created_at timestamptz not null default timezone('utc', now()),
  updated_at timestamptz not null default timezone('utc', now()),
  archived_at timestamptz,
  unique (building_id, code)
);

create table if not exists public.floors (
  id uuid primary key default gen_random_uuid(),
  building_section_id uuid not null references public.building_sections (id) on delete cascade,
  code text not null,
  name text not null,
  sort_order integer not null default 0,
  created_at timestamptz not null default timezone('utc', now()),
  updated_at timestamptz not null default timezone('utc', now()),
  archived_at timestamptz,
  unique (building_section_id, code)
);

create table if not exists public.rooms (
  id uuid primary key default gen_random_uuid(),
  floor_id uuid not null references public.floors (id) on delete cascade,
  room_number text not null,
  room_label text,
  sort_order integer not null default 0,
  created_at timestamptz not null default timezone('utc', now()),
  updated_at timestamptz not null default timezone('utc', now()),
  archived_at timestamptz,
  unique (floor_id, room_number)
);

create table if not exists public.windows (
  id uuid primary key default gen_random_uuid(),
  project_id uuid not null references public.projects (id) on delete cascade,
  building_id uuid references public.buildings (id),
  building_section_id uuid references public.building_sections (id),
  floor_id uuid references public.floors (id),
  room_id uuid references public.rooms (id),
  assigned_to uuid references public.profiles (id),
  released_by uuid references public.profiles (id),
  record_id text not null unique,
  inspection_number integer,
  window_number text not null default '',
  object_label text,
  building_label text,
  section_label text,
  floor_label text,
  room_label text,
  room_number text,
  accessibility_status text,
  status text not null default 'nicht begonnen',
  overall_rating text,
  priority text,
  assigned_name text,
  special_inspection_required boolean not null default false,
  urgent_action_required boolean not null default false,
  has_defect boolean not null default false,
  danger_immediate boolean not null default false,
  progress_percent numeric(5,2) not null default 0,
  form_data jsonb not null default '{}'::jsonb,
  calculated_data jsonb not null default '{}'::jsonb,
  last_edited_at timestamptz,
  completed_at timestamptz,
  released_at timestamptz,
  release_reason text,
  version integer not null default 1,
  created_at timestamptz not null default timezone('utc', now()),
  updated_at timestamptz not null default timezone('utc', now()),
  deleted_at timestamptz,
  archived_at timestamptz
);

create table if not exists public.window_wings (
  id uuid primary key default gen_random_uuid(),
  window_id uuid not null references public.windows (id) on delete cascade,
  wing_identifier text not null,
  wing_position text,
  width_mm numeric(10,2),
  height_mm numeric(10,2),
  calculated_weight_kg numeric(10,2),
  notes text,
  data jsonb not null default '{}'::jsonb,
  created_by uuid references public.profiles (id),
  created_at timestamptz not null default timezone('utc', now()),
  updated_at timestamptz not null default timezone('utc', now()),
  deleted_at timestamptz
);

create table if not exists public.glazing_data (
  id uuid primary key default gen_random_uuid(),
  window_id uuid not null references public.windows (id) on delete cascade,
  glazing_type text,
  glass_structure text,
  glazing_width_mm numeric(10,2),
  glazing_height_mm numeric(10,2),
  glass_weight_kg numeric(10,2),
  frame_weight_kg numeric(10,2),
  total_weight_kg numeric(10,2),
  data jsonb not null default '{}'::jsonb,
  created_at timestamptz not null default timezone('utc', now()),
  updated_at timestamptz not null default timezone('utc', now()),
  deleted_at timestamptz,
  unique (window_id)
);

create table if not exists public.hardware_components (
  id uuid primary key default gen_random_uuid(),
  window_id uuid not null references public.windows (id) on delete cascade,
  component_group text not null,
  component_name text not null,
  evaluation text,
  notes text,
  data jsonb not null default '{}'::jsonb,
  created_at timestamptz not null default timezone('utc', now()),
  updated_at timestamptz not null default timezone('utc', now()),
  deleted_at timestamptz
);

create table if not exists public.functional_tests (
  id uuid primary key default gen_random_uuid(),
  window_id uuid not null references public.windows (id) on delete cascade,
  data jsonb not null default '{}'::jsonb,
  created_at timestamptz not null default timezone('utc', now()),
  updated_at timestamptz not null default timezone('utc', now()),
  deleted_at timestamptz,
  unique (window_id)
);

create table if not exists public.findings (
  id uuid primary key default gen_random_uuid(),
  window_id uuid not null references public.windows (id) on delete cascade,
  category text not null,
  severity text,
  description text not null,
  data jsonb not null default '{}'::jsonb,
  created_by uuid references public.profiles (id),
  created_at timestamptz not null default timezone('utc', now()),
  updated_at timestamptz not null default timezone('utc', now()),
  deleted_at timestamptz
);

create table if not exists public.recommendations (
  id uuid primary key default gen_random_uuid(),
  window_id uuid not null references public.windows (id) on delete cascade,
  recommendation_type text not null,
  priority text,
  due_hint text,
  description text not null,
  data jsonb not null default '{}'::jsonb,
  created_by uuid references public.profiles (id),
  created_at timestamptz not null default timezone('utc', now()),
  updated_at timestamptz not null default timezone('utc', now()),
  deleted_at timestamptz
);

create table if not exists public.photos (
  id uuid primary key default gen_random_uuid(),
  window_id uuid not null references public.windows (id) on delete cascade,
  category text not null,
  caption text,
  file_name text not null,
  storage_path text not null unique,
  inspector_id uuid references public.profiles (id),
  inspector_name text,
  taken_at timestamptz,
  metadata jsonb not null default '{}'::jsonb,
  created_at timestamptz not null default timezone('utc', now()),
  updated_at timestamptz not null default timezone('utc', now()),
  deleted_at timestamptz
);

create table if not exists public.record_locks (
  id uuid primary key default gen_random_uuid(),
  window_id uuid not null references public.windows (id) on delete cascade,
  owner_id uuid not null references public.profiles (id) on delete cascade,
  owner_name text,
  reason text,
  created_at timestamptz not null default timezone('utc', now()),
  updated_at timestamptz not null default timezone('utc', now()),
  expires_at timestamptz not null,
  released_at timestamptz,
  released_by uuid references public.profiles (id),
  release_note text,
  unique (window_id)
);

create table if not exists public.audit_logs (
  id uuid primary key default gen_random_uuid(),
  project_id uuid references public.projects (id) on delete cascade,
  window_id uuid references public.windows (id) on delete cascade,
  actor_id uuid references public.profiles (id),
  actor_name text,
  action_type text not null,
  field_name text,
  old_value text,
  new_value text,
  reason text,
  details jsonb not null default '{}'::jsonb,
  created_at timestamptz not null default timezone('utc', now())
);

create table if not exists public.calculation_parameters (
  id uuid primary key default gen_random_uuid(),
  project_id uuid references public.projects (id) on delete cascade,
  parameter_key text not null,
  parameter_value numeric(12,4) not null,
  description text,
  updated_by uuid references public.profiles (id),
  created_at timestamptz not null default timezone('utc', now()),
  updated_at timestamptz not null default timezone('utc', now()),
  unique (project_id, parameter_key)
);

create table if not exists public.export_logs (
  id uuid primary key default gen_random_uuid(),
  project_id uuid not null references public.projects (id) on delete cascade,
  exported_by uuid references public.profiles (id),
  export_type text not null,
  file_name text,
  filter_snapshot jsonb not null default '{}'::jsonb,
  created_at timestamptz not null default timezone('utc', now())
);

create index if not exists idx_windows_project_status on public.windows (project_id, status) where deleted_at is null;
create index if not exists idx_windows_room on public.windows (room_number) where deleted_at is null;
create index if not exists idx_windows_assigned_to on public.windows (assigned_to) where deleted_at is null;
create index if not exists idx_windows_updated_at on public.windows (updated_at desc) where deleted_at is null;
create index if not exists idx_windows_form_data on public.windows using gin (form_data);
create index if not exists idx_record_locks_expires_at on public.record_locks (expires_at);
create index if not exists idx_audit_logs_window on public.audit_logs (window_id, created_at desc);
create index if not exists idx_photos_window on public.photos (window_id, created_at desc) where deleted_at is null;
create index if not exists idx_findings_window on public.findings (window_id) where deleted_at is null;
create index if not exists idx_recommendations_window on public.recommendations (window_id) where deleted_at is null;

create or replace function public.set_updated_at()
returns trigger
language plpgsql
as $$
begin
  new.updated_at = timezone('utc', now());
  return new;
end;
$$;

create or replace function public.log_window_changes()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
declare
  actor_name text;
  changed_key text;
  old_val text;
  new_val text;
begin
  select full_name into actor_name from public.profiles where id = auth.uid();

  if tg_op = 'INSERT' then
    insert into public.audit_logs (project_id, window_id, actor_id, actor_name, action_type, field_name, new_value, details)
    values (new.project_id, new.id, auth.uid(), actor_name, 'create', 'record', new.record_id, jsonb_build_object('status', new.status));
    return new;
  end if;

  if tg_op = 'UPDATE' then
    if old.status is distinct from new.status then
      insert into public.audit_logs (project_id, window_id, actor_id, actor_name, action_type, field_name, old_value, new_value)
      values (new.project_id, new.id, auth.uid(), actor_name, 'status_change', 'status', old.status, new.status);
    end if;

    for changed_key in
      select key from jsonb_object_keys(coalesce(new.form_data, '{}'::jsonb)) as key
      union
      select key from jsonb_object_keys(coalesce(old.form_data, '{}'::jsonb)) as key
    loop
      old_val := old.form_data ->> changed_key;
      new_val := new.form_data ->> changed_key;
      if old_val is distinct from new_val then
        insert into public.audit_logs (project_id, window_id, actor_id, actor_name, action_type, field_name, old_value, new_value)
        values (
          new.project_id,
          new.id,
          auth.uid(),
          actor_name,
          case when changed_key = 'manual_override_reason' or changed_key = 'manual_weight_override' then 'manual_calculation_override' else 'field_update' end,
          changed_key,
          old_val,
          new_val
        );
      end if;
    end loop;

    return new;
  end if;

  return coalesce(new, old);
end;
$$;

create or replace function public.acquire_record_lock(p_window_id uuid, p_timeout_minutes integer default 15)
returns table(ok boolean, lock_id uuid, owner_id uuid, owner_name text, expires_at timestamptz, message text)
language plpgsql
security definer
set search_path = public
as $$
declare
  v_existing public.record_locks%rowtype;
  v_profile public.profiles%rowtype;
  v_expires timestamptz := timezone('utc', now()) + make_interval(mins => greatest(coalesce(p_timeout_minutes, 15), 1));
begin
  if auth.uid() is null then
    return query select false, null::uuid, null::uuid, null::text, null::timestamptz, 'Nicht angemeldet';
    return;
  end if;

  select * into v_profile from public.profiles where id = auth.uid();
  delete from public.record_locks where expires_at < timezone('utc', now()) or released_at is not null;
  select * into v_existing from public.record_locks where window_id = p_window_id;

  if found and v_existing.owner_id <> auth.uid() and v_existing.expires_at > timezone('utc', now()) then
    return query select false, v_existing.id, v_existing.owner_id, v_existing.owner_name, v_existing.expires_at, 'Datensatz ist derzeit gesperrt';
    return;
  end if;

  insert into public.record_locks (window_id, owner_id, owner_name, expires_at)
  values (p_window_id, auth.uid(), coalesce(v_profile.full_name, v_profile.email), v_expires)
  on conflict (window_id) do update
    set owner_id = excluded.owner_id,
        owner_name = excluded.owner_name,
        expires_at = excluded.expires_at,
        updated_at = timezone('utc', now()),
        released_at = null,
        released_by = null,
        release_note = null
  returning id, owner_id, owner_name, expires_at into lock_id, owner_id, owner_name, expires_at;

  ok := true;
  message := 'Sperre aktiv';
  return next;
end;
$$;

create or replace function public.release_record_lock(p_window_id uuid)
returns boolean
language plpgsql
security definer
set search_path = public
as $$
declare
  v_role text;
begin
  select role into v_role from public.profiles where id = auth.uid();
  update public.record_locks
    set released_at = timezone('utc', now()),
        released_by = auth.uid(),
        expires_at = timezone('utc', now())
  where window_id = p_window_id
    and (owner_id = auth.uid() or v_role = 'administrator');
  return found;
end;
$$;

create or replace function public.is_admin()
returns boolean
language sql
stable
as $$
  select exists (
    select 1 from public.profiles where id = auth.uid() and role = 'administrator' and is_active = true
  );
$$;

create or replace function public.is_project_member()
returns boolean
language sql
stable
as $$
  select exists (
    select 1 from public.profiles where id = auth.uid() and is_active = true
  );
$$;

drop trigger if exists trg_profiles_updated_at on public.profiles;
create trigger trg_profiles_updated_at before update on public.profiles for each row execute function public.set_updated_at();
drop trigger if exists trg_projects_updated_at on public.projects;
create trigger trg_projects_updated_at before update on public.projects for each row execute function public.set_updated_at();
drop trigger if exists trg_buildings_updated_at on public.buildings;
create trigger trg_buildings_updated_at before update on public.buildings for each row execute function public.set_updated_at();
drop trigger if exists trg_building_sections_updated_at on public.building_sections;
create trigger trg_building_sections_updated_at before update on public.building_sections for each row execute function public.set_updated_at();
drop trigger if exists trg_floors_updated_at on public.floors;
create trigger trg_floors_updated_at before update on public.floors for each row execute function public.set_updated_at();
drop trigger if exists trg_rooms_updated_at on public.rooms;
create trigger trg_rooms_updated_at before update on public.rooms for each row execute function public.set_updated_at();
drop trigger if exists trg_windows_updated_at on public.windows;
create trigger trg_windows_updated_at before update on public.windows for each row execute function public.set_updated_at();
drop trigger if exists trg_window_wings_updated_at on public.window_wings;
create trigger trg_window_wings_updated_at before update on public.window_wings for each row execute function public.set_updated_at();
drop trigger if exists trg_glazing_data_updated_at on public.glazing_data;
create trigger trg_glazing_data_updated_at before update on public.glazing_data for each row execute function public.set_updated_at();
drop trigger if exists trg_hardware_components_updated_at on public.hardware_components;
create trigger trg_hardware_components_updated_at before update on public.hardware_components for each row execute function public.set_updated_at();
drop trigger if exists trg_functional_tests_updated_at on public.functional_tests;
create trigger trg_functional_tests_updated_at before update on public.functional_tests for each row execute function public.set_updated_at();
drop trigger if exists trg_findings_updated_at on public.findings;
create trigger trg_findings_updated_at before update on public.findings for each row execute function public.set_updated_at();
drop trigger if exists trg_recommendations_updated_at on public.recommendations;
create trigger trg_recommendations_updated_at before update on public.recommendations for each row execute function public.set_updated_at();
drop trigger if exists trg_photos_updated_at on public.photos;
create trigger trg_photos_updated_at before update on public.photos for each row execute function public.set_updated_at();
drop trigger if exists trg_record_locks_updated_at on public.record_locks;
create trigger trg_record_locks_updated_at before update on public.record_locks for each row execute function public.set_updated_at();
drop trigger if exists trg_calculation_parameters_updated_at on public.calculation_parameters;
create trigger trg_calculation_parameters_updated_at before update on public.calculation_parameters for each row execute function public.set_updated_at();

drop trigger if exists trg_windows_audit on public.windows;
create trigger trg_windows_audit after insert or update on public.windows for each row execute function public.log_window_changes();

alter table public.profiles enable row level security;
alter table public.projects enable row level security;
alter table public.buildings enable row level security;
alter table public.building_sections enable row level security;
alter table public.floors enable row level security;
alter table public.rooms enable row level security;
alter table public.windows enable row level security;
alter table public.window_wings enable row level security;
alter table public.glazing_data enable row level security;
alter table public.hardware_components enable row level security;
alter table public.functional_tests enable row level security;
alter table public.findings enable row level security;
alter table public.recommendations enable row level security;
alter table public.photos enable row level security;
alter table public.record_locks enable row level security;
alter table public.audit_logs enable row level security;
alter table public.calculation_parameters enable row level security;
alter table public.export_logs enable row level security;

drop policy if exists profiles_read on public.profiles;
create policy profiles_read on public.profiles for select using (public.is_project_member());
drop policy if exists profiles_self_update on public.profiles;
create policy profiles_self_update on public.profiles for update using (auth.uid() = id or public.is_admin()) with check (auth.uid() = id or public.is_admin());

drop policy if exists projects_read on public.projects;
create policy projects_read on public.projects for select using (public.is_project_member());
drop policy if exists projects_admin_write on public.projects;
create policy projects_admin_write on public.projects for all using (public.is_admin()) with check (public.is_admin());

drop policy if exists buildings_read on public.buildings;
create policy buildings_read on public.buildings for select using (public.is_project_member());
drop policy if exists buildings_admin_write on public.buildings;
create policy buildings_admin_write on public.buildings for all using (public.is_admin()) with check (public.is_admin());

drop policy if exists building_sections_read on public.building_sections;
create policy building_sections_read on public.building_sections for select using (public.is_project_member());
drop policy if exists building_sections_admin_write on public.building_sections;
create policy building_sections_admin_write on public.building_sections for all using (public.is_admin()) with check (public.is_admin());

drop policy if exists floors_read on public.floors;
create policy floors_read on public.floors for select using (public.is_project_member());
drop policy if exists floors_admin_write on public.floors;
create policy floors_admin_write on public.floors for all using (public.is_admin()) with check (public.is_admin());

drop policy if exists rooms_read on public.rooms;
create policy rooms_read on public.rooms for select using (public.is_project_member());
drop policy if exists rooms_admin_write on public.rooms;
create policy rooms_admin_write on public.rooms for all using (public.is_admin()) with check (public.is_admin());

drop policy if exists windows_read on public.windows;
create policy windows_read on public.windows for select using (public.is_project_member());
drop policy if exists windows_insert on public.windows;
create policy windows_insert on public.windows for insert with check (public.is_project_member());
drop policy if exists windows_update on public.windows;
create policy windows_update on public.windows for update using (
  public.is_admin()
  or exists (
    select 1 from public.profiles where id = auth.uid() and role = 'pruefer' and is_active = true
  )
  or exists (
    select 1 from public.profiles where id = auth.uid() and role = 'auswertung' and is_active = true
  )
) with check (
  public.is_admin()
  or (
    exists (select 1 from public.profiles where id = auth.uid() and role = 'pruefer' and is_active = true)
    and coalesce(released_at is null, true)
  )
);

drop policy if exists windows_delete_admin on public.windows;
create policy windows_delete_admin on public.windows for delete using (public.is_admin());

create policy window_wings_all on public.window_wings for all using (public.is_project_member()) with check (public.is_project_member());
create policy glazing_data_all on public.glazing_data for all using (public.is_project_member()) with check (public.is_project_member());
create policy hardware_components_all on public.hardware_components for all using (public.is_project_member()) with check (public.is_project_member());
create policy functional_tests_all on public.functional_tests for all using (public.is_project_member()) with check (public.is_project_member());
create policy findings_all on public.findings for all using (public.is_project_member()) with check (public.is_project_member());
create policy recommendations_all on public.recommendations for all using (public.is_project_member()) with check (public.is_project_member());
create policy photos_all on public.photos for all using (public.is_project_member()) with check (public.is_project_member());
create policy record_locks_all on public.record_locks for all using (public.is_project_member()) with check (public.is_project_member());
create policy audit_logs_read on public.audit_logs for select using (public.is_project_member());
create policy audit_logs_insert on public.audit_logs for insert with check (public.is_project_member());
create policy calculation_parameters_read on public.calculation_parameters for select using (public.is_project_member());
create policy calculation_parameters_admin_write on public.calculation_parameters for all using (public.is_admin()) with check (public.is_admin());
create policy export_logs_all on public.export_logs for all using (public.is_project_member()) with check (public.is_project_member());

do $$
begin
  begin
    alter publication supabase_realtime add table public.windows;
  exception when duplicate_object then null;
  end;
  begin
    alter publication supabase_realtime add table public.record_locks;
  exception when duplicate_object then null;
  end;
  begin
    alter publication supabase_realtime add table public.audit_logs;
  exception when duplicate_object then null;
  end;
  begin
    alter publication supabase_realtime add table public.photos;
  exception when duplicate_object then null;
  end;
end $$;
