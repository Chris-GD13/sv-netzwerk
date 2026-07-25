/**
 * PHP-API-Client – Fensterbeschlagsprüfung BMVg Bonn
 *
 * Ersetzt den Supabase-Client durch direkte fetch()-Aufrufe an die PHP-Backend-API.
 * Alle Endpunkte liegen unter /intern/api/*.php.
 */

import type {
  AuditLogEntry,
  CalculationParameterMap,
  LockResult,
  PhotoItem,
  PortalRole,
  PortalUser,
  WindowRecord,
  WindowSummary,
} from './types';
import { normalizeCalculationParameters } from './calculations';

const API_BASE = '/intern/api';

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
  const { data } = await apiGet<WindowSummary[]>('/windows.php');
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
    '/windows.php?action=locks',
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
