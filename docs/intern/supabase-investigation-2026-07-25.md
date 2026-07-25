# Supabase-Fehleranalyse – 2026-07-25

**Commit unter Untersuchung:** `95cac9e06ff099edc25316eae7afb817887e1ff2`  
**Version:** Homepage-v5  
**Fehlermeldung:** `Invalid supabaseUrl: Must be a valid HTTP or HTTPS URL.`

---

## 1. Erwartete Umgebungsvariable für die Supabase-URL

`PUBLIC_SUPABASE_URL` — das `PUBLIC_*`-Präfix von Astro/Vite kennzeichnet clientseitig zugängliche Umgebungsvariablen.

---

## 2. Verwendung im Quellcode

**Datei:** `sv-netzwerk/src/lib/internal/supabase.ts`, **Zeile 21:**
```ts
const url = sanitizeEnvValue(import.meta.env.PUBLIC_SUPABASE_URL);
```

`getSupabaseConfig()` validiert den Wert anschließend:
- Leer → gibt `null` zurück (kein Client wird erzeugt, Portal zeigt Konfigurations-Hinweis)
- Nicht-leer, aber kein HTTP/HTTPS → `console.error('Invalid PUBLIC_SUPABASE_URL: Must be a valid HTTP or HTTPS URL.')`, gibt `null` zurück
- Gültig → ruft `createClient(url, anonKey, …)` auf

`createClient` wird ausschließlich in `supabase.ts` importiert und verwendet. Das `InternalAppShell.astro`-Komponent startet das Portal via `mountInternalPortal()` (`client.ts`). Der Pfad `renderConfigMissing()` in `client.ts` Zeile 163 wird ausgelöst, wenn `hasSupabaseConfig()` `false` zurückgibt.

---

## 3. Injektion durch den GitHub Actions Workflow

**Ja — in zwei Schritten von `.github/workflows/deploy.yml`:**

**Schritt „Supabase-Umgebungsvariablen validieren und exportieren" (Zeilen 68–111):**
- Liest `secrets.PUBLIC_SUPABASE_URL`
- Normalisiert den Wert (entfernt Leerzeichen und umschließende Anführungszeichen)
- Bei fehlendem oder leerem Wert: gibt nur `::warning::` aus (kein `::error::`) und setzt den Wert auf `""` — **der Build wird nicht abgebrochen**
- Exportiert via `printf 'PUBLIC_SUPABASE_URL=%s\n' "$public_supabase_url" >> "$GITHUB_ENV"` und schreibt `.env.production`

**Schritt „Astro-Projekt prüfen und bauen" (Zeilen 113–117):**
```yaml
env:
  PUBLIC_SUPABASE_URL: ${{ env.PUBLIC_SUPABASE_URL }}
```
Übergibt den (möglicherweise leeren) Wert als explizite Umgebungsvariable an `npm run build`.

**Kritische Lücke:** Der Validierungsschritt macht fehlende Secrets nicht fatal. Der Workflow läuft stillschweigend mit einer leeren URL weiter, statt abzubrechen.

---

## 4. Existenz des GitHub Secret

**Kann aus den Repository-Dateien nicht verifiziert werden.** Der Workflow referenziert `${{ secrets.PUBLIC_SUPABASE_URL }}` und `${{ secrets.PUBLIC_SUPABASE_ANON_KEY }}`. Da der Workflow so gestaltet ist, dass er bei fehlendem Secret mit einer Warnung weiterläuft (statt zu scheitern), erzeugt ein fehlendes Secret keinen Build-Fehler — nur ein stillschweigend defektes Portal. Der beobachtete Fehler ist konsistent damit, dass das Secret fehlt oder einen ungültigen Wert enthält.

---

## 5. Leerer String im Produktions-Bundle

**Das `dist/`-Verzeichnis ist nicht im Repository eingecheckt, das Live-Bundle kann daher nicht direkt geprüft werden.**

Aus dem Workflow ableitbar: Wenn `secrets.PUBLIC_SUPABASE_URL` nicht gesetzt oder leer ist, schreibt der Validierungsschritt `PUBLIC_SUPABASE_URL=` (leerer Wert) in `$GITHUB_ENV` und `.env.production`. Vite/Astro ersetzt alle `import.meta.env.PUBLIC_*`-Werte zur Build-Zeit statisch. Damit würde `import.meta.env.PUBLIC_SUPABASE_URL` in jedem JS-Bundle-Chunk durch `""` ersetzt — das ist das mechanisch erwartete Ergebnis bei fehlendem Secret.

---

## 6. Warum der Deploy-Workflow weiterläuft, obwohl deploy-version.txt aktualisiert ist

**Ursache: Der `calendar-sync.yml`-Workflow löst den Deploy-Workflow stündlich aus.**

Verifizierte Fakten:
- `calendar-sync.yml` läuft per Cron `12 * * * *` (jede Stunde zur Minute 12) und committed Änderungen an `sv-netzwerk/src/data/calendar-slots.json`, wenn sich Outlook-Kalenderdaten geändert haben.
- Der Deploy-Workflow triggert bei `paths: ["sv-netzwerk/**"]`. Da `calendar-slots.json` unter `sv-netzwerk/src/data/` liegt, löst jeder Kalender-Sync-Commit mit Änderungen einen neuen Deploy-Lauf aus.
- Die zwei jüngsten Commits (`784e5e5` und `6a23ba8`) sind beide `chore: sync outlook calendar slots`-Commits; `6a23ba8` hat `calendar-slots.json` geändert.
- Der Deploy-Workflow hat `concurrency: cancel-in-progress: true`. Jeder neue Kalender-Sync-Commit bricht den laufenden Deploy ab und startet einen neuen.
- Der letzte Schritt „Deployment online verifizieren" pollt `https://www.sv-netzwerk.eu/deploy-version.txt` bis zu 24 × 15 s = 6 Minuten lang auf den aktuellen Commit-SHA. Da die Live-Datei `95cac9e` zeigt (der zuletzt vollständig abgeschlossene Deploy), läuft jeder neuere Lauf in der Schleife weiter — bis Timeout, Treffer oder Abbruch durch den nächsten Kalender-Sync-Commit.

**Zusammenfassung:** Der Deploy-Workflow ist nicht hängengeblieben — er wird stündlich durch Kalender-Sync-Commits neu ausgelöst, wobei jeder Commit den vorherigen Deploy-Lauf abbricht, bevor dieser die Online-Verifizierung abschließen kann. Die Live-Datei `deploy-version.txt` zeigt `95cac9e`, weil das der letzte vollständig abgeschlossene Deploy war; alle nachfolgenden Läufe wurden mitten in der Verifizierung abgebrochen.
