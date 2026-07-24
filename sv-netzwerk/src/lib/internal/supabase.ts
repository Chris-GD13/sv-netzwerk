import { createClient, type SupabaseClient } from '@supabase/supabase-js';

let cachedClient: SupabaseClient | null = null;

export function getSupabaseBrowserClient() {
  const url = import.meta.env.PUBLIC_SUPABASE_URL;
  const anonKey = import.meta.env.PUBLIC_SUPABASE_ANON_KEY;
  if (!url || !anonKey) return null;
  if (!cachedClient) {
    cachedClient = createClient(url, anonKey, {
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
  return Boolean(import.meta.env.PUBLIC_SUPABASE_URL && import.meta.env.PUBLIC_SUPABASE_ANON_KEY);
}
