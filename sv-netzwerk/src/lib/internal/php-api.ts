/**
 * PHP-API-Client – SV-Netzwerk Prüfportal
 *
 * Ersetzt den Supabase-Client durch direkte fetch()-Aufrufe an die PHP-Backend-API.
 * Alle Endpunkte liegen unter /intern/api/*.php.
 */

import type {
  AdminUser,
  AuditLogEntry,
  Building,
  CalculationParameterMap,
  Floor,
  LockResult,
  PhotoItem,
  PortalRole,
  PortalUser,
  OnlinePortalUser,
  Room,
  WindowInRoom,
  WindowRecord,
  WindowSashRecord,
  WindowSashSummary,
  WindowSummary,
} from './types';
import { normalizeCalculationParameters } from './calculations';

const API_BASE = '/intern/api';

// ── Projekt-Kontext aus URL ableiten ────────────────────────────────────────

/** Dynamische Map von URL-Slug auf project_id (wird beim ersten Aufruf gefüllt). */
let projectSlugMap: Record<string, number> | null = null;

async function ensureProjectMap(): Promise<Record<string, number>> {
  if (projectSlugMap) return projectSlugMap;
  try {
    const { data } = await apiGet<{ projects: Array<{ id: number; project_code: string }> }>('/projects.php');
    projectSlugMap = {};
    data?.projects?.forEach(p => { projectSlugMap![p.project_code] = Number(p.id); });
  } catch {
    projectSlugMap = { 'fensterpruefung-bonn': 1, 'referenz-testprojekt': 2 };
  }
  return projectSlugMap;
}

/** Aktuelle project_id aus der URL ableiten (Default: 1). */
function getProjectId(): number {
  const slug = getProjectSlugRaw();
  if (projectSlugMap && projectSlugMap[slug] !== undefined) {
    return projectSlugMap[slug];
  }
  return 1;
}

/** Aktuelle project_id als Query-Parameter-String. */
function pidParam(prefix: '?' | '&' = '&'): string {
  const pid = getProjectId();
  return pid === 1 ? '' : `${prefix}project_id=${pid}`;
}

