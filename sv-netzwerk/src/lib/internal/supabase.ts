import { createClient, type SupabaseClient } from '@supabase/supabase-js';

let cachedClient: SupabaseClient | null = null;
let configWarningShown = false;

function sanitizeEnvValue(value: unknown) {
  if (typeof value !== 'string') return '';
  return value.trim().replace(/^['"]|['"]$/g, '');
}

function isValidHttpUrl(value: string) {
  try {
    const url = new URL(value);
    return url.protocol === 'http:' || url.protocol === 'https:';
  } catch {
    return false;
  }
}

function getSupabaseConfig() {
  const url = sanitizeEnvValue(import.meta.env.PUBLIC_SUPABASE_URL);
  const anonKey = sanitizeEnvValue(import.meta.env.PUBLIC_SUPABASE_ANON_KEY);
  if (!url || !anonKey) return null;
  if (!isValidHttpUrl(url)) {
    if (!configWarningShown) {
      configWarningShown = true;
      console.error('Invalid PUBLIC_SUPABASE_URL: Must be a valid HTTP or HTTPS URL.');
    }
    return null;
  }
  return { url, anonKey };
}

export function getSupabaseBrowserClient() {
  const config = getSupabaseConfig();
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
  return Boolean(getSupabaseConfig());
}
