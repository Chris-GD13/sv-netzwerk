# Testbericht: vereinfachte Falloberfläche

20.10.2026, Europe/Berlin. Branch `codex/falloberflaeche-vereinfachen`, Ausgangspunkt `8cb620df`.

## Nachgewiesener Umfang

Eine zentrale Oberfläche mit vier Hauptaktionen, vier Ablauf-Vorauswahlen, einer besonderen Anweisung, Vorschau, Status und gegliedertem Ergebnis. Vorhandene Beleg- und Dokumentwerkzeuge sind im passenden Aktionskontext eingebunden; ihre ursprünglichen Steuerelemente und Handler werden verschoben, nicht kopiert. Sämtliche 227 ursprünglichen statischen Markup-IDs bleiben erhalten; der neue Stand enthält 243 eindeutige IDs ohne doppelte IDs.

Fallwechsel, Ausblenden der alten Ergebnisse, gemeinsame Texteingabe, serverbestätigte Metadaten, manuelle Work-Übergabe, Verlauf und gezielte Fortsetzung wurden geprüft. Die Diagnose verwendet eine eigene Ansicht `?ansicht=diagnose`. Die Benutzeroberfläche stellt nur fachliche Ergebnisfelder dar; technische Antwortdaten liegen dort separat. Dokumentauswahl und Belegentscheidung bleiben explizite Schritte und werden nicht schon beim Öffnen ausgeführt.

## Bestandene Prüfungen

| Prüfung | Ergebnis |
| --- | --- |
| Astro-Prüfung | 360 Dateien, 0 Fehler, 0 Warnungen, 0 Hinweise |
| Astro-Build | 1.078 Seiten erfolgreich erstellt |
| `assistant-core-test.php` | 52 Prüfungen; inklusive serverseitiger Versicherer-/Sparten-/Dokumentzuordnung, ACL, Fallrevision, Regeln, Doppelverarbeitung, Berechnungen, Wiederaufnahme |
| `assistant-integration-budget-test.php` | 52 Prüfungen, kein Netzwerktransport; Hard Cap 30 EUR, Reservierung, Trennung der Konten, Verbrauch, Sperren |
| `assistant-paid-test.php` | 31 Prüfungen, alle Providertransporte simuliert; reale externe Kosten 0 EUR |
| `assistant-ui-test.mjs` / `assistant-integration-ui-test.mjs` | Fallbindung, verspätete Antworten, geänderte Rechte, keine Browseridentität, sichere Speicher-/Upload-Fortsetzung |
| `claims_notes_test.mjs` | 15 Prüfungen; keine automatische Notizabfrage, Fallwechsel, Sperren, Zusammenführung |
| `document_review_test.php` / `document_review_workflow_test.php` | Rechnung/KVA, Centberechnung, Entscheidung, Originalanhang und isolierter IONOS-Testobjektspeicher; kein Produktivzugriff |
| `assistant-http-test.py` | Tatsächlicher PHP-Dispatcher mit synthetischer Anmeldung; Origin, ACL, Idempotenz, Fallwechsel, Buchungen |
| `assistant-concurrency-test.py` | Je vier echte PHP-Prozesse: eine 20-EUR-Reservierung, drei am 30-EUR-Limit blockiert; keine verwaisten Aufträge |
| `assistant-egress-test.py` | 13 bestehende Transportstellen geprüft, neue kostenpflichtige Ausführung weiter an Budgetfreigabe gebunden |
| Neue Browserprüfung Desktop | 37 bestandene Ablaufprüfungen bei 1.086 px Inhaltsbreite |
| Dieselbe Browserprüfung Smartphone | 37 bestanden im 375-px-Frame (360 px Inhalt nach Scrollleiste), kein horizontaler Überlauf |
| Diagnoseansicht | Normale Auftragsmaske ausgeblendet; Öffnen startet weder Verbindungstest noch Modellaufruf |

Die neuen Browserprüfungen verwenden die vollständige tatsächliche Fallseiten-Markup-Struktur, den Original-Einklappcode, die produktiven Assistant-/Fall-Work-/Belegprüfungs-/Gruppierungscontroller sowie die neue Zusammenführung. API-Kontext und simulierte Aufträge laufen über den tatsächlichen PHP-Dispatcher mit der vorhandenen synthetischen ACL-/SQLite-Fixture. Der Router führt ausschließlich freigegebene lokale Test-Routen aus. Originales Seiten-Bootstrapping mit produktiver Anmeldung ist damit nicht als produktiv getestet behauptet.

