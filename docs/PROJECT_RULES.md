# Projektregeln

## Verbindlicher Master

`Chris-GD13/sv-netzwerk` auf `main` ist die einzige technische Quelle der Wahrheit. Produktiver Code, Inhalte, Workflows und Projektdokumentation werden dort versioniert. Lokale oder serverseitige Dateien ohne Repository-Bezug gelten nicht als freigegebener Stand.

## Rollen

- Christian: Product Owner, fachliche Priorisierung und Freigabe strategischer Entscheidungen
- ChatGPT: Architektur, Projektsteuerung und Übersetzung der Produktziele in umsetzbare Arbeitspakete
- Codex: Entwicklungsumgebung, Repository-Analyse, Implementierung, Build, Prüfung, Commit, Push und technische Live-Verifikation

Fachliche, rechtliche oder irreversible Entscheidungen werden nicht ohne Product-Owner-Freigabe getroffen.

## Entwicklungsgrundsätze

1. Bestehende funktionsfähige Inhalte und Routen bleiben erhalten.
2. Neue Komponenten werden nur angelegt, wenn sie unmittelbar verwendet werden.
3. Gemeinsame Datenquellen versorgen Navigation, Karten, Detailseiten, Suche und Verknüpfungen.
4. Experten bleiben ein eigenständiger Hauptbereich. Das Netzwerk beschreibt Organisation und Konzept; Experten stellen die Menschen dar.
5. SVOS wird schrittweise als Fach-, Wissens- und Arbeitsplattform aufgebaut. Keine unsicheren Backend- oder Uploadfunktionen vortäuschen.
6. Accessibility, TypeScript-Sicherheit, SEO, interne Links und mobile Bedienbarkeit sind Abnahmekriterien.
7. Messwerte, Normen, Urteile, Schadenfälle und Qualifikationen dürfen nicht erfunden werden.

## Deployment

GitHub Actions baut das Astro-Projekt und überträgt den Inhalt von `dist/` direkt nach `/sv-netzwerk`. `/sv-netzwerk/dist/` ist unzulässig. `deploy-version.txt` muss live die Commit-ID des Workflows ausweisen.

## Fachwissen

Für jeden Veröffentlichungstag existiert genau ein gekennzeichneter Pflichtbeitrag. Wochenendbeiträge sind C-Beiträge mit 300–500 Wörtern, Montag/Mittwoch/Freitag B-Beiträge mit 800–1.200 Wörtern, Dienstag/Donnerstag A-Beiträge mit 1.500–2.500 Wörtern. Automatische Inhaltserzeugung oder Veröffentlichung findet nicht statt.
