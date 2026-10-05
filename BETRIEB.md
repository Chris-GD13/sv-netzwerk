# Betriebsübersicht sv-netzwerk.eu

Stand: **05.10.2026** · Quelle: Live-Prüfung des IONOS-VPS, Repository `main`/Workflow-Dateien und GitHub-Secret-Liste. Werte von Passwörtern, Tokens, privaten Schlüsseln und sonstigen geheimen Inhalten werden absichtlich nicht ausgegeben.

## 1. Server und Laufzeit

| Bereich | Stand | Speicherort / Dienst | Datum / Hinweis |
|---|---|---|---|
| Öffentliche IP | `217.160.143.102` | IONOS-VPS, `sv-netzwerk.eu` und `www.sv-netzwerk.eu` | Live geprüft 05.10.2026 |
| Benutzer | `root` für den aktuellen Portal-Deploy | SSH/SFTP, GitHub Actions | gesetzt; für den Deploy überprivilegiert, Umstellung auf eigenen Deploy-Benutzer offen |
| Dokumentenstamm | `/var/www/sv-netzwerk` | Apache-VHost | live bestätigt durch Portal-Marker und HTTPS |
| Betriebssystem | Ubuntu `26.04.1 LTS`, Kernel `7.0.0-38-generic` | VPS | live geprüft |
| Webserver | Apache `2.4.66` | `apache2.service` | aktiv |
| PHP | `8.5.4` | Apache/PHP | live geprüft |
| Datenbank-Client | MariaDB `11.8.6` | lokale Datenbank | Serverdienstname nicht eindeutig aus der Service-Abfrage ermittelt |
| SSH | `ssh.service` | VPS | aktiv |
| Cron | `cron.service` | VPS | aktiv |
| Dateirechte `.env` | `600`, `www-data:www-data` | `/var/www/sv-netzwerk/.env` | live geprüft |
| Dateirechte `intern/photos` | `777`, `root:root` | `/var/www/sv-netzwerk/intern/photos` | live geprüft; Schreibbarkeit für Apache gegeben, Rechte zu weit |
| Document Root | `777`, `root:root` | `/var/www/sv-netzwerk` | live geprüft; sicherheitstechnisch zu weit |
| Logs | Apache: `/var/log/apache2/sv-netzwerk-access.log`, `/var/log/apache2/sv-netzwerk-error.log`; Datenbank: `/var/log/mysql/`; Zertifikate: `/var/log/letsencrypt/` | Apache/MariaDB/Certbot | Apache-Logverzeichnis `root:adm`, Datenbank-Logverzeichnis `mysql:adm` |

### Cronjobs und Dienste

- `/etc/crontab`: `run-parts` stündlich um Minute 17, täglich 06:25, wöchentlich Sonntag 06:47, monatlich am 1. um 06:52.
- `/etc/cron.d/php`: PHP-Sessionbereinigung um Minute 09 und 39 jeder halben Stunde.
- `/etc/cron.d/certbot`: Zertifikatserneuerung alle 12 Stunden; systemd-Timer hat Vorrang, falls systemd aktiv ist.
- `/etc/cron.d/e2scrub_all`: wöchentliche bzw. tägliche Dateisystem-Prüfung nur ohne systemd.
- Für `scripts/backup-ionos-to-google.php` wurde in `/etc/cron*` und Benutzer-Crontabs kein geplanter Aufruf gefunden. Die tatsächliche Backup-Frequenz ist deshalb **nicht belegt**.
- Apache, SSH und Cron waren bei der Prüfung aktiv. Ein eigenständiger MariaDB-Systemdienst war mit der verwendeten Dienstsuche nicht eindeutig benannt; die Datenbankabfragen über localhost funktionieren.

### Backups

- Backup-Skript: `/var/www/sv-netzwerk/scripts/backup-ionos-to-google.php`.
- Konzept des Skripts: inkrementeller Abgleich des Primärspeichers nach `/srv/google-drive`, einschließlich komprimiertem Datenbankdump unter `/srv/google-drive/.ionos-backup/database/`, anschließend Google-Drive-Upload.
- Sichtbarer letzter Google-Drive-Stand: `/srv/google-drive/SV-Netzwerk-Projekt/sv-netzwerk-2026-10-02-3258f237.zip`, 02.10.2026 08:52 Uhr. Das ist ein Dateistand, kein Nachweis eines erfolgreich geplanten Laufes.
- Backup-Häufigkeit und letzter erfolgreicher Skriptlauf: **nicht belegt**, weil kein Cron-Aufruf gefunden wurde und keine vollständige Ausführungs-Historie vorliegt.

