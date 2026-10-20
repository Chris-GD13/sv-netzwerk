# Eine Akte, eine Bearbeitung

Entwicklungsstand, 20.10.2026. Grundlage: origin/main `8cb620df`, aktuelle Nutzerweisung zur Vereinfachung, führender privater Arbeitsstandard (gelesene Version 4.31) und Portal-Übergabe vom 12.10.2026. Keine internen Richtlinien oder Fallinhalte werden ins Repository kopiert. Die nachträgliche Nutzerfreigabe vom 20.10.2026 erlaubt Merge und Veröffentlichung nach bestandenen Tests.

## Bestandsaufnahme vor der Umsetzung

Die Astro-Fallseite enthält zwei konkurrierende Auftragsoberflächen: `vf-assistant-panel` mit fünf Aktionen, eigenem Textfeld und Budget-/Kontextverwaltung sowie `vf-analysis` mit Textfeld, Promptvorlagen, Upload, optionalem Claims-Import und gespeichertem Gespräch. `case-work-assistant.js` verwendet den servergebundenen Assistant-Zweig von `case-work.php`; `case-work.js` erhält den älteren Bearbeitungsverlauf und dessen Wiederaufnahme. Beide verwenden dieselbe Akte, aber unterschiedliche Auftragsschemata.

`document-review.js` erzeugt zusätzlich zwei Belegprüfungen. `case-action-groups.js` gruppiert diese mit Saniererfreigabe und technischem VN-Formular, führt eigene Nummern und eine weitere Funktionsauswahl ein. Die Astro-Seite enthält darüber hinaus die Dokumentauswahl mit eigenem Anweisungsfeld und einer weiteren manuellen Work-Übergabe. Weitere Texteingaben sind Empfänger-/Dokumentdaten oder Kommunikation und dürfen nicht mit einem allgemeinen Arbeitsauftrag verwechselt werden.

Der automatisch einklappende Seitencode, die mobile Grid-Anordnung, ereignisgesteuerte Fallbindung, dynamisch erzeugte Dokumentenliste und nachträglich eingehängte Mailablage müssen beim Zusammenführen berücksichtigt werden. Steuerelement-IDs und bestehende Handler bleiben erhalten; es werden keine Controller durch nachgebaute API-Aufrufe ersetzt.

## Konkrete Ansicht

```
Schadenakte [Suche / aktueller Schaden / Dokumente]
Bearbeitung [serverbestätigter Fall, Versicherer, Sparte, Profil, Regelstand]
  [Schaden bearbeiten] [Rechnung / KVA prüfen]
  [Bericht erstellen]  [Offene Aufgaben]
  Ablauf: Neuer Schaden | Akte aufarbeiten | Neuer Beleg / Nachtrag | Schadenabschluss
  Besondere Anweisungen: [ein gemeinsames Freitextfeld]
  So wird der Auftrag ausgeführt: [Aktion + Ablauf + Weg + Grenzen]
  [Auftrag ausführen]  Bearbeitungsstatus / Ergebnis / offene Fragen / Maßnahmen
  [Bearbeitungsverlauf]
Weitere Funktionen (geschlossen)
  Unterlagen | E-Mail | Ortstermin | Notizen | weitere Spezialfunktionen
Diagnose (separate Ansicht, kein Modellaufruf beim Öffnen)
```

Die vier Aktionen wählen zunächst einen Auftrag. Erst „Auftrag ausführen“ startet die bestehende Verarbeitung. Die Ablaufwahl ergänzt explizite Arbeitsanweisungen, stellt keinen Schadenabschluss oder eine Vollmacht dar. Kostenlose Kontextprüfung lädt die bestätigte Identität, Dokumentanzahl und freigegebenen Regelbezüge; Modellaufrufe beginnen ausschließlich nach explizitem Auftrag und über den vorhandenen Budgetgateway.

## Vollständige Funktionszuordnung

