# ADR-005: Täglicher Fachwissensstandard

- Status: akzeptiert
- Datum: 14.07.2026

## Entscheidung

Jeder Veröffentlichungstag erhält genau einen gekennzeichneten Pflichtbeitrag. Umfang: Wochenende C mit 300–500 Wörtern; Montag, Mittwoch und Freitag B mit 800–1.200 Wörtern; Dienstag und Donnerstag A mit 1.500–2.500 Wörtern.

## Technische Kontrolle

Das Repository prüft Datum, Kategorie, Wortumfang, Pflichtfelder, Slug-Eindeutigkeit, interne Links sowie Integration in Suche und Sitemap. Der Build schlägt bei einem fehlenden Tagesbeitrag fehl.

## Grenze

Automatisierung erzeugt oder veröffentlicht keine Fachinhalte. Redaktionelle und fachliche Verantwortung bleibt beim Product Owner beziehungsweise benannten Autor.
