# Manifest – SV-Netzwerk v1.4.1 CI & Stabilisierung

## Geänderte Infrastrukturdateien

- `.github/workflows/build-check.yml`
- `.github/workflows/deploy.yml`
- `sv-netzwerk/package-lock.json`

## Aktualisierte Release-Dokumentation

- `VERSION`
- `CHANGELOG.md`
- `INSTALL.md`
- `MANIFEST.md`
- `RELEASE_NOTES.md`
- `VALIDATION.md`

## Paketumfang

Das ZIP enthält den vollständigen Projektstand mit allen Website-Dateien und Unterordnern. Ausgeschlossen sind ausschließlich lokale beziehungsweise generierte Verzeichnisse, die nicht in ein Austauschpaket gehören:

- `.git/`
- `node_modules/`
- `.astro/`
- `dist/`

Diese Verzeichnisse werden lokal beziehungsweise in GitHub Actions neu erzeugt. Bestehende Repository-Metadaten bleiben dadurch unangetastet.
