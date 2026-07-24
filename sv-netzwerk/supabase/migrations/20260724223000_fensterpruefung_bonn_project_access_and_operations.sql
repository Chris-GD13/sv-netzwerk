-- Project-scoped authorization and operational hardening for the Bonn window inspection portal.
-- Run after 20260724210000_fensterpruefung_bonn_hardening.sql.

begin;

-- Explicit project memberships. Existing active users are backfilled to existing
-- active projects to preserve access during the transition. Future assignments
-- must be managed explicitly.
create table if not exists public.project_members (
  project_id uuid not null references public.projects (id) on delete cascade,
  user_id uuid not null references public.profiles (id) on delete cascade,
  project_role text not null check (project_role in ('administrator', 'pruefer', 'auswertung')),
  is_active boolean not null default true,
  created_by uuid references public.profiles (id),
  created_at timestamptz not null default timezone('utc', now()),
  updated_at timestamptz not null default timezone('utc', now()),
  primary key (project_id, user_id)
);

create index if not exists idx_project_members_user_active
  on public.project_members (user_id, is_active, project_id);

insert into public.project_members (project_id, user_id, project_role)
select p.id, u.id, u.role
from public.projects p
cross join public.profiles u
where p.is_active = true
  and u.is_active = true
on conflict (project_id, user_id) do nothing;

create or replace function public.has_project_access(p_project_id uuid)
returns boolean
language sql
stable
security definer
set search_path = public
as $$
  select coalesce(
    public.is_admin()
    or exists (
      select 1
      from public.project_members pm
      join public.profiles pr on pr.id = pm.user_id
      where pm.project_id = p_project_id
        and pm.user_id = auth.uid()
        and pm.is_active = true
        and pr.is_active = true
    ),
    false
  );
$$;

create or replace function public.has_project_write_access(p_project_id uuid)
returns boolean
language sql
stable
security definer
set search_path = public
as $$
  select coalesce(
    public.is_admin()
    or exists (
      select 1
      from public.project_members pm
      join public.profiles pr on pr.id = pm.user_id
      where pm.project_id = p_project_id
        and pm.user_id = auth.uid()
        and pm.is_active = true
        and pr.is_active = true
        and pm.project_role = 'pruefer'
    ),
    false
  );
$$;

revoke all on function public.has_project_access(uuid) from public;
revoke all on function public.has_project_write_access(uuid) from public;
grant execute on function public.has_project_access(uuid) to authenticated;
grant execute on function public.has_project_write_access(uuid) to authenticated;

alter table public.project_members enable row level security;

drop policy if exists project_members_read on public.project_members;
create policy project_members_read
on public.project_members
for select
to authenticated
using (user_id = auth.uid() or public.is_admin());

drop policy if exists project_members_admin_write on public.project_members;
create policy project_members_admin_write
on public.project_members
for all
to authenticated
using (public.is_admin())
with check (public.is_admin());

-- Project-scoped project and window access.
drop policy if exists projects_read on public.projects;
create policy projects_read
on public.projects
for select
to authenticated
using (public.has_project_access(id));

drop policy if exists windows_read on public.windows;
create policy windows_read
on public.windows
for select
to authenticated
using (public.has_project_access(project_id));

drop policy if exists windows_insert on public.windows;
create policy windows_insert
on public.windows
for insert
to authenticated
with check (public.has_project_write_access(project_id));

drop policy if exists windows_update on public.windows;
create policy windows_update
on public.windows
for update
to authenticated
using (
  public.is_admin()
  or (
    public.has_project_write_access(project_id)
    and released_at is null
  )
)
with check (
  public.is_admin()
  or (
    public.has_project_write_access(project_id)
    and released_at is null
  )
);

-- Photo integrity metadata.
alter table public.photos
  add column if not exists sha256 text,
  add column if not exists mime_type text,
  add column if not exists file_size_bytes bigint,
  add column if not exists width_px integer,
  add column if not exists height_px integer;

create index if not exists idx_photos_sha256
  on public.photos (sha256)
  where deleted_at is null and sha256 is not null;

-- Lock heartbeat support. Locks are renewed through an RPC instead of being
-- recreated, reducing races between active editors.
alter table public.record_locks
  add column if not exists heartbeat_at timestamptz not null default timezone('utc', now());

create index if not exists idx_record_locks_active_heartbeat
  on public.record_locks (window_id, heartbeat_at, expires_at)
  where released_at is null;

create or replace function public.heartbeat_record_lock(p_window_id uuid, p_timeout_minutes integer default 15)
returns table(ok boolean, expires_at timestamptz, message text)
language plpgsql
security definer
set search_path = public
as $$
declare
  v_project_id uuid;
