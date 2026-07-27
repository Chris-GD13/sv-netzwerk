# Audit-Bericht – Fensterbeschlagsprüfung BMVg Bonn

## Zusammenfassung für den externen Gutachter

Dieses Dokument liefert eine vollständige Bewertung der Webanwendung
"Fensterbeschlagsprüfung BMVg Bonn" hinsichtlich Architektur, Sicherheit,
Funktionalität, Wartbarkeit und Erweiterbarkeit.

---

## 1. Architektur

### Stärken

| # | Aspekt | Bewertung |
|---|--------|-----------|
| 1 | **Klare Trennung Frontend/Backend** | API-basiert (JSON over HTTP) |
| 2 | **Einfache Deployment-Pipeline** | GitHub Actions → SFTP → IONOS |
| 3 | **Statisches Frontend** | Astro generiert HTML, SPA übernimmt nach Laden |
| 4 | **Zentraler KI-Service** | Sauber gekapselt, erweiterbar |
| 5 | **Datenbank-Schema** | Gut normalisiert, korrekte Fremdschlüssel |
| 6 | **Rollenbasierte Zugriffskontrolle** | 6 Rollen, konsequent geprüft |
| 7 | **Record Locking** | Verhindert gleichzeitige Bearbeitung |
| 8 | **Soft-Delete** | Keine Datenverluste bei Löschoperationen |
| 9 | **Audit-Log** | Alle Änderungen nachvollziehbar |
| 10 | **Offline-Fähigkeit** | IndexedDB für Entwürfe vorbereitet |

### Schwächen

| # | Aspekt | Schwere | Empfehlung |
|---|--------|---------|------------|
| 1 | **Monolithische client.ts** (2900 Zeilen) | Mittel | In Module aufteilen |
| 2 | **Kein PHP-Framework** | Niedrig | Akzeptabel für Projektgröße |
| 3 | **Kein Autoloader** | Niedrig | PSR-4 einführen |
| 4 | **Session-basierte Auth** | Mittel | Für Web OK, für Mobile API-Token nötig |
| 5 | **Keine Unit-Tests** | Hoch | PHPUnit + Vitest einführen |
| 6 | **Kein API-Versionierung** | Niedrig | v1/ Prefix empfohlen |
| 7 | **Keine E-Mail-Funktionalität** | Mittel | Passwort-Reset, Benachrichtigungen fehlen |
| 8 | **Einzel-Projekt fixiert** | Niedrig | Schema Multi-Projekt-fähig, UI nicht |

---

## 2. Sicherheitsbewertung

### Positiv

- ✅ **Argon2ID** für Passwörter (Stand der Technik)
- ✅ **Prepared Statements** durchgehend (kein SQL-Injection-Risiko)
- ✅ **HTML-Escaping** im Frontend (XSS-Schutz)
- ✅ **SameSite=Strict** Cookie (CSRF-Basisschutz)
- ✅ **HttpOnly + Secure** Session-Cookie
- ✅ **MIME-Type Whitelist** für Uploads
- ✅ **Rollenprüfung** in jedem Backend-Endpoint
- ✅ **Keine API-Keys im Quellcode**

### Verbesserungsbedarf

- ⚠️ Kein expliziter CSRF-Token (SameSite reicht für moderne Browser)
- ⚠️ Kein Rate-Limiting (Brute-Force-Angriff auf Login möglich)
- ⚠️ Keine Session-Regeneration bei Login
- ⚠️ Kein Content-Security-Policy Header
- ⚠️ Keine HSTS-Konfiguration (liegt bei IONOS)

### Risikobewertung

| Risiko | Wahrscheinlichkeit | Auswirkung | Maßnahme |
|--------|-------------------|------------|----------|
| Brute-Force Login | Mittel | Hoch | Rate-Limiting implementieren |
| Session Fixation | Niedrig | Mittel | session_regenerate_id(true) |
| XSS durch fehlenden Escape | Niedrig | Hoch | CSP-Header + Code-Review |
| Unbefugter Datenzugriff | Sehr niedrig | Hoch | Rollenprüfung vorhanden |
| SQL-Injection | Minimal | Kritisch | Prepared Statements vorhanden |

**Gesamtrisiko:** GERING bis MITTEL (für Intranet-Anwendung akzeptabel)

---

## 3. Funktionalität

### Vollständig implementiert

- [x] Login/Logout mit Session
- [x] Dashboard mit Live-Statistiken
- [x] Gebäude-Hierarchie (5 Ebenen)
- [x] CRUD für alle Entitäten
- [x] Prüfformular mit Feldvalidierung
- [x] Foto-Upload und -Verwaltung
- [x] Record Locking (15 Min.)
- [x] CSV-Export mit Filtern
- [x] Benutzerverwaltung (Admin)
- [x] KI-Dokumentenimport (GPT-4o Vision)
- [x] Aktionsmenüs (Bearbeiten/Löschen)
- [x] Rollenbasierte Sichtbarkeit
- [x] Audit-Log (Änderungsverfolgung)
- [x] Berechnungsmodul (Glasgewicht)

### Teilweise implementiert