## 2. Geheimnisse und Zugänge

### GitHub-Repository-Secrets

Alle folgenden Einträge sind in GitHub als gesetzt sichtbar. „Ablauf“ ist in der Secret-Liste nicht angegeben; das Datum ist das sichtbare Änderungsdatum.

| Name | Zweck | Speicherort | gesetzt | Ablauf / Änderung | Dienst |
|---|---|---|---|---|---|
| `ADMIN_EMAIL`, `ADMIN_PASSWORD` | Live-Login für Portalprüfung | GitHub Repository Secret | ja | 26.07.2026 | Portal-Workflow |
| `DB_HOST`, `DB_NAME`, `DB_PASS`, `DB_USER` | Datenbankkonfiguration | GitHub Repository Secret | ja | 26.07.2026 | Portal/Build |
| `M365_CALENDAR_USER_ID`, `M365_CLIENT_ID`, `M365_CLIENT_SECRET`, `M365_TENANT_ID` | ältere Microsoft-365-Kalenderkonfiguration | GitHub Repository Secret | ja | 21.07.2026 | Microsoft Graph |
| `MS_CLIENT_ID`, `MS_CLIENT_SECRET`, `MS_SHAREPOINT_SITE_ID`, `MS_TENANT_ID` | Microsoft Graph/SharePoint | GitHub Repository Secret | ja | 14.08.2026 | Microsoft Graph/SharePoint |
| `OPENAI_API_KEY` | OpenAI-Serverzugriff | GitHub Repository Secret | ja | 31.08.2026 | OpenAI |
| `PUBLIC_SUPABASE_ANON_KEY`, `PUBLIC_SUPABASE_URL` | öffentliche Supabase-Konfiguration | GitHub Repository Secret | ja | 24.07.2026 | Supabase |
| `SETUP_KEY` | Portal-Einrichtung | GitHub Repository Secret | ja | 26.07.2026 | Portal |
| `SFTP_HOST`, `SFTP_PASSWORD`, `SFTP_PORT`, `SFTP_REMOTE_DIR`, `SFTP_USERNAME` | alter Website-Deploy | GitHub Repository Secret | ja | 12.07.2026; `SFTP_REMOTE_DIR` 04.10.2026 | öffentlicher Website-Workflow |
| `PORTAL_SFTP_HOST`, `PORTAL_SFTP_USERNAME`, `PORTAL_SFTP_PORT`, `PORTAL_SFTP_REMOTE_DIR`, `PORTAL_SSH_KEY` | Portal-Deploy auf IONOS-VPS | GitHub Repository Secret | ja | 05.10.2026 | Portal-Workflow |
| `UNSPLASH_ACCESS_KEY` | Bilddienst | GitHub Repository Secret | ja | 17.07.2026 | Unsplash |
| `WHATSAPP_ACCESS_TOKEN`, `WHATSAPP_APPOINTMENT_TEMPLATE`, `WHATSAPP_APP_SECRET`, `WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID`, `WHATSAPP_META_APP_ID`, `WHATSAPP_REGISTRATION_PIN`, `WHATSAPP_TOKEN_ENCRYPTION_KEY`, `WHATSAPP_VERIFY_TOKEN` | WhatsApp/Meta-Integration | GitHub Repository Secret | ja | 09.–10.09.2026 | Meta/WhatsApp |
| `ZAPIER_WEBHOOK_URL` | Webhook-Integration | GitHub Repository Secret | ja | 17.07.2026 | Zapier |
| `LINKEDIN_CLIENT_ID`, `LINKEDIN_CLIENT_SECRET` | LinkedIn-Integration | GitHub Environment Secret (Umgebung in der Liste nicht lesbar) | ja | 17.07.2026 | LinkedIn |

### VPS-`.env` (nur Schlüsselnamen)

Datei `/var/www/sv-netzwerk/.env`, `600`, `www-data:www-data`, gesetzt: **ja**. Die Datei enthält folgende Schlüssel; Werte und Inhalte werden nicht ausgegeben:

