# Datenfluss-Dokumentation

## Übersicht

Dieses Dokument beschreibt den vollständigen Datenfluss der Anwendung
für jede Hauptfunktion.

---

## 1. Login

```
Benutzer (Browser)
  │ E-Mail + Passwort (JSON POST)
  ▼
Frontend (client.ts → apiLogin())
  │ POST /intern/api/auth.php {action: "login"}
  ▼
Backend (auth.php)
  │ 1. Validierung: E-Mail + Passwort nicht leer
  │ 2. SELECT * FROM benutzer WHERE email = ?
  │ 3. password_verify(passwort, hash) [Argon2ID]
  │ 4. Prüfe: is_active = 1
  │ 5. $_SESSION['user_id'] = id
  │ 6. UPDATE last_login = NOW()
  ▼
Datenbank (MySQL)
  │ Benutzer-Record mit Rolle
  ▼
Backend → Response
  │ {success: true, user: {id, name, email, role}}
  ▼
Frontend
  │ Speichert User in SPA-State
  │ Navigiert zu: /dashboard
  ▼
Benutzer sieht: Dashboard
```

---

## 2. Gebäude anlegen

```
Admin/Projektleiter (Browser)
  │ Formular: Name + Kürzel
  ▼
Frontend (client.ts → renderGebaeude())
  │ POST /intern/api/gebaeude.php {action: "create"}
  ▼
Backend (gebaeude.php)
  │ 1. Session-Check (eingeloggt?)
  │ 2. Rollenprüfung (admin ODER projektleiter)
  │ 3. Validierung: name nicht leer, kuerzel max 10 Zeichen
  │ 4. INSERT INTO gebaeude (projekt_id, name, kuerzel)
  ▼
Datenbank
  │ Neuer Record in `gebaeude`
  │ Auto-Increment ID
  ▼
Backend → Response
  │ {success: true, id: 42}
  ▼
Frontend
  │ Refresh: Gebäudeliste neu laden
  │ Erfolgsmeldung anzeigen
  ▼
Benutzer sieht: Neues Gebäude in Liste
```

---

## 3. Fenster prüfen

```
Prüfer/Sachverständiger (Browser)
  │ Navigiert: Gebäude → Etage → Raum → Fenster → Flügel
  ▼
Frontend (renderFluegel())
  │ 1. GET /intern/api/fluegel.php?id=X (Daten laden)
  │ 2. POST /intern/api/lock.php {action: "acquire"} (Record sperren)
  ▼
Backend (lock.php)
  │ 1. Check: locked_by IS NULL OR expired
  │ 2. UPDATE record_locks SET locked_by = user_id
  ▼
Prüfer füllt Formular aus:
  │ Beschlagstyp, Maße, Zustand, Mängel, Bemerkungen
  ▼
Frontend
  │ POST /intern/api/fluegel.php {action: "update", data: {...}}
  ▼
Backend (fluegel.php)
  │ 1. Session + Rolle prüfen
  │ 2. Lock-Owner prüfen (nur eigener Lock erlaubt)
  │ 3. UPDATE fluegel SET ... WHERE id = ?
  │ 4. INSERT INTO audit_log (action, entity, old_data, new_data)
  │ 5. UPDATE fenster SET status = 'in_bearbeitung'
  │ 6. Release Lock
  ▼
Datenbank
  │ Flügel aktualisiert
  │ Audit-Log geschrieben
  │ Status propagiert
  ▼
Backend → Response
  │ {success: true}
  ▼
Frontend
  │ Erfolgsmeldung
  │ Nächsten Flügel laden (oder zurück)
```

---

## 4. Foto-Upload

```
Prüfer (Browser)
  │ Datei auswählen (drag&drop oder Klick)
  ▼
Frontend (renderFotos())
  │ FormData erstellen
  │ POST /intern/api/fotos.php (multipart/form-data)
  ▼
Backend (fotos.php)
  │ 1. Session + Rolle prüfen
  │ 2. MIME-Type Whitelist (image/jpeg, image/png)
  │ 3. Dateigröße prüfen (max. 20 MB)
  │ 4. Einzigartigen Dateinamen generieren (UUID)
  │ 5. move_uploaded_file() → /uploads/fotos/
  │ 6. INSERT INTO fotos (fenster_id, dateiname, pfad, ...)
  ▼
Dateisystem
  │ /uploads/fotos/abc-def-123.jpg
  ▼
Datenbank
  │ Neuer Record in `fotos`
  ▼
Backend → Response
  │ {success: true, foto: {id, url, thumbnail_url}}
  ▼
Frontend
  │ Foto in Galerie anzeigen
```

---

## 5. KI-Dokumentenimport