- [~] Offline-Modus (Modul vorhanden, nicht vollständig integriert)
- [~] Passwort-Reset (DB-Tabelle vorhanden, kein Frontend)
- [~] Multi-Projekt (Schema-Support, UI fixiert auf Projekt 1)

### Nicht implementiert

- [ ] E-Mail-Benachrichtigungen
- [ ] 2-Faktor-Authentifizierung
- [ ] Mobile-optimierte Ansicht (responsive, aber keine native App)
- [ ] Automatische Prüfprotokolle (KI-Service vorbereitet)
- [ ] Versionierung von Prüfdaten (nur version-Counter)
- [ ] Archivierung abgeschlossener Projekte

---

## 4. Benutzerführung (UX)

### Stärken
- Klare Hierarchie-Navigation (Gebäude → Etagen → Räume → Fenster → Flügel)
- Fortschrittsbalken auf allen Ebenen
- Farbcodierte Status-Badges
- Breadcrumb-Navigation
- Kontextabhängige Aktionsmenüs
- Drag&Drop für KI-Import

### Schwächen
- Keine Suche über alle Entitäten hinweg
- Kein "Zurück"-Button in SPA (nur Browser-Back)
- Große Tabellen ohne Pagination
- Kein Dark-Mode
- Keine Tastaturnavigation für Barrierefreiheit
- Formular-Felder ohne inline-Validierung

---

## 5. Wartbarkeit

| Aspekt | Bewertung | Begründung |
|--------|-----------|------------|
| Code-Lesbarkeit | B+ | Gut strukturiert, deutsche Kommentare |
| Modularität Backend | A- | Jeder Endpoint in eigener Datei |
| Modularität Frontend | C+ | Alles in einer Datei (client.ts) |
| Dokumentation | B | schema.sql kommentiert, TESTDATA.md vorhanden |
| Deployment | A | Automatisiert via GitHub Actions |
| Fehlerbehandlung | B | Try/Catch vorhanden, aber kein strukturiertes Logging |
| Testabdeckung | D | Keine automatisierten Tests |

---

## 6. Erweiterungsmöglichkeiten

### Kurzfristig (< 1 Monat)
1. Rate-Limiting für Login
2. CSRF-Token
3. Session-Regeneration
4. Content-Security-Policy
5. Globale Suchfunktion

### Mittelfristig (1-3 Monate)
1. client.ts in Module aufteilen
2. Unit-Tests (PHPUnit + Vitest)
3. E-Mail-System (Benachrichtigungen, Passwort-Reset)
4. Vollständiger Offline-Modus (Service Worker)
5. KI-Mängelerkennung aus Fotos

### Langfristig (3-12 Monate)
1. Native Mobile App (oder PWA)
2. Multi-Mandanten-Fähigkeit
3. Automatische Prüfprotokolle (KI)
4. Dashboard-Konfiguration pro Benutzer
5. Schnittstelle zu Facility-Management-Systemen
6. PDF-Berichtsgenerator
7. Barrierefreiheit (WCAG 2.1 AA)

---

## 7. Technische Schulden

| Prio | Schuld | Aufwand | Risiko bei Nichtbehandlung |
|------|--------|---------|---------------------------|
| 1 | Keine Tests | 5 Tage | Regression bei Änderungen |
| 2 | Monolithisches Frontend | 3 Tage | Wartungsaufwand steigt |
| 3 | Kein strukturiertes Logging | 1 Tag | Fehleranalyse erschwert |
| 4 | Kein Backup-Konzept | 1 Tag | Datenverlust möglich |
| 5 | Kein Monitoring | 1 Tag | Ausfälle unbemerkt |
| 6 | Keine API-Docs (OpenAPI) | 2 Tage | Drittanbieter-Anbindung erschwert |

---

## 8. Gesamtbewertung

| Kriterium | Note | Kommentar |
|-----------|------|-----------|
| Funktionsumfang | A- | Alle Kernfunktionen vorhanden |
| Architektur | B+ | Solide, aber monolithisches Frontend |
| Sicherheit | B | Grundschutz gut, Härtung empfohlen |
| Benutzerführung | B | Funktional, UX-Feinschliff nötig |
| Wartbarkeit | B- | Backend gut, Frontend zu monolithisch |
| Erweiterbarkeit | A- | KI-Service und Schema zukunftssicher |
| Performance | B+ | SPA mit schnellen API-Calls |
| Deployment | A | Vollautomatisiert |
| Dokumentation | B | Vorhanden, könnte umfangreicher sein |
| Testabdeckung | D | Größte Schwachstelle |

### Gesamtnote: **B+ (Gut)**

Die Anwendung ist funktional vollständig und für den vorgesehenen Zweck
(Fensterbeschlagsprüfung durch einen kleinen Kreis von Fachleuten)
geeignet. Die Sicherheitsgrundlagen sind solide. Hauptempfehlungen:

1. **Sofort:** Rate-Limiting + Session-Regeneration
2. **Kurzfristig:** Unit-Tests einführen
3. **Mittelfristig:** Frontend modularisieren

---

*Erstellt: 2026-07-26*
*Dieses Dokument dient der externen technischen Bewertung.*
