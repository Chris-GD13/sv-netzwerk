# Release Notes – SV-Netzwerk v5.0.1

## Informationsarchitektur

Version 5.0.1 führt alle Inhalte über eine gemeinsame Navigation zusammen. Die Hauptbereiche Leistungen, Schadenarten, Fachwissen, Praxisfälle, Downloads, Netzwerk, SVOS, Über uns und Kontakt sind auf jeder gerenderten Seite erreichbar.

### Navigation und Header

- drei Desktop-Mega-Menüs mit fachlich gegliederten Zielen
- mobile Off-Canvas-Navigation mit aufklappbaren Untermenüs
- Schließen über ESC, Overlay, Links und Schließen-Schaltfläche
- Fokusführung, Fokusfalle, Fokus-Rückgabe und Scroll-Lock
- Sticky Header mit kompaktem Scroll-Zustand, Suche und CTA „Schaden melden“
- aktive Menümarkierung und sichtbare Tastaturfokusse

### Seiten und Inhalte

- neue Schadenarten-Übersicht für neun relevante Schadenbilder
- neue Seite Über uns mit Arbeitsprinzipien, Qualität und Standort Aalen
- überarbeitete Seiten für Leistungen, Netzwerk und SVOS
- Fachwissensbereich um Normen und Rechtsprechung sowie Wissen in 180 Sekunden ergänzt
- bestehende Praxisfälle, Downloads, Fachartikel und funktionierende Detailrouten bleiben erhalten

### Gemeinsame Oberfläche

- Startseite und Unterseiten verwenden denselben Header, dieselbe Navigation und denselben Footer
- Footer mit Leistungen, Wissen, SVOS, Netzwerk, Kontakt, Rechtlichem, Social Media, Zertifizierungshinweis und Standort Aalen
- doppelte Homepage-Navigationskomponenten entfernt

### Deployment

Der Build-Inhalt wird direkt nach `/sv-netzwerk` übertragen. `deploy-version.txt` weist Commit, UTC-Buildzeit und `Homepage-v5` aus.
