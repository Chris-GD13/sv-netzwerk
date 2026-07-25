import { createClient, type SupabaseClient } from '@supabase/supabase-js';

let cachedClient: SupabaseClient | null = null;
let cachedConfig: { url: string; anonKey: string } | null | undefined;
let configWarningShown = false;

const SUPABASE_URL_ENV_NAMES = ['PUBLIC_SUPABASE_URL', 'VITE_SUPABASE_URL'] as const;
const SUPABASE_ANON_KEY_ENV_NAMES = ['PUBLIC_SUPABASE_ANON_KEY', 'VITE_SUPABASE_ANON_KEY'] as const;

function normalizeEnvValue(value: unknown): string | null {
  if (typeof value !== 'string') return null;
  const trimmed = value.trim();
  if (!trimmed) return null;
  const unquoted = trimmed.replace(/^['"]+|['"]+$/g, '').trim();
  if (!unquoted) return null;
  if (unquoted.startsWith('${{') || /^undefined|null$/i.test(unquoted)) return null;
  return unquoted;
}

function readEnvValue(names: readonly string[]): string | null {
  const env = import.meta.env as Record<string, unknown>;
  for (const name of names) {
    const normalized = normalizeEnvValue(env[name]);
    if (normalized) return normalized;
  }
  return null;
}

function isValidHttpUrl(value: string): boolean {
  try {
    const parsed = new URL(value);
    return parsed.protocol === 'https:' || parsed.protocol === 'http:';
  } catch {
    return false;
  }
}

function resolveRuntimeConfig() {
  if (cachedConfig !== undefined) return cachedConfig;
  const url = readEnvValue(SUPABASE_URL_ENV_NAMES);
  const anonKey = readEnvValue(SUPABASE_ANON_KEY_ENV_NAMES);
  if (!url || !anonKey) {
    cachedConfig = null;
    return cachedConfig;
  }
  if (!isValidHttpUrl(url)) {
    if (!configWarningShown) {
      configWarningShown = true;
      console.error('Invalid Supabase URL: Set PUBLIC_SUPABASE_URL or VITE_SUPABASE_URL to a valid HTTP or HTTPS URL.');
    }
    cachedConfig = null;
    return cachedConfig;
  }
  cachedConfig = { url, anonKey };
  return cachedConfig;
}

export function getSupabaseBrowserClient() {
  const config = resolveRuntimeConfig();
  if (!config) return null;
  if (!cachedClient) {
    cachedClient = createClient(config.url, config.anonKey, {
      auth: {
        persistSession: true,
        autoRefreshToken: true,
        detectSessionInUrl: true,
        storageKey: 'sv-intern-auth',
      },
      realtime: {
        params: { eventsPerSecond: 10 },
      },
      db: {
        schema: 'public',
      },
      global: {
        headers: {
          'x-application-name': 'sv-netzwerk-intern',
        },
      },
    });
  }
  return cachedClient;
}

export function hasSupabaseConfig() {
  return Boolean(resolveRuntimeConfig());
}
