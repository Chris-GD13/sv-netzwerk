# Backup- und Exportbeschreibung

## Datensicherung

- PostgreSQL-Backups ueber Supabase-Projektbackups
- private Bucket-Dateien regelmaessig exportieren
- Exportprotokolle werden in `export_logs` gespeichert

## Verfuegbare Exporte

- CSV-Gesamtexport
- Excel-kompatibler CSV-Export
- Maengelliste
- Liste nicht zugaenglicher Fenster
- Liste Spezialpruefungen
- Liste dringender Sicherungsmassnahmen
- Browser-Druckansicht fuer Einzel- und Sammelprotokolle

## Empfohlener Ablauf

1. Vor groesseren Zwischenstaenden CSV-Gesamtexport erstellen.
2. Nach Arbeitstagen Bucket-Dateien sichern.
3. Vor Freigabe Management-Zusammenfassung und Maengelliste ablegen.
