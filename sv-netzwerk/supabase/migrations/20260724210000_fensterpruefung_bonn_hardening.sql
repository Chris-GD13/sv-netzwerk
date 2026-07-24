-- Security and data-integrity hardening for the Bonn window inspection portal.
-- Run after 20260723220000_fensterpruefung_bonn.sql and before the seed.

begin;

-- Avoid RLS recursion and centralise active-role checks.
create or replace function public.current_user_role()
returns text
language sql
stable
security definer
set search_path = public
as $$
  select role
  from public.profiles
  where id = auth.uid()
    and is_active = true;
$$;

create or replace function public.is_admin()
returns boolean
language sql
stable
security definer
set search_path = public
as $$
  select coalesce(public.current_user_role() = 'administrator', false);
$$;

create or replace function public.is_project_member()
returns boolean
language sql
stable
security definer
set search_path = public
as $$
  select public.current_user_role() in ('administrator', 'pruefer', 'auswertung');
$$;

revoke all on function public.current_user_role() from public;
revoke all on function public.is_admin() from public;
revoke all on function public.is_project_member() from public;
grant execute on function public.current_user_role() to authenticated;
grant execute on function public.is_admin() to authenticated;
grant execute on function public.is_project_member() to authenticated;

-- Prevent users from changing security-sensitive profile fields themselves.
create or replace function public.protect_profile_security_fields()
returns trigger
language plpgsql
security definer
set search_path = public
as $$
begin
  if auth.uid() is not null and not public.is_admin() then
    if new.id is distinct from old.id
       or new.role is distinct from old.role
       or new.is_active is distinct from old.is_active
       or new.created_at is distinct from old.created_at then
      raise exception 'Rolle, Aktivstatus und Identitaet duerfen nur administrativ geaendert werden';
    end if;
  end if;
  return new;
end;
$$;

drop trigger if exists trg_profiles_protect_security_fields on public.profiles;
create trigger trg_profiles_protect_security_fields
before update on public.profiles
for each row execute function public.protect_profile_security_fields();

-- Keep self-service profile updates, but enforce the trigger above.
drop policy if exists profiles_self_update on public.profiles;
create policy profiles_self_update
on public.profiles
for update
to authenticated
using (auth.uid() = id or public.is_admin())
with check (auth.uid() = id or public.is_admin());

-- Evaluation users are read-only. Inspectors may only edit unreleased records.
drop policy if exists windows_insert on public.windows;
create policy windows_insert
on public.windows
for insert
to authenticated
with check (public.current_user_role() in ('administrator', 'pruefer'));

drop policy if exists windows_update on public.windows;
create policy windows_update
on public.windows
for update
to authenticated
using (
  public.is_admin()
  or (
    public.current_user_role() = 'pruefer'
    and released_at is null
  )
)
with check (
  public.is_admin()
  or (
    public.current_user_role() = 'pruefer'
    and released_at is null
  )
);

-- Increment the optimistic-lock version on every material window update.
create or replace function public.bump_window_version()
returns trigger
language plpgsql
as $$
begin
  new.version := old.version + 1;
  return new;
end;
$$;

drop trigger if exists trg_windows_bump_version on public.windows;
create trigger trg_windows_bump_version
before update on public.windows
for each row execute function public.bump_window_version();

-- Lock rows may be read directly, but writes are only performed through RPCs.
drop policy if exists record_locks_all on public.record_locks;
drop policy if exists record_locks_read on public.record_locks;
create policy record_locks_read
on public.record_locks
for select
to authenticated
using (public.is_project_member());

revoke insert, update, delete on public.record_locks from anon, authenticated;
grant select on public.record_locks to authenticated;

-- Harden lock acquisition: active member, valid window, no lock takeover.
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
  if auth.uid() is null or not public.is_project_member() then
    return query select false, null::uuid, null::uuid, null::text, null::timestamptz, 'Nicht berechtigt';
    return;
  end if;

  if not exists (select 1 from public.windows where id = p_window_id and deleted_at is null) then
    return query select false, null::uuid, null::uuid, null::text, null::timestamptz, 'Datensatz nicht gefunden';
    return;
  end if;

  select * into v_profile from public.profiles where id = auth.uid() and is_active = true;

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

create or replace function public.release_record_lock(p_window_id uuid)
returns boolean
language plpgsql
security definer
set search_path = public
as $$
begin
  if auth.uid() is null or not public.is_project_member() then
    return false;
  end if;

  update public.record_locks
     set released_at = timezone('utc', now()),
         released_by = auth.uid(),
         expires_at = timezone('utc', now())
   where window_id = p_window_id
     and (owner_id = auth.uid() or public.is_admin());

  return found;
end;
$$;

revoke all on function public.acquire_record_lock(uuid, integer) from public;
revoke all on function public.release_record_lock(uuid) from public;
grant execute on function public.acquire_record_lock(uuid, integer) to authenticated;
grant execute on function public.release_record_lock(uuid) to authenticated;

-- Audit entries are immutable to clients and may only be produced by trusted code.
drop policy if exists audit_logs_insert on public.audit_logs;
drop policy if exists audit_logs_update on public.audit_logs;
drop policy if exists audit_logs_delete on public.audit_logs;
revoke insert, update, delete on public.audit_logs from anon, authenticated;
grant select on public.audit_logs to authenticated;

-- Make global and project-specific calculation parameters unique independently.
alter table public.calculation_parameters
  drop constraint if exists calculation_parameters_project_id_parameter_key_key;

create unique index if not exists ux_calculation_parameters_global_key
  on public.calculation_parameters (parameter_key)
  where project_id is null;

create unique index if not exists ux_calculation_parameters_project_key
  on public.calculation_parameters (project_id, parameter_key)
  where project_id is not null;

-- Additional indexes used by dashboards, releases and exports.
create index if not exists idx_windows_project_assigned
  on public.windows (project_id, assigned_to)
  where deleted_at is null;
create index if not exists idx_windows_project_released
  on public.windows (project_id, released_at)
  where deleted_at is null;
create index if not exists idx_windows_project_created
  on public.windows (project_id, created_at desc)
  where deleted_at is null;
create index if not exists idx_audit_logs_project_created
  on public.audit_logs (project_id, created_at desc);
create index if not exists idx_export_logs_project_created
  on public.export_logs (project_id, created_at desc);

-- Private photo bucket and conservative object policies.
insert into storage.buckets (id, name, public)
values ('window-photos-private', 'window-photos-private', false)
on conflict (id) do update set public = false;

alter table storage.objects enable row level security;

drop policy if exists window_photos_read on storage.objects;
create policy window_photos_read
on storage.objects
for select
to authenticated
using (
  bucket_id = 'window-photos-private'
  and public.is_project_member()
);

drop policy if exists window_photos_insert on storage.objects;
create policy window_photos_insert
on storage.objects
for insert
to authenticated
with check (
  bucket_id = 'window-photos-private'
  and public.current_user_role() in ('administrator', 'pruefer')
  and owner_id = auth.uid()
);

drop policy if exists window_photos_update_own on storage.objects;
create policy window_photos_update_own
on storage.objects
for update
to authenticated
using (
  bucket_id = 'window-photos-private'
  and (owner_id = auth.uid() or public.is_admin())
)
with check (
  bucket_id = 'window-photos-private'
  and (owner_id = auth.uid() or public.is_admin())
);

drop policy if exists window_photos_delete_admin on storage.objects;
create policy window_photos_delete_admin
on storage.objects
for delete
to authenticated
using (
  bucket_id = 'window-photos-private'
  and public.is_admin()
);

commit;