`DB_HOST`, `DB_NAME`, `DB_PASS`, `DB_PORT`, `DB_USER`, `GOOGLE_DRIVE_BLANCO_FOLDER_ID`, `GOOGLE_DRIVE_CASES_FOLDER_ID`, `GOOGLE_DRIVE_CLIENT_ID`, `GOOGLE_DRIVE_CLIENT_SECRET`, `GOOGLE_DRIVE_KNOWLEDGE_FOLDER_ID`, `GOOGLE_DRIVE_REFRESH_TOKEN`, `IONOS_STORAGE_ROOT`, `MS_CLIENT_ID`, `MS_CLIENT_SECRET`, `MS_TENANT_ID`, `OPENAI_API_KEY`, `OPENAI_MODEL`, `PORTAL_STORAGE_BACKEND`, `PROJECT_NAME`, `WHATSAPP_ACCESS_TOKEN`, `WHATSAPP_APPOINTMENT_TEMPLATE`, `WHATSAPP_APP_SECRET`, `WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID`, `WHATSAPP_META_APP_ID`, `WHATSAPP_TEMPLATE_LANGUAGE`, `WHATSAPP_TOKEN_ENCRYPTION_KEY`, `WHATSAPP_VERIFY_TOKEN`.

`IONOS_STORAGE_ROOT` kommt in der Datei doppelt vor; das ist ein Konfigurationsrisiko. Eine separate `intern/api/.env` mit erkennbaren `KEY=VALUE`-Einträgen wurde nicht festgestellt.

### Microsoft/Entra, Google, ClaimsForce und SSH

| Zugang | Zweck / Profil | Speicherort | gesetzt | Ablauf / Änderung | Zuständiger Dienst |
|---|---|---|---|---|---|
| Entra Tenant/Client | Microsoft Graph Mail, Kalender, SharePoint | VPS-`.env` (`MS_TENANT_ID`, `MS_CLIENT_ID`, `MS_CLIENT_SECRET`) und GitHub-Secrets | ja | Secret-Ablaufdatum nicht aus GitHub/VPS ablesbar; Änderungsstand der GitHub-Secrets 14.08.2026 | Microsoft Entra/Graph |
| Google-Drive-Client | Fallakten, Wissensablage, Backup | VPS-`.env` (`GOOGLE_DRIVE_CLIENT_ID`, `GOOGLE_DRIVE_CLIENT_SECRET`, `GOOGLE_DRIVE_REFRESH_TOKEN`) | ja | Ablaufdatum nicht belegt | Google Drive |
| ClaimsForce | Browser-Bridge; Profil Christian Wächter, daneben Profile Jens, Marc und Holger im Portal | Chrome-Erweiterung/Browser-Sitzung und Portal-Profilrouting; kein Klartextzugang im Repository | Profil Christian im Portal gesetzt; vollständige Zugangsdaten für einzelne Profile können fehlen | Änderung/Rotation nicht belegt | ClaimsForce-Bridge |
| Portal-SSH-Key | GitHub-Deploy auf den IONOS-VPS | GitHub-Secret `PORTAL_SSH_KEY`; lokale Arbeitskopie `C:\Users\chris\.ssh\ionos_svportal_ed25519` | ja | GitHub geändert 05.10.2026; Ablaufdatum nicht belegt | GitHub Actions / OpenSSH |
| SSH-Berechtigung | aktueller Deploy | VPS-Benutzer `root` | ja | keine heutige Rotation belegt | OpenSSH |

## 3. Datenbank

| Merkmal | Stand |
|---|---|
| Datenbank | `dbs15938486` |
| Host/Port | `127.0.0.1:3306` |
| Benutzer | `svportal` |
| Backup | `mysqldump --single-transaction --routines --triggers`, gzip, unter `/srv/google-drive/.ionos-backup/database/`; Ausführungsfrequenz nicht belegt |

Tabellen: `ai_activity_log`, `app_settings`, `audit_logs`, `bki_calculations`, `bki_calculation_drafts`, `bki_kva_jobs`, `buildings`, `calculation_parameters`, `case_folder_owners`, `chatgpt_case_drafts`, `claimsforce_import_jobs`, `claimsforce_profile_status`, `claimsforce_task_status`, `export_logs`, `floors`, `gf_ai_jobs`, `kva_send_log`, `login_attempts`, `login_blocks`, `oauth_access_tokens`, `oauth_authorization_codes`, `oauth_clients`, `oauth_refresh_tokens`, `password_resets`, `phonebook_contacts`, `portal_field_notes`, `portal_revenue_summary`, `portal_work_items`, `projects`, `record_locks`, `report_archive`, `rooms`, `users`, `whatsapp_case_links`, `whatsapp_messages`, `whatsapp_profile_connections`, `windows`, `window_sashes`.

## 4. Deployments

