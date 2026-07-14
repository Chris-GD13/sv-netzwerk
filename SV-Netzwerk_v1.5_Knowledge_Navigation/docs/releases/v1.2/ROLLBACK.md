# Rücknahme / Rollback

Falls das Release unerwartete Probleme verursacht:

1. GitHub Desktop öffnen.
2. Den Commit `SV-Netzwerk v1.2 Quality & CI` auswählen.
3. Repository → `Revert Changes in Commit`.
4. Den Revert-Commit nach `main` pushen.

Alternativ können ausschließlich folgende Dateien auf den vorherigen Stand zurückgesetzt werden:

- `.github/dependabot.yml`
- `.github/workflows/build-check.yml`
- `.github/workflows/linkcheck.yml`
- `.github/workflows/html-validator.yml`
- `.github/workflows/lighthouse.yml`

Der produktive Workflow `.github/workflows/deploy.yml` ist von diesem Paket nicht betroffen.
