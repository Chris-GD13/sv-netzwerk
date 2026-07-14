# Migration von v1.5.4 auf v1.6.0

1. Paket vollständig in den Repository-Stamm kopieren.
2. Vorhandene Dateien ersetzen.
3. GitHub Desktop öffnen und Änderungen prüfen.
4. Commit `SV-Netzwerk v1.6.0 SVOS Foundation` erstellen.
5. Push ausführen.
6. Build-Check und IONOS-Deployment prüfen.
7. `/svos/` aufrufen und Collection-Zähler kontrollieren.

Die bestehende Datei `src/data/library.ts` bleibt vorerst aktiv. Eine Datenmigration ist in v1.6.1 vorgesehen.
