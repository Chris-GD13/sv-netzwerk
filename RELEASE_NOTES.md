# Release Notes – SV-Netzwerk v1.6.1 Knowledge Engine

Dieses Release macht die in v1.6.0 eingeführte Content-Architektur erstmals als automatisierte Fachwissensoberfläche nutzbar. Veröffentlichte Knowledge-Einträge erzeugen Übersichten, Detailseiten, Kategorien, Tags, Lesedauer und verwandte Beiträge.

# Release Notes – SV-Netzwerk v1.6.0 SVOS Foundation

## Ziel
Version 1.6.0 schafft die verbindliche Content- und Datenarchitektur des SV Operating System (SVOS). Inhalte werden nicht mehr ausschließlich in statischen Datenlisten geführt, sondern können über validierte Astro Content Collections verarbeitet werden.

## Neue Collections
- `knowledge`: Fachartikel und technische Wissensbausteine
- `downloads`: Checklisten, Formulare und Arbeitshilfen
- `practiceCases`: anonymisierte Praxisfälle
- `authors`: Autoren- und Fachprofile

## Technische Grundlagen
- zentrale Veröffentlichungssystematik mit `draft`, `review`, `published` und `archived`
- einheitliche SEO-Metadaten
- zentrale Typdefinitionen
- wiederverwendbare Content-Utilities
- verbindliche Routing-Helfer
- Statusseite `/svos/`

## Kompatibilität
Die vorhandene Fachwissensbibliothek und die bestehenden statischen Datenquellen bleiben erhalten. Die Migration auf die neuen Collections erfolgt schrittweise in den Folgeversionen.