```
Admin/Prüfer (Browser)
  │ Datei per Drag&Drop auf Upload-Zone
  ▼
Frontend (renderAiImport())
  │ FormData mit Datei
  │ POST /intern/api/ai-import.php {action: "analyze"}
  ▼
Backend (ai-import.php)
  │ 1. Session + Rolle prüfen (nur admin/pruefer)
  │ 2. Datei empfangen und MIME validieren
  │ 3. AiService::analyzeDocument(file) aufrufen
  ▼
AiService.php
  │ 1. prepareFileContent() → base64 für Bilder, Text für CSV/TXT
  │ 2. buildDocumentAnalysisPrompt() → System-Prompt
  │ 3. cURL → OpenAI API (GPT-4o Vision)
  │ 4. JSON-Response parsen
  ▼
OpenAI API
  │ Analysiert: Gebäude, Etagen, Räume, Fenster
  │ Gibt strukturierte Daten zurück (JSON)
  ▼
Backend (classifyItems())
  │ 1. Für jeden erkannten Datensatz:
  │    a. SELECT existing FROM DB
  │    b. Vergleich: neu / ergänzung / abweichung / vorhanden
  │ 2. Kategorien zuweisen
  ▼
Backend → Response
  │ {success: true, items: [{type, status, data, change_description}]}
  ▼
Frontend
  │ Zeigt 4 Gruppen:
  │ - 🟢 Neue Datensätze
  │ - 🔵 Ergänzungen
  │ - 🟡 Abweichungen (Benutzer entscheidet)
  │ - ⚪ Bereits vorhanden
  ▼
Benutzer wählt aus: "Übernehmen" / "Ablehnen"
  ▼
Frontend
  │ POST /intern/api/ai-import.php {action: "apply", items: [...]}
  ▼
Backend (handleApply())
  │ Für jeden bestätigten Datensatz:
  │ - Neu: INSERT INTO ... (Gebäude/Etage/Raum/Fenster)
  │ - Ergänzung: UPDATE SET col = CASE WHEN empty THEN new ELSE old END
  │ - Abweichung: Direktes UPDATE der bestätigten Felder
  │ - Audit-Log schreiben
  ▼
Datenbank
  │ Datensätze angelegt/ergänzt
  ▼
Backend → Response
  │ {success: true, applied: 15, skipped: 3}
  ▼
Frontend: Erfolgsmeldung + Refresh
```

---

## 6. CSV-Export

```
Benutzer (mit Export-Berechtigung)
  │ Klick auf "Export" + Filter wählen
  ▼
Frontend
  │ GET /intern/api/export.php?format=csv&filter=...
  ▼
Backend (export.php)
  │ 1. Session + Rolle prüfen
  │ 2. SQL mit Filtern zusammenbauen (Prepared Statements)
  │ 3. Header: Content-Type: text/csv; charset=utf-8
  │ 4. BOM schreiben (Excel-Kompatibilität)
  │ 5. Zeilen mit fputcsv() ausgeben
  ▼
Datenbank
  │ SELECT mit JOINs über Gebäude→Etage→Raum→Fenster→Flügel
  ▼
Browser
  │ Download-Dialog: fensterpruefung_export_2026-07-26.csv
```

---

## 7. Architekturdiagramm

```
┌─────────────────────────────────────────────────────────┐
│                      Browser (SPA)                        │
│  ┌──────────┐  ┌──────────┐  ┌───────────┐             │
│  │ client.ts│  │ php-api.ts│  │ styles.css│             │
│  │ (Routing │  │ (API-Calls│  │           │             │
│  │  UI, DOM) │  │  Typen)  │  │           │             │
│  └────┬─────┘  └────┬─────┘  └───────────┘             │
│       │              │                                    │
└───────┼──────────────┼────────────────────────────────────┘
        │              │
        │    JSON/HTTP  │
        ▼              ▼
┌─────────────────────────────────────────────────────────┐
│                   PHP Backend (API)                       │
│                                                          │
│  ┌─────────┐ ┌──────────┐ ┌─────────┐ ┌────────────┐  │
│  │auth.php │ │gebaeude. │ │fluegel. │ │ai-import.  │  │
│  │         │ │php       │ │php      │ │php         │  │
│  └────┬────┘ └────┬─────┘ └────┬────┘ └─────┬──────┘  │
│       │           │            │             │          │
│       └───────────┼────────────┼─────────────┘          │
│                   │            │                         │
│              ┌────▼────────────▼────┐                   │
│              │      db.php          │                    │
│              │  (PDO-Verbindung)    │                    │
│              └────────┬─────────────┘                    │
│                       │                                  │
│              ┌────────▼─────────────┐  ┌─────────────┐  │
│              │   services/          │  │ uploads/    │  │
│              │   AiService.php      │──│ fotos/      │  │
│              └────────┬─────────────┘  └─────────────┘  │
│                       │                                  │
└───────────────────────┼──────────────────────────────────┘
                        │ HTTPS
                        ▼
               ┌────────────────┐
               │  OpenAI API    │
               │  (GPT-4o)      │
               └────────────────┘
                        
        │ MySQL Protocol
        ▼
┌─────────────────────────────────────────────────────────┐
│              MySQL 8.0 (IONOS Hosting)                    │
│                                                          │
│  benutzer │ projekte │ gebaeude │ etagen │ raeume │     │
│  fenster  │ fluegel  │ fotos    │ record_locks │         │
│  audit_log│ password_resets │ sessions │                 │
└─────────────────────────────────────────────────────────┘
```

---

*Erstellt: 2026-07-26*
