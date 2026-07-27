# Rollenmodell – Fensterbeschlagsprüfung BMVg Bonn

## Rollenhierarchie

```
Administrator (höchste Berechtigung)
  │
  ▼
Projektleiter
  │
  ▼
Sachverständiger
  │
  ▼
Prüfer
  │
  ▼
Auswertung (nur Lesen + Export)
  │
  ▼
Gast (minimale Berechtigung)
```

---

## Rollen im Detail

### 1. Administrator (`administrator`)

**Beschreibung:** Vollzugriff auf alle Funktionen. Systemadministration.

| Bereich | Berechtigung |
|---------|-------------|
| Benutzerverwaltung | Anlegen, Bearbeiten, Deaktivieren, Passwort setzen |
| Gebäude/Etagen/Räume | Anlegen, Bearbeiten, Löschen |
| Fenster/Flügel | Anlegen, Bearbeiten, Löschen, Prüfen |
| Fotos | Hochladen, Löschen |
| Export | Alle Formate |
| KI-Dokumentenimport | Vollzugriff |
| Dashboard | Vollständig |
| Auswertung | Vollständig |
| Datensatzsperren | Kann fremde Sperren aufheben |
| Audit-Log | Einsehen |

### 2. Projektleiter (`projektleiter`)

**Beschreibung:** Leitet das Prüfprojekt. Kann Strukturen anlegen und bearbeiten.

| Bereich | Berechtigung |
|---------|-------------|
| Benutzerverwaltung | ✗ Kein Zugriff |
| Gebäude/Etagen/Räume | Anlegen, Bearbeiten (kein Löschen) |
| Fenster/Flügel | Anlegen, Bearbeiten, Prüfen |
| Fotos | Hochladen, Löschen |
| Export | Alle Formate |
| KI-Dokumentenimport | ✗ Kein Zugriff |
| Dashboard | Vollständig |
| Auswertung | Vollständig |

### 3. Sachverständiger (`sachverstaendiger`)

**Beschreibung:** Führt die eigentliche Fensterbeschlagsprüfung durch.

| Bereich | Berechtigung |
|---------|-------------|
| Benutzerverwaltung | ✗ Kein Zugriff |
| Gebäude/Etagen/Räume | Anlegen, Bearbeiten (kein Löschen) |
| Fenster/Flügel | Anlegen, Bearbeiten, Prüfen, Freigeben |
| Fotos | Hochladen, Löschen |
| Export | Alle Formate |
| KI-Dokumentenimport | ✗ Kein Zugriff |
| Dashboard | Vollständig |
| Auswertung | Vollständig |
| Spezialfunktion | Fenster freigeben (Release) |

### 4. Prüfer (`pruefer`)

**Beschreibung:** Assistiert bei der Prüfung. Kann Daten erfassen.

| Bereich | Berechtigung |
|---------|-------------|
| Benutzerverwaltung | ✗ Kein Zugriff |
| Gebäude/Etagen/Räume | Anlegen, Bearbeiten (kein Löschen) |
| Fenster/Flügel | Anlegen, Bearbeiten, Prüfen |
| Fotos | Hochladen |
| Export | Alle Formate |
| KI-Dokumentenimport | ✓ Vollzugriff |
| Dashboard | Vollständig |
| Auswertung | Vollständig |

### 5. Auswertung (`auswertung`)

**Beschreibung:** Nur-Lese-Zugriff für Auswertungen und Exports.

| Bereich | Berechtigung |
|---------|-------------|
| Benutzerverwaltung | ✗ |
| Gebäude/Etagen/Räume | Nur Ansicht |
| Fenster/Flügel | Nur Ansicht |
| Fotos | Nur Ansicht |
| Export | ✓ |
| KI-Dokumentenimport | ✗ |
| Dashboard | Nur Ansicht |
| Auswertung | ✓ |

### 6. Gast (`gast`)

**Beschreibung:** Minimaler Zugriff. Kann nur das Dashboard sehen.

| Bereich | Berechtigung |
|---------|-------------|
| Benutzerverwaltung | ✗ |
| Gebäude/Etagen/Räume | ✗ |
| Fenster/Flügel | ✗ |
| Fotos | ✗ |
| Export | ✗ |
| KI-Dokumentenimport | ✗ |
| Dashboard | Eingeschränkt |
| Auswertung | ✗ |

---

## Berechtigungsmatrix (Kompakt)

| Funktion | Admin | PL | SV | Prüfer | Auswertung | Gast |
|----------|:-----:|:--:|:--:|:------:|:----------:|:----:|
| Login | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Dashboard | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Gebäude ansehen | ✓ | ✓ | ✓ | ✓ | ✓ | – |
| Gebäude anlegen | ✓ | ✓ | ✓ | ✓ | – | – |
| Gebäude bearbeiten | ✓ | ✓ | ✓ | ✓ | – | – |
| Gebäude löschen | ✓ | – | – | – | – | – |
| Fenster prüfen | ✓ | ✓ | ✓ | ✓ | – | – |
| Fenster freigeben | ✓ | ✓ | ✓ | – | – | – |
| Fotos hochladen | ✓ | ✓ | ✓ | ✓ | – | – |
| Fotos löschen | ✓ | ✓ | ✓ | – | – | – |
| Export | ✓ | ✓ | ✓ | ✓ | ✓ | – |
| KI-Import | ✓ | – | – | ✓ | – | – |
| Benutzer verwalten | ✓ | – | – | – | – | – |
| Audit-Log einsehen | ✓ | ✓ | ✓ | – | – | – |

---

## Technische Umsetzung

- Rolle wird in `users.role` als ENUM gespeichert
- Frontend: `canEdit()` Funktion prüft Admin/PL/SV/Prüfer
- Frontend: `isAdminRole()` prüft nur Admin
- Backend: `requireRole($user, ['administrator', ...])` in jedem Endpoint
- KI-Import: `in_array($user['role'], ['administrator', 'pruefer'])`
- Delete-Operationen: `requireRole($user, ['administrator'])`
