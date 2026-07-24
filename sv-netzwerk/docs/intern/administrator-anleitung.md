# Administratoranleitung

## Aufgaben

- Benutzerkonten in Supabase Auth anlegen
- Rollen in `profiles.role` pflegen
- Calculation Parameters und Referenzdaten aktualisieren
- Sperren bei Bedarf ueber `release_record_lock` aufheben
- Exporte pruefen und Datensicherung veranlassen

## Inbetriebnahme

1. Migration und Seed ausfuehren.
2. Bucket `window-photos-private` anlegen und privat halten.
3. Benutzerkonten fuer drei Pruefer und einen Administrator anlegen.
4. Profile mit Rollen `pruefer`, `administrator`, `auswertung` fuellen.
5. Realtime und RLS testen.

## Hinweise

- Aenderungen nach Freigabe nur administrativ und mit Begruendung vornehmen.
- Benutzerverwaltung im Frontend ist bewusst nicht freigeschaltet, solange keine sichere Admin-API mit Service-Role-Absicherung vorhanden ist.
