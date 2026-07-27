# Navigationsbaum – Fensterbeschlagsprüfung BMVg Bonn

## Hauptnavigation (Header)

```
┌─────────────────────────────────────────────────────────────────┐
│  Dashboard │ Gebäude │ Auswertung │ Export │ 📄 KI-Import* │    │
│  Benutzerverwaltung** │                              [Abmelden] │
└─────────────────────────────────────────────────────────────────┘

 * nur für Administrator + Prüfer sichtbar
** nur für Administrator sichtbar
```

---

## Vollständiger Navigationsbaum

```
/intern/
├── Login (/intern/login/)
│
└── Fensterbeschlagsprüfung BMVg Bonn (/intern/fensterpruefung-bonn/)
    │
    ├── Dashboard (Startseite nach Login)
    │   ├── Statistik-Übersicht (Gesamt, Nicht begonnen, In Arbeit, Abgeschlossen)
    │   ├── Mängel-Statistik (Mangelhaft, Dringend, Spezialpüfung, Nicht zugänglich)
    │   ├── Heutige Aktivität
    │   ├── Prüfer-Übersicht
    │   └── Letzte Änderungen
    │
    ├── Gebäude (/intern/fensterpruefung-bonn/gebaeude/)
    │   ├── [+ Gebäude anlegen]
    │   │
    │   └── Gebäude X
    │       ├── [⋮ Bearbeiten | Löschen]
    │       │
    │       ├── Etagen (?building={id})
    │       │   ├── [+ Etage anlegen]
    │       │   │
    │       │   └── Etage Y
    │       │       ├── [⋮ Bearbeiten | Löschen]
    │       │       │
    │       │       ├── Räume (?floor={id})
    │       │       │   ├── [+ Raum anlegen]
    │       │       │   │
    │       │       │   └── Raum Z
    │       │       │       ├── [⋮ Bearbeiten | Löschen]
    │       │       │       │
    │       │       │       └── Fenster (?room={id})
    │       │       │           ├── [+ Fenster anlegen]
    │       │       │           │
    │       │       │           └── Fenster W
    │       │       │               ├── [⋮ Löschen]
    │       │       │               │
    │       │       │               └── Flügel (/fenster/?window={id})
    │       │       │                   ├── [+ Flügel anlegen]
    │       │       │                   │
    │       │       │                   └── Flügel F
    │       │       │                       └── Prüfformular (/fenster/?sash={id})
    │       │       │                           ├── Beschlagsprüfung
    │       │       │                           ├── Maße & Berechnung
    │       │       │                           ├── Mängeldokumentation
    │       │       │                           ├── Fotodokumentation
    │       │       │                           │   ├── [Foto hochladen]
    │       │       │                           │   └── [Foto löschen]
    │       │       │                           └── Abschluss & Status
    │
    ├── Auswertung (/intern/fensterpruefung-bonn/auswertung/)
    │   ├── Filter (Gebäude, Status, Priorität, Prüfer)
    │   ├── Tabelle aller Fenster
    │   └── → Einzelnes Fenster öffnen
    │
    ├── Export (/intern/fensterpruefung-bonn/export/)
    │   ├── Alle Fenster (CSV)
    │   ├── Nur abgeschlossene
    │   ├── Nur mit Mängeln
    │   └── Nur dringende
    │
    ├── 📄 KI-Import (/intern/fensterpruefung-bonn/import/) *
    │   ├── Datei hochladen (Drag&Drop)
    │   ├── KI-Analyse-Ergebnis
    │   │   ├── Neue Datensätze
    │   │   ├── Ergänzungen
    │   │   ├── Konflikte
    │   │   └── Bereits vorhanden
    │   └── [Ausgewählte übernehmen]
    │
    └── Benutzerverwaltung (/intern/fensterpruefung-bonn/admin/) **
        ├── Benutzerliste
        ├── [+ Benutzer anlegen]
        ├── [Bearbeiten]
        ├── [Passwort ändern]
        └── [Deaktivieren]
```

---

## Breadcrumb-Navigation (kontextbasiert)

```
Dashboard > Gebäude > [Gebäudename] > [Etage] > [Raum] > [Fenster] > [Flügel]
```

Jede Ebene ist klickbar und führt zurück zur jeweiligen Übersicht.

---

## Sichtbarkeit nach Rolle

| Navigation | Admin | PL | SV | Prüfer | Auswertung | Gast |
|-----------|:-----:|:--:|:--:|:------:|:----------:|:----:|
| Dashboard | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Gebäude | ✓ | ✓ | ✓ | ✓ | ✓ | – |
| Auswertung | ✓ | ✓ | ✓ | ✓ | ✓ | – |
| Export | ✓ | ✓ | ✓ | ✓ | ✓ | – |
| 📄 KI-Import | ✓ | – | – | ✓ | – | – |
| Benutzerverwaltung | ✓ | – | – | – | – | – |
| Abmelden | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