### Portal-Workflow `.github/workflows/deploy-portal.yml`

- Trigger: Push auf `main` bei Portal-/Bridge-Dateien oder `workflow_dispatch`.
- Secrets: `PORTAL_SFTP_HOST`, `PORTAL_SFTP_USERNAME`, `PORTAL_SFTP_PORT`, `PORTAL_SFTP_REMOTE_DIR`, `PORTAL_SSH_KEY`; zusätzlich `MS_*`, `WHATSAPP_*` für die Serverkonfiguration und `ADMIN_EMAIL`/`ADMIN_PASSWORD` für den Live-Test.
- Ziel: `/var/www/sv-netzwerk`.
- Vor dem Upload: Bridge-Zip wird aus `sv-netzwerk/browser-extension/claimsforce-bridge/` neu gebaut; Probedatei muss unter `https://www.sv-netzwerk.eu/intern/` zurücklesbar sein.
- Überschreibt: Portaldateien, `intern/deploy-version.txt`, `intern/claimsforce-central.js`, Bridge-Zip, ausgewählte Portal-Indexdateien und die konfigurierten Microsoft-/WhatsApp-Schlüssel in `.env`.
- Schützt: `.env` beim Mirror, `intern/photos`, `intern/api/config.php`, `.htaccess` und Logs; `.well-known/.htaccess` wird gezielt gesetzt.
- Live-Nachweis: Marker, Login 200, geschütztes Cockpit 302, authentifiziertes Cockpit 200, Bridge-Hash und OAuth-Metadaten.

### Öffentlicher Workflow `.github/workflows/deploy.yml`

- Trigger: Push auf `main` außerhalb des Portalbereichs oder `workflow_dispatch`.
- Secrets: alte `SFTP_*`-Secrets.
- Ziel: Website-Document-Root; Portalpfad `intern/**`, `.env`, `.htaccess` und Logs werden ausgeschlossen.
- Überschreibt: öffentliche Website, einschließlich öffentlichem `deploy-version.txt` und Fachwissen-Dateien.
- Berührt nie: Portal `intern/**`, `.env`, `.htaccess` und `intern/photos`.
- Risiko: Die alten `SFTP_*`-Secrets sind weiterhin aktiv und müssen vom Portalzugang getrennt bleiben.

## 5. Alte Reste und nicht mehr verwendete Pfade

- Alte Website-Secrets `SFTP_HOST`, `SFTP_USERNAME`, `SFTP_PASSWORD`, `SFTP_PORT`, `SFTP_REMOTE_DIR` existieren weiter und gehören ausschließlich zum öffentlichen Workflow.
- `G:`-Pfade und alte lokale `/intern`-Laufwerke sind keine Produktionsquelle. G: bleibt höchstens geprüfte Kopie/Anweisungsablage.
- Historische SFTP-Zielversuche (`public`, `sv-netzwerk/public`, `sv-netzwerk`) waren geraten und wurden durch `PORTAL_SFTP_REMOTE_DIR` plus HTTPS-Probe ersetzt.
- Der frühere Stand mit `Commit: local-build` unter `/intern/deploy-version.txt` ist durch den IONOS-VPS-Deploy abgelöst.
- Ein separates altes Produktionssystem neben `217.160.143.102` ist nicht belegt; die frühere SFTP-Domain-Abweichung war ein Zugang-/Zielpfadproblem.

## 6. Änderungen am 05.10.2026

| Zeitpunkt | Änderung | Grund | Nachweis |
|---|---|---|---|
| ca. 19:33–19:38 MESZ | GitHub-Secrets `PORTAL_SFTP_HOST`, `PORTAL_SFTP_USERNAME`, `PORTAL_SFTP_PORT`, `PORTAL_SFTP_REMOTE_DIR`, `PORTAL_SSH_KEY` angelegt | Portal-Deploy vom alten Website-SFTP trennen und auf den IONOS-VPS richten | GitHub-Secret-Liste, 05.10.2026 |
| 17:39 UTC | Portal-`.env` durch Lauf 37349900237/Commit `b274c133` synchronisiert; ausgewählte `MS_*`-/`WHATSAPP_*`-Einträge aus GitHub-Secrets aktualisiert, übrige Einträge erhalten | Serverkonfiguration des neuen Portal-Workflows | `/intern/deploy-version.txt` und Workflow-Log |
| 17:46 UTC | Portal-`.env` durch Lauf 37350809231/Commit `59530b75` erneut synchronisiert; dieselbe selektive Konfigurationsaktualisierung | erfolgreicher Main-Deploy auf den VPS | Marker: `Build-Zeit 2026-10-05T17:46:10.649Z`, `Scope: portal` |
| 17:46 UTC | Portaldateien, Bridge-Zip und `intern/deploy-version.txt` auf `/var/www/sv-netzwerk` übertragen | Veröffentlichung des Portal-Commits | Live-Marker und HTTPS-Prüfungen |
| 05.10.2026 | Kein Passwort-, Token- oder Schlüsselwert in diese Dokumentation übernommen | Schutz der Zugangsdaten | diese Datei |