/** Rohwert des URL-Slugs extrahieren. */
function getProjectSlugRaw(): string {
  const path = window.location.pathname;
  const match = path.match(/\/intern\/([^/]+)\//);
  return match?.[1] ?? 'fensterpruefung-bonn';
}

/** Aktuellen Projekt-URL-Slug ermitteln. */
export function getProjectSlug(): string {
  return getProjectSlugRaw();
}

// ── HTTP-Hilfsfunktionen ────────────────────────────────────────────────────

async function apiFetch<T>(
  path: string,
  options: RequestInit = {},
): Promise<{ data: T | null; error: Error | null }> {
  try {
    const response = await fetch(`${API_BASE}${path}`, {
      ...options,
      credentials: 'same-origin',
      headers: {
        Accept: 'application/json',
        ...options.headers,
      },
    });
    const json = await response.json().catch(() => null);
    if (!response.ok) {
      return { data: null, error: new Error((json as { error?: string })?.error ?? `HTTP ${response.status}`) };
    }
    return { data: json as T, error: null };
  } catch (err) {
    return { data: null, error: err instanceof Error ? err : new Error('Netzwerkfehler') };
  }
}

async function apiGet<T>(path: string): Promise<{ data: T | null; error: Error | null }> {
  return apiFetch<T>(path, { method: 'GET' });
}

async function apiPost<T>(path: string, body: unknown): Promise<{ data: T | null; error: Error | null }> {
  return apiFetch<T>(path, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
}

async function apiPut<T>(path: string, body: unknown): Promise<{ data: T | null; error: Error | null }> {
  return apiFetch<T>(path, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
}

async function apiDelete<T>(path: string): Promise<{ data: T | null; error: Error | null }> {
  return apiFetch<T>(path, { method: 'DELETE' });
}

async function apiPatch<T>(path: string, body: unknown): Promise<{ data: T | null; error: Error | null }> {
  return apiFetch<T>(path, {
    method: 'PATCH',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
}

// ── Auth ────────────────────────────────────────────────────────────────────

export interface ApiSessionUser {
  id: number;
  email: string;
  full_name: string;
  role: PortalRole;
}

export async function apiLogin(
  email: string,
  password: string,
): Promise<{ user: ApiSessionUser | null; error: Error | null }> {
  const { data, error } = await apiPost<ApiSessionUser>('/auth.php?action=login', { email, password });
  return { user: data, error };
}

export async function apiLogout(): Promise<void> {
  await apiPost('/auth.php?action=logout', {});
}

export async function apiGetSession(): Promise<ApiSessionUser | null> {
  const { data } = await apiGet<{ user: ApiSessionUser | null }>('/auth.php?action=session');
  return data?.user ?? null;
}

export async function apiResetPassword(email: string): Promise<{ error: Error | null }> {
  const { error } = await apiPost('/auth.php?action=reset', { email });
  return { error };
}

/** Lädt das Benutzerprofil aus der Session und gibt einen PortalUser zurück. */
export async function loadApiUser(): Promise<PortalUser | null> {
  const sessionUser = await apiGetSession();
  if (!sessionUser) return null;
  await ensureProjectMap();
  return {
    id: String(sessionUser.id),
    email: sessionUser.email,
    profile: {
      id: String(sessionUser.id),
      email: sessionUser.email,
      full_name: sessionUser.full_name,
      role: sessionUser.role,
      is_active: true,
    },
  };
}

// ── Fenster-Datensätze ──────────────────────────────────────────────────────

export async function apiListWindows(): Promise<WindowSummary[]> {
  const { data } = await apiGet<WindowSummary[]>(`/windows.php?_=1${pidParam()}`);
  return data ?? [];
}

export async function apiGetWindow(id: string): Promise<WindowRecord | null> {
  const { data } = await apiGet<WindowRecord>(`/windows.php?id=${encodeURIComponent(id)}`);
  return data ?? null;
}

export async function apiCreateWindow(
  formData: Record<string, unknown>,
): Promise<{ id: string } | null> {
  const { data } = await apiPost<{ id: number; record_id: string }>('/windows.php', { form_data: formData });
  if (!data) return null;
  return { id: String(data.id) };
}

export async function apiSaveWindow(
  id: string,
  formData: Record<string, unknown>,
  calculatedData: Record<string, unknown>,
): Promise<{ error: Error | null }> {
  const { error } = await apiPut(`/windows.php?id=${encodeURIComponent(id)}`, {
    form_data: formData,
    calculated_data: calculatedData,
  });
  return { error };
}

// ── Sperren ─────────────────────────────────────────────────────────────────

export async function apiAcquireLock(windowId: string, timeoutMinutes = 15): Promise<LockResult | null> {
  const { data, error } = await apiPost<{
    ok: boolean;
    owner_id?: number;
    owner_name?: string;
    expires_at?: string;
    message?: string;
  }>(`/locks.php?action=acquire&id=${encodeURIComponent(windowId)}`, { timeout_minutes: timeoutMinutes });

  if (!data) return { ok: false, message: error?.message ?? 'Sperre konnte nicht gesetzt werden.' };
  return {
    ok: data.ok,
    owner_id: data.owner_id !== undefined ? String(data.owner_id) : null,
    owner_name: data.owner_name ?? null,
    expires_at: data.expires_at ?? null,
    message: data.message,
  };
}

export async function apiReleaseLock(windowId: string): Promise<void> {
  await apiDelete(`/locks.php?id=${encodeURIComponent(windowId)}`);
}

export async function apiGetActiveLocks(): Promise<
  Map<string, { owner_id: string | null; owner_name: string | null; expires_at: string | null }>
> {
  const result = new Map<string, { owner_id: string | null; owner_name: string | null; expires_at: string | null }>();
  const { data } = await apiGet<Array<{ window_id: number; owner_id: number; owner_name: string; expires_at: string }>>(
    `/windows.php?action=locks${pidParam()}`,
  );
  data?.forEach((item) => {
    result.set(String(item.window_id), {
      owner_id: String(item.owner_id),
      owner_name: item.owner_name,
      expires_at: item.expires_at,
    });
  });
  return result;
}

// ── Audit-Log ───────────────────────────────────────────────────────────────

export async function apiGetAuditLog(windowId: string): Promise<AuditLogEntry[]> {
  const { data } = await apiGet<AuditLogEntry[]>(
    `/windows.php?action=audit&id=${encodeURIComponent(windowId)}`,
  );
  return data ?? [];
}

// ── Fotos ───────────────────────────────────────────────────────────────────

export async function apiListPhotos(windowId: string): Promise<PhotoItem[]> {
  const { data } = await apiGet<PhotoItem[]>(`/photos.php?window_id=${encodeURIComponent(windowId)}`);
  return data ?? [];
}

export async function apiUploadPhoto(
  windowId: string,
  file: File,
  category: string,
  caption: string,
): Promise<boolean> {
  const formData = new FormData();
  formData.append('photo', file);
  formData.append('category', category);
  formData.append('caption', caption);

  const { error } = await apiFetch(
    `/photos.php?window_id=${encodeURIComponent(windowId)}`,
    { method: 'POST', body: formData },
  );
  return error === null;
}

export async function apiDeletePhoto(photoId: string): Promise<boolean> {
  const { error } = await apiDelete(`/photos.php?id=${encodeURIComponent(photoId)}`);
  return error === null;
}

// ── Berechnungsparameter ────────────────────────────────────────────────────

export async function apiGetCalculationParameters(): Promise<CalculationParameterMap> {
  const { data } = await apiGet<Partial<CalculationParameterMap>>('/parameters.php');
  return normalizeCalculationParameters(data);
}

// ── Export-Log ──────────────────────────────────────────────────────────────

export async function apiLogExport(exportType: string, fileName: string, filter: unknown): Promise<void> {
  await apiPost('/exports.php', { export_type: exportType, file_name: fileName, filter_snapshot: filter });
}

// ── Auth-Zustandspolling ────────────────────────────────────────────────────

type AuthCallback = (user: PortalUser | null) => void;
const authCallbacks = new Set<AuthCallback>();
let authPollInterval: number | null = null;
let lastKnownUserId: string | null = null;

/** Startet das Auth-Zustandspolling (alle 60 Sekunden). */
export function startAuthPolling(): void {
  if (authPollInterval !== null) return;
  authPollInterval = window.setInterval(async () => {
    const user = await loadApiUser();
    const currentId = user?.id ?? null;
    if (currentId !== lastKnownUserId) {
      lastKnownUserId = currentId;
      authCallbacks.forEach((cb) => cb(user));
    }
  }, 60_000);
}

/** Registriert einen Auth-Zustands-Listener und gibt eine Abmeldefunktion zurück. */
export function onAuthChange(callback: AuthCallback): () => void {
  authCallbacks.add(callback);
  startAuthPolling();
  return () => authCallbacks.delete(callback);
}

// ── Benutzerverwaltung (nur Administratoren) ────────────────────────────────

export async function apiListUsers(): Promise<AdminUser[]> {
  const { data } = await apiGet<AdminUser[]>('/users.php');
  return data ?? [];
}

export async function apiListOnlineUsers(): Promise<OnlinePortalUser[]> {
  const { data } = await apiGet<{ users: OnlinePortalUser[] }>('/users.php?action=presence');
  return data?.users ?? [];
}

export async function apiCreateUser(payload: {
  email: string;
  full_name: string;
  role: PortalRole;
  password: string;
}): Promise<{ data: AdminUser | null; error: Error | null }> {
  return apiPost<AdminUser>('/users.php', payload);
}

export async function apiUpdateUser(
  id: number,
  payload: { full_name: string; role: PortalRole; is_active: boolean },
): Promise<{ error: Error | null }> {
  const { error } = await apiPut(`/users.php?id=${id}`, payload);
  return { error };
}

export async function apiSetUserPassword(
  id: number,
  password: string,
): Promise<{ error: Error | null }> {
  const { error } = await apiPost(`/users.php?action=set_password&id=${id}`, { password });
  return { error };
}

export async function apiDeactivateUser(id: number): Promise<{ error: Error | null }> {
  const { error } = await apiDelete(`/users.php?id=${id}`);
  return { error };
}

export async function apiDeleteUserPermanent(id: number): Promise<{ error: Error | null }> {
  const { error } = await apiDelete(`/users.php?action=permanent&id=${id}`);
  return { error };
}

// ── Projekte ────────────────────────────────────────────────────────────────

export interface ProjectInfo {
  id: number;
  project_code: string;
  title: string;
  object_name: string;
  address: string;
  planned_window_count: number;
  window_count: number;
  building_count: number;
}

export async function apiListProjects(): Promise<ProjectInfo[]> {
  const { data } = await apiGet<{ projects: ProjectInfo[] }>('/projects.php');
  return data?.projects ?? [];
}

export async function apiCreateProject(title: string, objectName: string, address: string, windowCount: number): Promise<{ id: number; project_code: string } | null> {
  const { data } = await apiPost<{ id: number; project_code: string }>('/projects.php', { title, object_name: objectName, address, planned_window_count: windowCount });
  projectSlugMap = null; // invalidate cache
  return data;
}

export async function apiUpdateProject(id: number, title: string, objectName: string, address: string, windowCount: number): Promise<boolean> {
  const { data } = await apiPut<{ ok: boolean }>(`/projects.php?id=${id}`, { title, object_name: objectName, address, planned_window_count: windowCount });
  projectSlugMap = null;
  return !!data?.ok;
}

export async function apiDeleteProject(id: number, permanent = false): Promise<boolean> {
  const suffix = permanent ? '&action=permanent' : '';
  const { data } = await apiDelete<{ ok: boolean }>(`/projects.php?id=${id}${suffix}`);
  projectSlugMap = null;
  return !!data?.ok;
}

export async function apiDuplicateProject(id: number): Promise<{ id: number; project_code: string } | null> {
  const { data } = await apiPost<{ id: number; project_code: string }>(`/projects.php?action=duplicate&id=${id}`, {});
  projectSlugMap = null;
  return data;
}

export async function apiArchiveProject(id: number): Promise<boolean> {
  const { data } = await apiPatch<{ ok: boolean }>(`/projects.php?id=${id}`, { action: 'archive' });
  return !!data?.ok;
}

export async function apiCompleteProject(id: number): Promise<boolean> {
  const { data } = await apiPatch<{ ok: boolean }>(`/projects.php?id=${id}`, { action: 'complete' });
  return !!data?.ok;
}

export async function apiReopenProject(id: number): Promise<boolean> {
  const { data } = await apiPatch<{ ok: boolean }>(`/projects.php?id=${id}`, { action: 'reopen' });
  return !!data?.ok;
}

// ── Hierarchie ──────────────────────────────────────────────────────────────

export async function apiListBuildings(): Promise<Building[]> {
  const { data } = await apiGet<Building[]>(`/hierarchy.php?_=1${pidParam()}`);
  return data ?? [];
}

export async function apiListFloors(buildingId: number): Promise<Floor[]> {
  const { data } = await apiGet<Floor[]>(`/hierarchy.php?building_id=${buildingId}`);
  return data ?? [];
}

export async function apiListRooms(floorId: number): Promise<Room[]> {
  const { data } = await apiGet<Room[]>(`/hierarchy.php?floor_id=${floorId}`);
  return data ?? [];
}

export async function apiListWindowsInRoom(roomId: number): Promise<WindowInRoom[]> {
  const { data } = await apiGet<WindowInRoom[]>(`/hierarchy.php?room_id=${roomId}`);
  return data ?? [];
}

export async function apiCreateBuilding(name: string, code: string): Promise<{ id: number } | null> {
  const { data } = await apiPost<{ id: number }>(`/hierarchy.php?entity=building${pidParam()}`, { name, code });
  return data;
}

export async function apiUpdateBuilding(id: number, name: string, code: string): Promise<boolean> {
  const { error } = await apiPut(`/hierarchy.php?entity=building&id=${id}`, { name, code });
  return !error;
}

export async function apiDeleteBuilding(id: number): Promise<boolean> {
  const { error } = await apiDelete(`/hierarchy.php?entity=building&id=${id}`);
  return !error;
}

export async function apiCreateFloor(buildingId: number, name: string, level: number): Promise<{ id: number } | null> {
  const { data } = await apiPost<{ id: number }>('/hierarchy.php?entity=floor', { building_id: buildingId, name, level });
  return data;
}

export async function apiUpdateFloor(id: number, name: string, level: number): Promise<boolean> {
  const { error } = await apiPut(`/hierarchy.php?entity=floor&id=${id}`, { name, level });
  return !error;
}

export async function apiDeleteFloor(id: number): Promise<boolean> {
  const { error } = await apiDelete(`/hierarchy.php?entity=floor&id=${id}`);
  return !error;
}

export async function apiCreateRoom(floorId: number, name: string, roomNumber: string): Promise<{ id: number } | null> {
  const { data } = await apiPost<{ id: number }>('/hierarchy.php?entity=room', { floor_id: floorId, name, room_number: roomNumber });
  return data;
}

export async function apiUpdateRoom(id: number, name: string, roomNumber: string): Promise<boolean> {
  const { error } = await apiPut(`/hierarchy.php?entity=room&id=${id}`, { name, room_number: roomNumber });
  return !error;
}

export async function apiDeleteRoom(id: number): Promise<boolean> {
  const { error } = await apiDelete(`/hierarchy.php?entity=room&id=${id}`);
  return !error;
}

export async function apiDeleteWindow(id: number): Promise<boolean> {
  const { error } = await apiDelete(`/hierarchy.php?entity=window&id=${id}`);
  return !error;
}

export async function apiCreateWindowInRoom(roomId: number, windowNumber: string): Promise<{ id: number; record_id: string } | null> {
  const { data } = await apiPost<{ id: number; record_id: string }>(`/hierarchy.php?entity=window${pidParam()}`, { room_id: roomId, window_number: windowNumber });
  return data;
}

// ── Duplizieren / Archivieren / Verschieben ──────────────────────────────────

export async function apiDuplicateBuilding(id: number): Promise<{ id: number; name: string } | null> {
  const { data } = await apiPatch<{ id: number; name: string }>(`/hierarchy.php?entity=building&id=${id}${pidParam()}`, { action: 'duplicate' });
  return data;
}

export async function apiDuplicateFloor(id: number): Promise<{ id: number; name: string } | null> {
  const { data } = await apiPatch<{ id: number; name: string }>(`/hierarchy.php?entity=floor&id=${id}${pidParam()}`, { action: 'duplicate' });
  return data;
}

export async function apiDuplicateRoom(id: number): Promise<{ id: number; name: string } | null> {
  const { data } = await apiPatch<{ id: number; name: string }>(`/hierarchy.php?entity=room&id=${id}${pidParam()}`, { action: 'duplicate' });
  return data;
}

export async function apiArchiveBuilding(id: number): Promise<boolean> {
  const { error } = await apiPatch(`/hierarchy.php?entity=building&id=${id}${pidParam()}`, { action: 'archive' });
  return !error;
}

export async function apiArchiveFloor(id: number): Promise<boolean> {
  const { error } = await apiPatch(`/hierarchy.php?entity=floor&id=${id}${pidParam()}`, { action: 'archive' });
  return !error;
}

export async function apiArchiveRoom(id: number): Promise<boolean> {
  const { error } = await apiPatch(`/hierarchy.php?entity=room&id=${id}${pidParam()}`, { action: 'archive' });
  return !error;
}

export async function apiMoveFloor(id: number, targetBuildingId: number): Promise<boolean> {
  const { error } = await apiPatch(`/hierarchy.php?entity=floor&id=${id}${pidParam()}`, { action: 'move', target_id: targetBuildingId });
  return !error;
}

export async function apiMoveRoom(id: number, targetFloorId: number): Promise<boolean> {
  const { error } = await apiPatch(`/hierarchy.php?entity=room&id=${id}${pidParam()}`, { action: 'move', target_id: targetFloorId });
  return !error;
}

export async function apiMoveWindow(id: number, targetRoomId: number): Promise<boolean> {
  const { error } = await apiPatch(`/hierarchy.php?entity=window&id=${id}${pidParam()}`, { action: 'move', target_id: targetRoomId });
  return !error;
}

export async function apiCompleteEntity(entity: string, id: number): Promise<boolean> {
  const { error } = await apiPatch(`/hierarchy.php?entity=${entity}&id=${id}${pidParam()}`, { action: 'complete' });
  return !error;
}

export async function apiReopenEntity(entity: string, id: number): Promise<boolean> {
  const { error } = await apiPatch(`/hierarchy.php?entity=${entity}&id=${id}${pidParam()}`, { action: 'reopen' });
  return !error;
}

// ── KI-Dokumentenimport ─────────────────────────────────────────────────────

export interface AiAnalysisItem {
  type: 'building' | 'floor' | 'room' | 'window';
  status: 'new' | 'exists' | 'update' | 'conflict';
  data: Record<string, unknown>;
  confidence: number;
  change_description?: string;
}

export interface AiAnalysisResult {
  document_type: string;
  summary: string;
  items: AiAnalysisItem[];
}

export async function apiAiAnalyze(file: File): Promise<{ analysis: AiAnalysisResult; file_name: string } | null> {
  const formData = new FormData();
  formData.append('file', file);
  try {
    const response = await fetch(`${API_BASE}/ai-import.php?action=analyze`, {
      method: 'POST',
      credentials: 'same-origin',
      body: formData,
    });
    const json = await response.json().catch(() => null);
    if (!response.ok || !json?.ok) return null;
    return { analysis: json.analysis, file_name: json.file_name };
  } catch {
    return null;
  }
}

export async function apiAiApply(items: AiAnalysisItem[]): Promise<{ created: unknown[]; skipped: unknown[]; errors: unknown[]; summary: string } | null> {
  const { data } = await apiPost<{ created: unknown[]; skipped: unknown[]; errors: unknown[]; summary: string }>('/ai-import.php?action=apply', { items });
  return data;
}

// ── Flügel (Window Sashes) ──────────────────────────────────────────────────

export async function apiListSashes(windowId: number): Promise<WindowSashSummary[]> {
  const { data } = await apiGet<WindowSashSummary[]>(`/sashes.php?window_id=${windowId}`);
  return data ?? [];
}

export async function apiGetSash(id: number): Promise<WindowSashRecord | null> {
  const { data } = await apiGet<WindowSashRecord>(`/sashes.php?id=${id}`);
  return data ?? null;
}

export async function apiCreateSash(
  windowId: number,
  sashLabel: string,
  openingType: string,
  position: string,
): Promise<{ id: number; sash_number: number } | null> {
  const { data } = await apiPost<{ id: number; sash_number: number }>('/sashes.php', {
    window_id: windowId,
    sash_label: sashLabel,
    opening_type: openingType,
    position,
    form_data: { status: 'nicht begonnen', sash_label: sashLabel, opening_type: openingType, position },
  });
  return data;
}

export async function apiSaveSash(
  id: number,
  formData: Record<string, unknown>,
): Promise<{ error: Error | null }> {
  const { error } = await apiPut(`/sashes.php?id=${id}`, { form_data: formData });
  return { error };
}

export async function apiDeleteSash(id: number): Promise<{ error: Error | null }> {
  const { error } = await apiDelete(`/sashes.php?id=${id}`);
  return { error };
}

// ── Flügel-Fotos ─────────────────────────────────────────────────────────────

export async function apiListSashPhotos(sashId: number): Promise<PhotoItem[]> {
  const { data } = await apiGet<PhotoItem[]>(`/photos.php?sash_id=${sashId}`);
  return data ?? [];
}

export async function apiUploadSashPhoto(
  windowId: string,
  sashId: number,
  file: File,
  category: string,
  caption: string,
): Promise<boolean> {
  const formData = new FormData();
  formData.append('photo', file);
  formData.append('category', category);
  formData.append('caption', caption);
  formData.append('sash_id', String(sashId));

  const { error } = await apiFetch(
    `/photos.php?window_id=${encodeURIComponent(windowId)}&sash_id=${sashId}`,
    { method: 'POST', body: formData },
  );
  return error === null;
}

// ── Demo-Daten ───────────────────────────────────────────────────────────────

export async function apiGetDemoStatus(): Promise<{ demo_data_exists: boolean; building_count: number; sash_count: number; user_count: number }> {
  const { data } = await apiGet<{ demo_data_exists: boolean; building_count: number; sash_count: number; user_count: number }>('/demo.php');
  return data ?? { demo_data_exists: false, building_count: 0, sash_count: 0, user_count: 0 };
}

export async function apiSeedDemoData(reset = false): Promise<{ ok: boolean; message: string; results: string[] }> {
  const { data } = await apiPost<{ ok: boolean; message: string; results: string[] }>(
    `/demo.php${reset ? '?reset=1' : ''}`,
    {},
  );
  return data ?? { ok: false, message: 'Fehler beim Anlegen', results: [] };
}
