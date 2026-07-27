# Sicherheitsübersicht – Fensterbeschlagsprüfung BMVg Bonn

## 1. Authentifizierung

| Aspekt | Implementierung | Bewertung |
|--------|----------------|-----------|
| Login-Mechanismus | E-Mail + Passwort | Standard |
| Passwort-Hashing | Argon2ID (PHP password_hash) | ✅ Sehr gut |
| Session-Management | PHP-Session mit sicheren Cookie-Flags | ✅ Gut |
| Brute-Force-Schutz | ❌ Nicht implementiert | ⚠️ Risiko |
| 2FA | ❌ Nicht implementiert | ⚠️ Optional |
| Account-Lockout | ❌ Nicht implementiert | ⚠️ Empfehlung |

### Passwort-Speicherung
```php
password_hash($password, PASSWORD_ARGON2ID)
```
- Algorithmus: Argon2ID (aktueller Stand der Technik)
- Automatisches Salting durch PHP
- Keine Klartextpasswörter in der Datenbank

---

## 2. Session-Verwaltung

| Aspekt | Einstellung | Bewertung |
|--------|-------------|-----------|
| Cookie: HttpOnly | ✅ Ja | Kein JS-Zugriff |
| Cookie: Secure | ✅ Ja (bei HTTPS) | Nur HTTPS |
| Cookie: SameSite | Strict | ✅ CSRF-Basisschutz |
| Session-Timeout | PHP-Default (24 Min. inaktiv) | Standard |
| Session-Regeneration | ❌ Nicht bei Login | ⚠️ Session Fixation möglich |

### Implementierung (config.php)
```php
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => isset($_SERVER['HTTPS']),
    'httponly'  => true,
    'samesite' => 'Strict',
]);
```

---

## 3. Autorisierung / Rollenprüfung

| Aspekt | Implementierung | Bewertung |
|--------|----------------|-----------|
| Rollenmodell | 6 Rollen (ENUM in DB) | ✅ Klar definiert |
| Backend-Prüfung | `requireRole()` in jedem Endpoint | ✅ Gut |
| Frontend-Prüfung | `canEdit()`, `isAdminRole()` | Zusätzlich (nicht alleinig) |
| Privilege Escalation | Jeder Endpoint prüft eigenständig | ✅ Defense in Depth |

### Schwachstellen
- Frontend-Rollenprüfung kann umgangen werden (nur UX-Einschränkung)
- Backend prüft korrekt → kein Sicherheitsproblem

---

## 4. SQL-Injection-Schutz

| Aspekt | Implementierung | Bewertung |
|--------|----------------|-----------|
| Prepared Statements | ✅ Durchgehend PDO mit Named Parameters | ✅ Sehr gut |
| Emulate Prepares | `false` (echte prepared statements) | ✅ |
| Error Mode | `ERRMODE_EXCEPTION` | ✅ |

### Beispiel (typisch für alle Queries)
```php
$stmt = db()->prepare('SELECT * FROM users WHERE email = :email');
$stmt->execute([':email' => $email]);
```

**Bewertung:** Kein bekanntes SQL-Injection-Risiko bei korrekter Anwendung.

---

## 5. Cross-Site Scripting (XSS)

| Aspekt | Implementierung | Bewertung |
|--------|----------------|-----------|
| Output-Encoding | `escapeHtml()` im Frontend | ✅ Für HTML-Kontext |
| JSON-Output Backend | `json_encode()` (sicher) | ✅ |
| Content-Type Header | `application/json` für API | ✅ |
| CSP-Header | ❌ Nicht gesetzt | ⚠️ Empfehlung |

### Frontend escapeHtml()
```typescript
function escapeHtml(value: string) {
  return value.replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
```

### Risiko
- `innerHTML` wird extensiv genutzt (SPA-Pattern)
- Alle Benutzereingaben werden durch `escapeHtml()` geschützt
- ⚠️ Ein vergessener Aufruf könnte XSS ermöglichen

---

## 6. Cross-Site Request Forgery (CSRF)

