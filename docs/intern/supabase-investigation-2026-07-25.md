# Supabase-Migration – Abgeschlossen 2026-07-25

Supabase wurde als Backend vollständig durch PHP 8.4 + MySQL 8.0 ersetzt.

Dieses Dokument verbleibt als historische Referenz zur ursprünglichen
Fehleranalyse. Die beschriebenen Probleme (fehlende Secrets, stündliche
Deployment-Loops durch Kalender-Sync) sind durch die Migration behoben.

## Umgesetzte Maßnahmen

- `client.ts` vollständig auf PHP-API umgestellt (`php-api.ts`)
- `@supabase/supabase-js` wird im internen Portal nicht mehr verwendet
- Deploy-Workflow: Supabase-Secret-Prüfschritte entfernt
- PHP-Backend: `public/intern/api/` (auth, windows, locks, photos, parameters, exports)
- MySQL-Schema: `public/intern/api/schema.sql`
- Einrichtungsassistent: `public/intern/api/setup.php`
- `.env.example` mit MySQL-Konfiguration