| Bisherige Funktion | Neuer Ort / Verhalten |
| --- | --- |
| SV-Assistent: Fall, Belege, Bericht, Aufgaben, individuelle Anweisung | Vier Hauptaktionen; individuelle Anweisung gilt für jede Aktion |
| Fall-Work: freier Auftrag / Korrektur und Promptvorlagen | Gemeinsames Textfeld und Ablauf-Vorauswahl; bestehender Fall-Work-Verlauf und Wiederaufnahme bleiben im Verlauf erreichbar |
| ChatGPT Work | Bearbeitungsweg im zentralen Auftrag; sichere manuelle Übergabe, kein automatischer Work-Import |
| Assistant-Verlauf, Status, Wiederaufnahme, Abbruch | Gemeinsamer Bearbeitungsverlauf und Status; bisherige serverseitige Mechanismen |
| Alte Fall-Work-Aufträge, Importwarteposition, Portalbestand-Fortsetzung | Bestehender Verlauf; gezielte Fortsetzung mit vorhandenem Bestand / Import wiederholen |
| Rechnungs-/Angebotsprüfung, Belegauswahl, ergänzende Belege, Entscheidung | Kontextwerkzeug bei „Rechnung / KVA prüfen“, bisherige Handler und Speicherkontrollen |
| Freigabe-Mail Sanierer / technisches VN-Formular | Dasselbe Kontextwerkzeug; nur ausgewählte Funktion sichtbar, Versand bleibt eigene Handlung |
| Berichtstyp, Zusatzdokumente, Schadenprotokoll, Finanzübersicht | Kontextwerkzeug bei „Bericht erstellen“; ein Auftragstext, vorhandene Original-/Dokumentverarbeitung |
| Dokumentenindex, Register, Kalkulation, Erst-/Zwischen-/Schlussbericht, Nachtrag, SE, Zahlungsbefürwortung, Rückfrage, Abgeltungsformular | Vollständige bestehende Dokumentauswahl im Berichtskontext; keine automatische Änderung nativer Formulare |
| Erstellte Dokumente / Excel-Originale | Berichtskontext, bestehende Ausgabe-/Downloadansicht |
| Fall suchen / anlegen / zuletzt geöffnet | Aktenkopf; vorhandene Suche und serverseitiges Laden |
| Falldaten und zusätzliche Telefonkontakte | Weitere Funktionen: Falldaten nur bei Änderungsbedarf öffnen |
| Dateien / Ordner / Unterlagen und Outlook-Mails hochladen / Mailarchiv | Weitere Funktionen; bestehende Ablage und Dateibrowser |
| E-Mail samt Anhang und Empfängern | Weitere Funktionen: E-Mail schreiben |
| Outlook-Termin / Einladungen / WhatsApp | Weitere Funktionen: Ortstermin planen; bestehende Versandgrenzen |
| PLAUD / Polycam | Weitere Funktionen: Ortstermin einlesen / Aufmaß auswerten |
| Gespeicherte Claimsforce-Notizen / manuelle Aktualisierung | Weitere Funktionen: Notizen; kein automatischer Abruf |
| Claimsforce-/Rekon-Import und Brücken-Download | Weitere Funktionen: Fallbestand aktualisieren; keine Ausführung durch Öffnen |
| Verbindungen, Masterabgleich, synthetischer Verbindungstest, Budgetdetails | Separate Diagnoseansicht |
| System-/Vorlagenprüfung, Cache, Einsortierung, IONOS-Neueinlesen | Separate Diagnoseansicht, ursprüngliche Berechtigungen bleiben erforderlich |

## Unveränderte Grenzen

Keine neue Datenbank oder neue Infrastruktur. Keine Browserdaten als Berechtigungsnachweis. Der vorhandene Kontext prüft Sitzung, Tab, Benutzer, Profil, Fallrevision und Regelversion weiterhin vor jedem Auftrag. Idempotenz, Fallmutex, globale Budgetreservierung (maximal 30 EUR/Monat), Verbrauchserfassung, unbekannter Verbrauch und Wiederaufnahme bleiben bestehen. Technische Daten werden ausschließlich diagnostisch dargestellt; fachliche Quellen bleiben am Ergebnis sichtbar. Das Ausblenden ist keine Berechtigungsprüfung und ersetzt keine API-ACL.

Synthetische Tests prüfen reale Seitenstruktur plus Controller und bestehende PHP-Grenzen. Kein Test darf Produktionsdaten, echte Claimsforce-Schreibaktionen, externe Nachrichten oder bezahlte Provider verwenden. Merge und Deployment sind nach bestandenen Tests ausdrücklich freigegeben. Externe Nachrichten und produktive Claimsforce-Schreibzugriffe gehören nicht zu diesem Auftrag.