| Aspekt | Implementierung | Bewertung |
|--------|----------------|-----------|
| CSRF-Token | ❌ Nicht implementiert | ⚠️ |
| SameSite Cookie | Strict | ✅ Basisschutz |
| Origin-Prüfung | ❌ Nicht implementiert | ⚠️ |

### Bewertung
- `SameSite=Strict` verhindert die meisten CSRF-Angriffe
- Für ein Sicherheits-Audit wird ein expliziter CSRF-Token empfohlen
- Risiko: Gering durch SameSite, aber nicht Zero

---

## 7. Datei-Upload-Schutz

| Aspekt | Implementierung | Bewertung |
|--------|----------------|-----------|
| MIME-Typ-Prüfung | ✅ `mime_content_type()` | ✅ |
| Dateigröße | ✅ 10 MB (Fotos), 20 MB (KI-Import) | ✅ |
| Erlaubte Typen | Whitelist (JPG/PNG/PDF/etc.) | ✅ |
| Dateiname | Nicht direkt verwendet, generierter Pfad | ✅ |
| Ausführbarkeit | .htaccess verhindert PHP-Ausführung in photos/ | ✅ |
| Path Traversal | Dateien in festem Verzeichnis gespeichert | ✅ |

---

## 8. HTTP-Sicherheitsheader

| Header | Gesetzt | Bewertung |
|--------|---------|-----------|
| X-Content-Type-Options: nosniff | ✅ | Gut |
| X-Frame-Options: DENY | ✅ | Gut |
| Cache-Control: no-store | ✅ | Gut |
| Strict-Transport-Security | ❌ (IONOS-Konfiguration) | ⚠️ |
| Content-Security-Policy | ❌ | ⚠️ Empfehlung |
| Permissions-Policy | ❌ | Niedrig |

---

## 9. Verschlüsselung

| Aspekt | Status |
|--------|--------|
| HTTPS (TLS) | ✅ Über IONOS SSL-Zertifikat |
| Datenbank-Verbindung | Unverschlüsselt (localhost auf IONOS) |
| Passwörter in .env | Klartext auf Server (Dateisystem-Schutz) |
| API-Key | Nur in .env und GitHub Secrets |

---

## 10. Record Locking (Gleichzeitige Bearbeitung)

| Aspekt | Implementierung |
|--------|----------------|
| Sperr-Mechanismus | Pessimistisch (15 Min. Timeout) |
| Sperre aufheben | Owner oder Admin |
| Ablauf | Automatisch nach 15 Minuten |
| Schutz vor Race Conditions | ✅ DB-Level (PRIMARY KEY auf window_id) |

---

## 11. Datenlöschung

| Aspekt | Implementierung |
|--------|----------------|
| Fenster | Soft-Delete (deleted_at) |
| Flügel | Soft-Delete (deleted_at) |
| Fotos | Soft-Delete (deleted_at) + Datei bleibt |
| Gebäude/Etagen/Räume | Hard-Delete mit CASCADE |
| Benutzer | Deaktivierung (is_active=0) |

---

## 12. Gesamtbewertung

| Kategorie | Note | Kommentar |
|-----------|------|-----------|
| Authentifizierung | B+ | Argon2ID gut, Brute-Force-Schutz fehlt |
| Autorisierung | A- | Konsequent, alle Endpoints geprüft |
| SQL-Injection | A | Durchgehend Prepared Statements |
| XSS | B | escapeHtml vorhanden, CSP fehlt |
| CSRF | B- | SameSite=Strict, aber kein Token |
| Upload-Sicherheit | A- | Whitelist + MIME + Größenlimit |
| Transport-Sicherheit | A | HTTPS erzwungen |
| Session-Management | B+ | Sichere Flags, keine Regeneration |

### Empfohlene Sofortmaßnahmen (Prio 1)
1. Rate-Limiting für Login-Endpoint (max. 5 Versuche/Minute)
2. Session-ID bei Login regenerieren (`session_regenerate_id(true)`)
3. CSRF-Token für state-changing Requests
4. Content-Security-Policy Header

### Empfohlene Maßnahmen (Prio 2)
5. Strict-Transport-Security Header
6. Account-Lockout nach 10 Fehlversuchen
7. Logging fehlgeschlagener Login-Versuche
8. Automatisierte Sicherheitstests