## Reproduktion

Im Ordner `sv-netzwerk`:

```powershell
$env:SVNET_TEST_DB = Join-Path $env:TEMP 'sv-workspace-review.sqlite'
$env:SVNET_AI_TRANSPORT_MODE = 'deny'
php -d extension=pdo_sqlite -d extension=zip -S 127.0.0.1:47866 tests/workspace-local-router.php
```

Im Browser `http://127.0.0.1:47866/intern/versicherungsfaelle/` öffnen und „Synthetische Oberflächenprüfung“ anklicken. Erwartet: `TOTAL 37 Prüfungen`, kein `FAIL`. Die Seite vor erneutem Test frisch laden. Für Smartphone `http://127.0.0.1:47866/mobile` und „Mobile Prüfung ausführen“ verwenden. Der Test prüft unter anderem: kein automatischer Modell-/Notiz-/Belegabruf, genau vier Aktionen, sichere serverseitige Metadaten, ein Auftrag pro Start, keine Doppelansätze, erhaltene Originalauswahl, Verlauflesen ohne neue Auswertung, manuelle Work-Übergabe, Fallwechsel und sofortige Sperre beim Schließen.

Die genannten PHP-/Node-/Python-Dateien lassen sich unabhängig ausführen. PHP benötigt PDO SQLite; der simulierte Provider-/Tabellentest zusätzlich ZIP. `npm run check` und `npm exec astro build` prüfen den Seitenbau. Keine zusätzlichen Testbibliotheken oder laufenden Dienste wurden eingeführt.

## Grenzen und Freigabestatus

Merge und Deployment sind nach den bestandenen Tests ausdrücklich vom Nutzer beauftragt. Keine echten Claimsforce-Schreibaktionen, keine Mails, Zahlungen oder Fallabschlüsse und keine kostenpflichtigen KI-Aufrufe. Keine echten Fallinhalte oder internen Richtlinientexte in diesem Entwicklungsstand. Die Änderung ersetzt weder die vorhandenen Versicherer-Originalformulare noch deren fachliche Kontrolle. Reale Anmeldung, reale Providerqualität und produktive Originaldokument-Erstellung sind nicht Bestandteil dieses synthetischen Oberflächentests; ein späterer Pilot benötigt gesonderte Freigabe. Die bestehende technische Verarbeitung und deren serverseitige Sicherheits-/Budgetgrenzen bleiben erhalten.

## Ergänzende Abschlussprüfung

Die vollständige Portal-Deployment-Regression und sechs zusätzliche Assistant-/Notiztests bestehen: insgesamt 57 ausgeführte PHP-/Node-Testprogramme. Der neue Brandingtest prüft sieben Eigenschaften: kanonische Grafik, Inline-Mail, Idempotenz, unveränderte Empfänger/Betreff/Originalbytes sowie zwei PDF-Schreiber. Zwei synthetische PDF-Muster (drei Seiten) wurden gerendert und visuell vollständig auf Logo, Layout und Lesbarkeit geprüft. Bestehende Tests mit Erwartungen an das frühere gezeichnete Textlogo wurden auf das echte Bild aktualisiert.

Der reale isolierte Speicherworkflow umfasst die neue freie Übernahme: falsche Zielnummer, fremdes Ziel, Bytevergleich beider Originale, erneute fachliche Bestätigung, gesperrter Alt-Mailentwurf, idempotente Wiederholung und Wiederaufnahme nach simuliertem Ausfall vor dem letzten Journal-Checkpoint ohne Dateidubletten. Quelle und Originale bleiben erhalten.

Zusätzlich prüfen die 37 Browserassertionen die neue Dokumentnavigation, vier Weitergabeaktionen und einen echten browserseitigen Datei-Anhang mit eingebettetem Logo. Der E-Mail-Entwurf wird vorbereitet und nicht abgesendet. WhatsApp-Empfang und gerätespezifische Druckdialoge werden nicht als getestet behauptet; das native Teilen bleibt von Browser und Betriebssystem abhängig. Bei fehlender Dateifreigabe wird der Speicher-/Anhangweg angezeigt.

Lokale Testknöpfe und synthetische Daten kommen ausschließlich aus tests/workspace-local-router.php und tests/fixtures/. Sie sind nicht Teil des Produktionspakets. Die separate Live-Prüfung nach dem Deployment kontrolliert den Commitmarker, Anmeldung/Cockpit und die echten Navigations-/Seitenbestandteile ohne Modellauftrag oder echten Fallversand.