## Ordnung und Weitergabe

Die Fallnavigation folgt fünf Schritten: Schaden öffnen, Bearbeitung, Verlauf und Ergebnisse, Unterlagen, weitere Funktionen. Die linke Versicherungsfälle-Navigation bietet dieselben Einstiege und die freie Belegprüfung. Originaldateien und Upload liegen unter „Unterlagen und gespeicherte Dokumente“; Notizen, E-Mail, Termin und Importe bleiben Spezialfunktionen. Alle alten statischen IDs bleiben erhalten.

An Arbeitsergebnissen und bekannten authentifizierten Downloadlinks stehen dieselben vier Aktionen: E-Mail-Entwurf mit Datei, Druckansicht/PDF-Druck, Download und native Dateifreigabe. Der Nutzer wählt auf unterstützten Smartphones WhatsApp oder eine andere App im Teilenmenü. Bei fehlendem Dateiteilen wird der Download mit manueller Weitergabe erklärt. Keine Falldaten in öffentlichen WhatsApp-Links, keine automatische Empfängerwahl oder Versandbestätigung. Ein vorhandener Mailentwurf wird nicht überschrieben. Originale bleiben bytegleich. Textresultate werden als eigenständige, offline lesbare HTML-Datei mit eingebettetem Logo gespeichert; PDF ist über die Druckansicht erreichbar. Office-Dateien werden zum Öffnen/Drucken in der jeweiligen Geräte-App weitergegeben. Direkte Speicherbestätigung bezeichnet nur den Browserdownload; die serverseitige Fallablage bleibt bei den bestehenden Speichernaktionen.

## Freie Belegprüfung und spätere Zuordnung

Rechnung/KVA ist ohne Fall wählbar. Die persönliche, benutzergebundene Prüfablage bleibt beim Öffnen eines Zielschadens erhalten. Nur ein expliziter Übernahmeauftrag kopiert Originale und gespeichertes Prüfergebnis. Der Server prüft Quelleigentümer, Ziel-ACL und exakte aktuelle Schadennummer; Browserdaten sind kein Berechtigungsnachweis. Beleg-Hashes werden vor und nach der Kopie geprüft. Dauerhafter Übernahmejournal, Sperre, Inhaltswiederverwendung und Rücklesen schützen Wiederholung und Wiederaufnahme. Ablage: Originale 04_Rechnungen_KVA, Prüfergebnis 06_Freigaben_Zahlungen. Die Quelle bleibt erhalten. Übernommene Entscheidungen werden erkennbar als erneut zu prüfen gekennzeichnet; alte Mailfreigaben sind gesperrt, bis die Entscheidung im Zielschaden erneut bestätigt und gespeichert wurde.

## Logo

Die bestehende orange SV-/sv-netzwerk.eu-JPG-Datei ist die zentrale Grafik. Eigene Mailausgaben erhalten ein tatsächliches Inline-Bild; technische Freigabe-, Abgeltungs-/KVA- und Wert-1914-PDF sowie Ausdrucke verwenden die Grafik. Bestehende Privatgutachten-Ausgaben behalten ihr geprüftes Logo. Originalbelege und fremde/native Vorlagen werden nicht überstempelt. Für ältere bereits erstellte Dokumente erfolgt keine automatische Umschreibung. Keine internen Richtlinien im öffentlichen Quellcode.

## Veröffentlichungsumfang

Nur public/intern und gebaute Portalansichten werden ausgeliefert. Der lokale Router, synthetische Testoberflächen und Datenbanken unter tests/ gehören nicht zum Deploymentpaket. Live-Prüfung erfolgt zusätzlich auf das Fehlen von Testknöpfen und Testdaten. Modell-, Mail- und Claimsforce-Transporte werden bei den Tests simuliert oder blockiert.

Der bestehende Dokumentenbrowser ist im Unterlagenbereich enthalten. Er verwendet ausschließlich den geladenen Fall und verwirft verspätete Listen nach einem Fallwechsel. Alte Auswahlen werden sofort geleert. Die Browserprüfung erzwingt eine verspätete Antwort von Fall A nach Fall B und prüft den erhaltenen B-Bestand.