begin
  select project_id into v_project_id
  from public.windows
  where id = p_window_id
    and deleted_at is null;

  if v_project_id is null or not public.has_project_write_access(v_project_id) then
    return query select false, null::timestamptz, 'Nicht berechtigt';
    return;
  end if;

  update public.record_locks
     set heartbeat_at = timezone('utc', now()),
         expires_at = timezone('utc', now()) + make_interval(mins => greatest(coalesce(p_timeout_minutes, 15), 1)),
         updated_at = timezone('utc', now())
   where window_id = p_window_id
     and owner_id = auth.uid()
     and released_at is null
     and expires_at > timezone('utc', now())
  returning public.record_locks.expires_at into expires_at;

  if expires_at is null then
    return query select false, null::timestamptz, 'Keine aktive eigene Sperre';
    return;
  end if;

  ok := true;
  message := 'Sperre verlaengert';
  return next;
end;
$$;

revoke all on function public.heartbeat_record_lock(uuid, integer) from public;
grant execute on function public.heartbeat_record_lock(uuid, integer) to authenticated;

-- Ensure lock acquisition is limited to users with write access to the
-- associated project and initialise the heartbeat timestamp.
create or replace function public.acquire_record_lock(p_window_id uuid, p_timeout_minutes integer default 15)
returns table(ok boolean, lock_id uuid, owner_id uuid, owner_name text, expires_at timestamptz, message text)
language plpgsql
security definer
set search_path = public
as $$
declare
  v_existing public.record_locks%rowtype;
  v_profile public.profiles%rowtype;
  v_project_id uuid;
  v_expires timestamptz := timezone('utc', now()) + make_interval(mins => greatest(coalesce(p_timeout_minutes, 15), 1));
begin
  select project_id into v_project_id
  from public.windows
  where id = p_window_id
    and deleted_at is null;

  if auth.uid() is null or v_project_id is null or not public.has_project_write_access(v_project_id) then
    return query select false, null::uuid, null::uuid, null::text, null::timestamptz, 'Nicht berechtigt';
    return;
  end if;

  select * into v_profile
  from public.profiles
  where id = auth.uid()
    and is_active = true;

  delete from public.record_locks
  where expires_at < timezone('utc', now()) or released_at is not null;

  select * into v_existing
  from public.record_locks
  where window_id = p_window_id
  for update;

  if found and v_existing.owner_id <> auth.uid() and v_existing.expires_at > timezone('utc', now()) then
    return query select false, v_existing.id, v_existing.owner_id, v_existing.owner_name, v_existing.expires_at, 'Datensatz ist derzeit gesperrt';
    return;
  end if;

  insert into public.record_locks (window_id, owner_id, owner_name, heartbeat_at, expires_at)
  values (p_window_id, auth.uid(), coalesce(v_profile.full_name, v_profile.email), timezone('utc', now()), v_expires)
  on conflict (window_id) do update
    set owner_id = excluded.owner_id,
        owner_name = excluded.owner_name,
        heartbeat_at = excluded.heartbeat_at,
        expires_at = excluded.expires_at,
        updated_at = timezone('utc', now()),
        released_at = null,
        released_by = null,
        release_note = null
    where public.record_locks.owner_id = auth.uid()
       or public.record_locks.expires_at <= timezone('utc', now())
       or public.record_locks.released_at is not null
  returning public.record_locks.id,
            public.record_locks.owner_id,
            public.record_locks.owner_name,
            public.record_locks.expires_at
  into lock_id, owner_id, owner_name, expires_at;

  if lock_id is null then
    return query select false, v_existing.id, v_existing.owner_id, v_existing.owner_name, v_existing.expires_at, 'Datensatz ist derzeit gesperrt';
    return;
  end if;

  ok := true;
  message := 'Sperre aktiv';
  return next;
end;
$$;

-- Additional audit context fields. Trusted application code may populate these;
-- direct client writes remain revoked by the previous hardening migration.
alter table public.audit_logs
  add column if not exists ip_address inet,
  add column if not exists user_agent text,
  add column if not exists client_version text;

-- Stable export surface. Security invoker keeps the calling user's RLS context.
drop view if exists public.export_windows;
create view public.export_windows
with (security_invoker = true)
as
select
  w.id,
  w.project_id,
  p.project_code,
  p.title as project_title,
  w.record_id,
  w.inspection_number,
  w.window_number,
  w.object_label,
  w.building_label,
  w.section_label,
  w.floor_label,
  w.room_label,
  w.room_number,
  w.status,
  w.overall_rating,
  w.priority,
  w.special_inspection_required,
  w.urgent_action_required,
  w.has_defect,
  w.danger_immediate,
  w.progress_percent,
  w.assigned_to,
  w.assigned_name,
  w.last_edited_at,
  w.completed_at,
  w.released_at,
  w.release_reason,
  w.version,
  w.created_at,
  w.updated_at
from public.windows w
join public.projects p on p.id = w.project_id
where w.deleted_at is null;

grant select on public.export_windows to authenticated;

-- Dashboard and operational indexes.
create index if not exists idx_windows_project_status_updated
  on public.windows (project_id, status, updated_at desc)
  where deleted_at is null;

create index if not exists idx_windows_project_progress
  on public.windows (project_id, progress_percent)
  where deleted_at is null;

create index if not exists idx_windows_project_last_edited
  on public.windows (project_id, last_edited_at desc)
  where deleted_at is null;

create index if not exists idx_windows_released_at
  on public.windows (released_at desc)
  where deleted_at is null and released_at is not null;

commit;
