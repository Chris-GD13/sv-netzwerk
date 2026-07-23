# Geschuetztes Pruefportal Fensterbeschlagspruefung BMVg Bonn

## Enthaltene Bestandteile

- geschuetzte Astro-Routen unter `/intern/`
- clientseitige Supabase-Authentifizierung mit Session-Guard
- Fensterliste, Dashboard, Datensatzeditor, Auswertung und Exportansicht
- Realtime-Aktualisierung fuer Fensterdatensaetze, Sperren, Audit-Log und Fotos
- lokale IndexedDB-Zwischenspeicherung mit Synchronisierung nach Wiederverbindung
- Supabase-SQL-Migrationen, Seeds und Importvorlage

## Umgebungsvariablen

```env
PUBLIC_SUPABASE_URL=
PUBLIC_SUPABASE_ANON_KEY=
```

## Supabase-Einrichtung

1. Neues Supabase-Projekt erstellen.
2. SQL-Migration `supabase/migrations/20260723220000_fensterpruefung_bonn.sql` ausfuehren.
3. Seed `supabase/seed/fensterpruefung-bonn.sql` ausfuehren.
4. Privaten Storage-Bucket `window-photos-private` anlegen.
5. Realtime fuer `windows`, `record_locks`, `audit_logs` und `photos` aktivieren.
6. Drei Benutzerkonten in Supabase Auth anlegen und den Profilen Rollen zuweisen.
7. Passwort-Zuruecksetzung und Mail-Versand in Supabase konfigurieren.

## Betrieb

- interne Routen sind nicht in Navigation oder Sitemap sichtbar
- `robots.txt` und `noindex,nofollow` sperren externe Indexierung
- Datensatzseiten werden per Rewrite auf eine statische Shell aufgeloest
- Benutzerverwaltung bleibt bis zu einer separaten Admin-API in der Supabase-Konsole

## Bekannte Einschraenkungen

- PDF-Erstellung nutzt die Browser-Druckfunktion der geschuetzten Seiten
- Foto-Vorschauen verwenden Platzhalter, wenn kein signierter Download angefordert wird
- direkte Benutzeranlage im Frontend ist ohne geschuetzte Serverfunktion absichtlich nicht freigeschaltet
