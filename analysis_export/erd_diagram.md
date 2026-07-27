# Entity-Relationship-Diagramm

## Diagramm (Mermaid)

```mermaid
erDiagram
    projekte ||--o{ gebaeude : "hat"
    gebaeude ||--o{ etagen : "hat"
    etagen ||--o{ raeume : "hat"
    raeume ||--o{ fenster : "hat"
    fenster ||--o{ fluegel : "hat"
    fenster ||--o{ fotos : "hat"
    fenster ||--o{ dokumente : "hat"
    benutzer ||--o{ audit_log : "erstellt"
    benutzer ||--o{ record_locks : "hält"
    benutzer ||--o{ sessions : "hat"
    benutzer ||--o{ password_resets : "anfordert"

    projekte {
        int id PK
        varchar name
        varchar auftraggeber
        varchar standort
        varchar status
        timestamp created_at
    }

    gebaeude {
        int id PK
        int projekt_id FK
        varchar name
        varchar kuerzel
        tinyint is_deleted
        timestamp created_at
    }

    etagen {
        int id PK
        int gebaeude_id FK
        varchar name
        int geschoss
        timestamp created_at
    }

    raeume {
        int id PK
        int etage_id FK
        varchar name
        varchar raumnummer
        varchar nutzung
        text bemerkung
        timestamp created_at
    }

    fenster {
        int id PK
        int raum_id FK
        varchar fensternummer
        varchar fenstertyp
        varchar hersteller
        varchar beschlagsystem
        int baujahr
        int breite_mm
        int hoehe_mm
        varchar glastyp
        varchar lage
        varchar status
        text bemerkung
        int version
        timestamp created_at
        timestamp updated_at
    }

    fluegel {
        int id PK
        int fenster_id FK
        int nummer
        varchar oeffnungsart
        int breite_mm
        int hoehe_mm
        varchar beschlag_zustand
        varchar dichtung_zustand
        tinyint geprueft
        date geprueft_am
        int geprueft_von FK
        text bemerkung
        timestamp created_at
        timestamp updated_at
    }

    fotos {
        int id PK
        int fenster_id FK
        varchar dateiname
        varchar pfad
        varchar typ
        date aufnahmedatum
        int groesse_kb
        timestamp created_at
    }

    benutzer {
        int id PK
        varchar name
        varchar email UK
        varchar passwort_hash
        varchar rolle
        tinyint is_active
        datetime last_login
        timestamp created_at
    }

    audit_log {
        int id PK
        int benutzer_id FK
        varchar aktion
        varchar entitaet
        int entitaet_id
        text alte_daten
        text neue_daten
        text details
        timestamp zeitpunkt
    }

    record_locks {
        int id PK
        varchar entitaet
        int entitaet_id
        int locked_by FK
        timestamp locked_at
        timestamp expires_at
    }

    sessions {
        varchar id PK
        int benutzer_id FK
        text data
        timestamp created_at
        timestamp expires_at
    }

    password_resets {
        int id PK
        int benutzer_id FK
        varchar token
        timestamp expires_at
        timestamp created_at
    }

    dokumente {
        int id PK
        int fenster_id FK
        varchar dateiname
        varchar typ
        int groesse_kb
        int hochgeladen_von FK
        timestamp created_at
    }
```

## Beziehungen (Kardinalitäten)

| Von | Zu | Beziehung | FK-Feld | Kaskade |
|-----|-----|-----------|---------|---------|
| projekte | gebaeude | 1:n | gebaeude.projekt_id | Soft-Delete |
| gebaeude | etagen | 1:n | etagen.gebaeude_id | CASCADE |
| etagen | raeume | 1:n | raeume.etage_id | CASCADE |
| raeume | fenster | 1:n | fenster.raum_id | CASCADE |
| fenster | fluegel | 1:n | fluegel.fenster_id | CASCADE |
| fenster | fotos | 1:n | fotos.fenster_id | CASCADE |
| fenster | dokumente | 1:n | dokumente.fenster_id | SET NULL |
| benutzer | audit_log | 1:n | audit_log.benutzer_id | SET NULL |
| benutzer | record_locks | 1:n | record_locks.locked_by | CASCADE |
| benutzer | fluegel | 1:n | fluegel.geprueft_von | SET NULL |

## Hierarchie-Tiefe

```
Projekt (1)
  └── Gebäude (n)
        └── Etage (n)
              └── Raum (n)
                    └── Fenster (n)
                          ├── Flügel (n)
                          ├── Fotos (n)
                          └── Dokumente (n)
```

## Indizes

| Tabelle | Index | Spalten | Typ |
|---------|-------|---------|-----|
| benutzer | email | email | UNIQUE |
| fenster | idx_raum | raum_id | INDEX |
| fenster | idx_status | status | INDEX |
| fenster | idx_fensternummer | fensternummer | INDEX |
| fluegel | idx_fenster | fenster_id | INDEX |
| fotos | idx_fenster | fenster_id | INDEX |
| raeume | idx_etage | etage_id | INDEX |
| etagen | idx_gebaeude | gebaeude_id | INDEX |
| audit_log | idx_zeitpunkt | zeitpunkt | INDEX |
| audit_log | idx_entitaet | entitaet, entitaet_id | INDEX |
| record_locks | idx_lock | entitaet, entitaet_id | UNIQUE |

---

*Erstellt: 2025-07-26*