Eine nachweisbare heutige Änderung an anderen VPS-Dateien oder eine Secret-Rotation außerhalb der genannten Einträge liegt nicht vor.

## 7. Fünf Störungen des Tages

| Störung | Ursache | Behebung | Künftige Verhinderung |
|---|---|---|---|
| Portal-Deploy schrieb in falschen Stamm | Zielpfad wurde geraten und SFTP-Zugang zeigte nicht auf den Domain-Document-Root | eigener `PORTAL_*`-Satz und fester VPS-Pfad | Probedatei per SFTP hochladen und über HTTPS zurücklesen; bei Abweichung sofort abbrechen |
| `.well-known`/Portaldateien fehlten im Paket | Build- und Deploy-Quellen waren unterschiedlich behandelt | Build-Ausgabe und Zielpfade explizit geprüft | `prepare-deploy-scope` plus `test -f`-Prüfungen vor Upload |
| `.htaccess` wurde am falschen Ort erwartet | geschützte Root-Datei wurde aus dem Mirror ausgeschlossen | `dist/.well-known/.htaccess` gezielt übertragen | geschützte Dateien dokumentieren und explizit prüfen |
| `mkdir` scheiterte an bereits vorhandenem Zielordner | Deploy nahm einen leeren Zielordner an | kein unnötiges `mkdir`, vorhandene Ordner direkt verwenden | idempotente Upload-Schritte |
| Bridge/Aufgabenstand und Live-Marker waren veraltet | alte Bridge bzw. alte SFTP-Secrets; Aufgabenstatus lieferte kein numerisches `openTasks` | Bridge aus Quellcode bauen, neue Portal-Secrets, Live-Checks | Bridge-Hash-/Versionsprüfung, Markerprüfung und Fehlerstatus statt stiller Null-/Leeranzeige |

Zusatzbefund zum Aufgabenstand: In `claimsforce_task_status` stehen für Christian und Jens `source_job_id = NULL`; aktuelle Importjobs liefern `openTasks: null`. Der Dashboard-Code zeigt deshalb keinen bestätigten Aufgabenstand an. Das ist ein Daten-/Bridge-Rückgabefehler, kein belastbarer Wert „0“.

## 8. Aktueller Fehler: „Nicht versendet: Microsoft-Anmeldung ist fehlgeschlagen“

**Belegbarer Ablauf:** In `/var/www/sv-netzwerk/intern/api/outlook-case-mail.php` wird diese Meldung ausgegeben, wenn der OAuth-Token-Aufruf an `login.microsoftonline.com/<Tenant>/oauth2/v2.0/token` nicht HTTP 200 liefert oder kein `access_token` zurückgibt. Derselbe Abbruch ist in `kva-release.php` implementiert.

**Logbefund:** Im Apache-Fehlerlog vom 05.10.2026 stehen wiederholt `preg_match(): Allocation of JIT memory failed, PCRE JIT will be disabled` in `intern/oauth/lib.php` sowie in Outlook-/Storage-Endpunkten. Der Access-Log zeigt wiederholte `POST /intern/oauth/token.php` mit HTTP 200 und einer kurzen Fehlerantwortgröße. Ein Microsoft-HTTP-Status, ein Entra-Fehlercode oder der Token-Antworttext wird aktuell nicht in das Apache-Fehlerlog geschrieben. Deshalb ist die konkrete Entra-Ursache (Secret abgelaufen, falscher Tenant/Client oder fehlende Berechtigung) **mit dem vorhandenen Log nicht abschließend beweisbar**.

**Betriebsmaßnahme:** Den Token-Status und den Microsoft-Fehlercode künftig serverseitig redigiert protokollieren (ohne Secret/Token), zusätzlich `pcre.jit=0` für PHP setzen und danach den authentifizierten Versand erneut live prüfen. Bis dahin darf die Meldung nicht als Beweis für einen abgelaufenen Client-Secret ausgegeben werden.
