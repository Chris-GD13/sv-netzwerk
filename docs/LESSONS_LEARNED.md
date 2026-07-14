# Lessons Learned

## Renderkette vor Änderung prüfen

Frühere Änderungen blieben unsichtbar, weil nicht immer die tatsächlich gerenderten Dateien betroffen waren. Vor jeder visuellen Änderung werden Seite, Layout, Komponenten, Styles und Buildausgabe gemeinsam geprüft.

## Deploymentziel explizit halten

Automatische Verzeichnissuche erzeugte Unsicherheit. Das von IONOS bestätigte Ziel `/sv-netzwerk` ist fest codiert. Vor und nach dem Upload wird geprüft, dass kein `dist/`-Unterordner entsteht.

## Live-Status braucht einen maschinenlesbaren Nachweis

Ein grüner Workflow allein belegt nicht, dass die Domain den neuen Stand ausliefert. `deploy-version.txt` verbindet Git-Commit, Buildzeit und Live-Abruf.

## Menschen nicht hinter Organisation verstecken

Die Zusammenlegung von Netzwerk und Experten schwächte die strategische Aussage. Organisation und Arbeitsmodell gehören zu Netzwerk; Personen, Qualifikationen und Verantwortung gehören zu Experten.

## Daten vor Darstellung zentralisieren

Statische Bibliothekseinträge ohne reale Route führten zu Inkonsistenzen. Neue Plattformbereiche verwenden gemeinsame Datenquellen und echte Detailrouten.

## Automatisierung darf Qualität prüfen, nicht Inhalte erfinden

Der tägliche Wissenslauf kontrolliert Datum, Umfang, Metadaten und technische Integration. Fachtexte bleiben redaktionell und fachlich verantwortet.
