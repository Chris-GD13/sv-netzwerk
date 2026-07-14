# Entscheidungen

Die ausführlichen Begründungen stehen in `docs/adr/`.

## 14.07.2026

1. GitHub `Chris-GD13/sv-netzwerk` auf `main` ist der Projektmaster.
2. Codex arbeitet als Entwicklungsumgebung direkt auf dem Repository und liefert reproduzierbare Builds.
3. Deployment erfolgt ausschließlich über GitHub Actions nach IONOS.
4. Das IONOS-Document-Root ist `/sv-netzwerk`; der Inhalt von `dist/` liegt direkt dort.
5. `deploy-version.txt` ist der technische Live-Nachweis für Commit und Buildzeit.
6. Navigation, Mobile-Menü und Footer werden zentral gerendert.
7. Experten sind dauerhaft ein eigenständiger Hauptbereich; Netzwerk und Experten werden nicht vermischt.
8. Schadenarten, Experten und Kurzvideos werden datengetrieben modelliert.
9. SVOS ist die zukünftige Fach-, Wissens- und Arbeitsplattform, wird aber nur mit abgesicherten Funktionen erweitert.
10. Der tägliche Fachwissensstandard wird im Repository und in CI geprüft; Inhalte werden nicht automatisch erfunden oder veröffentlicht.
