---
Titel: SV-Assistent – lokaler Fall-Work-Prototyp
Projekt: SV-Netzwerk / Fall-Work
Dokumenttyp: Architektur, Bedienung und Testbericht
Status: IN PRÜFUNG
Version: 0.2.0
Erstellt am: 2026-10-17
Letzte Änderung: 2026-10-17
Abhängigkeiten: Phase 1 / Draft-PR 403; Claimsforce-Stabilisierung 402 nur Referenz
---

# Phase 2: Erweiterung der vorhandenen Fall-Work

## Tatsächlich implementiert

Der vorhandene Fall-Work-Bereich der Versicherungsfallseite heißt im Entwicklungsstand
„SV-Assistent“. Vier Aktionsbuttons, Freitext, bestätigte Schadennummer, Quellprofil,
Regelrelease und simuliertes Monatsbudget sind enthalten. Desktop und schmale
Ansichten verwenden dieselbe Oberfläche. Es gibt keine zweite Fallverwaltung und
keinen zweiten Dokumentenspeicher. Die Browserprüfung verwendet genau diesen aus
der bestehenden Astro-Datei entnommenen Abschnitt und die tatsächlichen JS-Dateien.
Der lokale Test-Router ergänzt ausschließlich synthetische Fallauswahl/Authentifizierung.

Alle Aktionen gehen über `case-work.php?assistant=1` in der vorhandenen
PHP-Auftragsverarbeitung. Aufträge und Ergebnisse verwenden `case_work_turns` und
`case_analysis_jobs`. Hilfstabellen in derselben Datenbank speichern kurzlebige
Tabkontexte, Monatsbudget, Reservierungen/Usage und Auditereignisse. Kein neuer
Datenbank-, Browser-, Queue- oder KI-Dienst. SQLite ersetzt im lokalen Test die
bestehende MySQL/MariaDB-Verbindung; der PDO-Code besitzt einen InnoDB-Transaktionspfad.

Der einzige Modellpfad ist eine deterministische In-Process-Simulation. Freitext
wird am Auftrag dokumentiert, aber nicht semantisch von einem Sprachmodell ausgeführt.
Der Bericht ist ein sichtbar simulierter Textentwurf; keine DOCX-/SE-/ZB-Erstellung,
keine nativen Formularänderungen und keine fachliche Freigabe.

## Sicherheits- und Ablaufgrenzen

1. Nach tatsächlichem Öffnen/Speichern eines Falls meldet die bestehende Fallseite
   einen flüchtigen Öffnungsereignis. Der Assistent lädt serverseitig den Original-
   kontext erneut. Browserstorage ist kein Identitätsnachweis.
2. Bestehende Authentifizierung, Originalordner-ACL und Profilregeln bleiben die
   Produktionsgrenze. Die Quellfunktion wird vor jeder Aktion erneut ausgeführt.
   Metadaten-/Dokumentversion und Regelhash müssen mit dem gespeicherten Kontext
   übereinstimmen. Fehlende Identitäts-/Sparte-/Versichererangaben blockieren.
3. Zufälliger Kontext, Sessionhash, Benutzer-ID und Tab-ID binden jeden Auftrag.
   Andere Tabs behalten ihren eigenen Kontext. Schließen/Wechseln leert die lokale
   Ansicht sofort und widerruft den alten Serverkontext. Verspätete Antworten werden
   durch eine zusätzliche Browsergeneration verworfen, auch wenn Abbruch nicht greift.
4. Ein Request-Key mit identischem Inhalt liefert denselben Auftrag. Abweichender
   Inhalt mit gleichem Key wird abgewiesen. Fallbezogene Sperre verhindert parallele
   Assistenten-/Work-Aufträge. Jede mutierende Operation läuft in einer DB-Transaktion.
5. Explizite Schritte: queued → running → done bzw. handoff. Pause/Resume/Cancel,
   erneutes Öffnen der DB, kontrolliertes Wiederbinden eines gespeicherten Auftrags
   und Verwerfen veralteter Aufträge sind implementiert. Kein Timer/Polling im neuen
   Controller. Statuslesen erzeugt weder Modell- noch Dokumentfolgeaufträge.
6. UNKNOWN hält seine Kostenreservierung und wird nicht automatisch wiederholt.
   Die reine Mock-Reconciliation ist ein serverseitiger Kernpfad ohne HTTP-Freigabe.
   Bei unklarem echtem Providerstatus wäre später ein berechtigter Abgleich nötig.
7. Fremddokumenttexte und Freitext können keine Aktion außerhalb der festen
   Aktionsliste aufrufen. Alle UI-Inhalte werden als Text gerendert. Kein Tool zum
   Senden, Zahlen, Freigeben, Importieren oder Abschließen ist registriert.

## Budgetgateway

Das Monatskonto gilt für den gemeinsamen Zusatzverbrauch, nicht je Nutzer. Harte
Obergrenze 30.000.000 Mikro-Euro = 30 EUR, auch wenn eine DB-Zeile höher eingestellt
wäre. Reservierung **vor** Auftragsannahme, gebuchte und reservierte Summen in einer
atomaren Transaktion; SQLite `BEGIN IMMEDIATE`, MySQL/InnoDB `FOR UPDATE` auf einer
zentralen Mutexzeile. Fehlgeschlagene Reservierung hinterlässt keinen Auftrag.

Standardreservierung im Mock: 0,25 EUR. Simulierte Usage: 500 Input- und 100 Outputtoken
mit dem ausdrücklich synthetischen Tarif `synthetic-v1` (100/500 Mikro-Euro pro Token)
ergeben 0,10 EUR Simulationsverbrauch. Dies ist ein Stresstarif für die Tests und
keine Herstellerkostenprognose. Echte Gebühren bleiben 0 EUR. Work-Übergaben haben
keinen simulierten Modellverbrauch. Operation-ID verhindert doppelte Verbuchung.

Istverbrauch, Tokenzahlen, Tarifversion, reale Gebühr 0 und Simulationsstatus werden
im Ledger gespeichert. Reservierungsdifferenz wird erst beim bestätigten Abschluss
gelöst. Abbruch vor Simulation gibt die Reservierung frei. UNKNOWN hält sie fest.
Ein nachträglich abgeglichener Mehrverbrauch wird vollständig gebucht und der
Auftrag als fehlgeschlagen markiert; der Verbrauch wird nicht auf die Reservierung
heruntergerechnet. Weitere Reservierungen werden an der Grenze abgewiesen.
Monatszuordnung erfolgt in Europe/Berlin und bleibt am ursprünglichen Auftrag,
auch wenn der Abschluss in den Folgemonat fällt.

Zusätzlich sperrt `ai-budget-gateway.php` **alle bekannten vorhandenen Modell-
transporte** unmittelbar vor `curl_exec`. Neun bestehende Transportdateien einschließlich
KVA, BKI, GF und AiService durchlaufen diese Sperre; indirekte Analysepfade verwenden
deren vorhandene Helfer. Die Sperre ist in Phase 2 nicht per Umgebungsvariable abschaltbar.
Ein CI-Quellscan prüft die bekannten Providertransporte. Damit kann auch ein alter
KI-Endpunkt keinen kostenpflichtigen Modellaufruf am Prototyp vorbei ausführen.

**Integrationsfolge:** Diese Branch-Version würde beim späteren Einbau bestehende
echte KI-Funktionen bewusst blockieren. Sie darf nicht unbesehen produktiv übernommen
werden. Ein später genehmigter bezahlter Adapter muss jeden Alt-/Neupfad an denselben
Ledger anbinden, Tarif-/Tokenobergrenzen prüfen und die Transportsperre erst dann in
einem eigenen überprüften Arbeitspaket ersetzen. Heute existiert kein solcher Adapter.

## Gemeinsame Regeln und Work

Der Server erwartet ein privat konfiguriertes, per SHA256 gepinntes Release.
Nur Quellen mit dokumentierter Prüfgrundlage und Status `approved` werden verwendet.
Ungeprüfte Kandidaten bleiben ausgeschlossen. Auswahl erfolgt nach exaktem
Versicherer- und Spartenwert; unbekannte Zuordnung wird nicht geraten. Quellenbezug,
Regel-ID und Releasehash sind am Ergebnis sichtbar und gespeichert. Ein geändertes
Release invalidiert alte Kontexte und verhindert die unbemerkte Fortsetzung.

Die echte lokale Browserprüfung verwendete einen privat gehaltenen, manuell
eingeordneten Teilbestand der führenden Masterquelle. Dessen Inhalt, Parameter,
Originaltexte und Quellenartefakte sind **nicht Teil dieses öffentlichen PRs**.
Öffentliche Tests verwenden ausschließlich ausdrücklich synthetische Regeln und Fälle.
Damit ist weder die vollständige fachliche Wissensmigration noch eine Pilotfreigabe behauptet.

Unterstützte deterministische Regelprüfungen: Berichtsschwelle, Zahlungsnachweis,
gesonderte Energieberechnung und eigene Wiederherstellungskalkulation bei fehlendem
Angebot. Rechnungsprüfung erkennt Hashdubletten und ausdrücklich belegte KVA-/Rechnungs-
ersetzungen. Mehrfache/zyklische Ersetzungen blockieren. Cent-/Mengenrechnungen erfolgen
mit Integerwerten und definierter Rundung. Ungeprüfte Quellen/fehlende Beträge bleiben offen.
Es gibt keine automatische Anerkennung, Auszahlung oder Rekonstruktion fehlender Maße.

ChatGPT Work wird als manuelle Übergabe auf demselben Fall-/Auftragsdatensatz
vorbereitet. Paket: Originalfall, Profil, Quellenrevision, Regelrelease/-hash,
anwendbare freigegebene Regeln mit Quellen, Auftrag und Rückkehrprüfung. Kopieren
ist eine eigene Benutzeraktion; es gibt keinen Versand und keine behauptete
automatische Work-Schnittstelle. Solange die Übergabe offen ist, sperrt die
Auftragslogik einen konkurrierenden Assistentenlauf. Vor Rückübernahme muss der
aktuelle Fall-/Regelstand geprüft werden. Keine automatische Ergebnisrückschreibung.

## Lokaler Start

Voraussetzung: PHP 8.2 mit PDO SQLite, mbstring und curl; Node für die UI-Prüfungen.
Synthetische Testquelle und Testauthentifizierung bleiben ausschließlich im Test-Router.

```powershell
$env:SVNET_TEST_DB = 'ABSOLUTER_PRIVATER_PFAD/prototype.sqlite'
php -d extension=pdo_sqlite -S 127.0.0.1:47841 sv-netzwerk/tests/assistant-local-router.php
```

Browser: `http://127.0.0.1:47841/intern/versicherungsfaelle/`.
Mobil-Prüfrahmen: `http://127.0.0.1:47841/mobile` (375px).
Fall A/B explizit öffnen, Bereich aufklappen, Aktion auslösen. Auftrag nach Neuladen
über „Gespeicherten Auftrag laden“ wiederbinden, dann Status/Weiterbearbeitung wählen.
Diese Browseransicht ist ein lokaler Komponenten-/API-Test, kein IONOS-Livetest.

Optional private Regelprüfung im lokalen Router: `SVNET_TEST_RULE_RELEASE` und
`SVNET_TEST_RULE_HASH`. Keine echte Fallakte einlesen. Für den späteren normalen
Portaladapter sind `CASE_WORK_ASSISTANT_MODE=mock`, `CASE_WORK_RULE_RELEASE` und
`CASE_WORK_RULE_RELEASE_SHA256` erforderlich. Ohne diese Konfiguration sperrt er.
Dieser Adapter liest bestehende Quellen, markiert jedoch keine Rohdatei automatisch
als geprüften strukturierten Finanzbeleg. Eine fachlich geprüfte Extraktionsschicht
ist Voraussetzung für aussagekräftige echte Fallauswertungen.

## Testnachweise

- 50 PHP-Kernprüfungen lokal bestanden: Identität, falsche Nummer, Nutzer-/Profil-
  rechte, Session/Tab, Ablauf/Verfall, Rechteentzug, doppelte Requests, Fallwechsel,
  Dokument-/Regeländerungen, Recovery, Abbruch, offene Übergabe, UNKNOWN/Mehrverbrauch,
  Monatsgrenze, Rechnungsersetzung, Rundung, synthetische Berichtsschwellen,
  Injektionsdaten und gesperrte echte Providertransporte.
- Vier tatsächlich parallele PHP-Prozesse auf derselben SQLite-Datei: ein Gewinner
  mit 20-EUR-Reservierung, drei Budgetablehnungen, keine verwaisten Auftragszeilen.
- Ausgeführter JS-Controller im Node-VM-Test: keine Startabfrage, keine Storage-
  identität/Timer, überholte Öffnungsantwort und verspäteter Bericht verworfen,
  sofort leere Ansicht nach Fallschluss, serverseitiger Kontextwiderruf.
- Echter Chrome: Fall A laden, vier Aktionen, Fall B wechseln, Work-Übergabe;
  Ergebnisansicht getrennt. 375px-Prüfrahmen: Kontext/Buttons/Belegprüfung sichtbar
  und bedienbar. Kein Test auf einem physischen Smartphone.
- Bestehende Profilrouting-, Fall-Work-, Analyse-, Analyseworker-, Dokumentworkflow-
  und Legacy-Fall-Work-UI-Tests lokal bestanden. Dokumentworkflow mit SQLite-Modul.
- Astrocheck ohne Fehler/Warnungen/Hinweise; vollständiger Build mit 1.047 Seiten,
  Schutz interner Seiten und vorhandenen Text-/SEO-Validierungen bestanden.
- Eigene CI enthält zusätzlich denselben Lebenszyklustest auf einem separaten
  MySQL-8.0-Testservice. Der konkrete Laufstatus gehört in den Abschlussbericht.

```text
php -d extension=pdo_sqlite sv-netzwerk/tests/assistant-core-test.php
python sv-netzwerk/tests/assistant-concurrency-test.py
node sv-netzwerk/tests/assistant-ui-test.mjs
python sv-netzwerk/tests/assistant-egress-test.py
```

## Verbleibende Pilotvoraussetzungen

Keine echte Modell-/OCR-/PDF-Extraktion, kein realer Claims-Schreibweg, kein nativer
Speicher-/Rücklesebeleg, kein vollständiger freigegebener Regelbestand. Echte
Freitextausführung und layoutgetreue Berichte bleiben offen. Die aktuelle
Produktions-ACL/IONOS-Verbindung wurde im lokalen Prototyp nicht mit echten Akten
aufgerufen. Feldbezeichnungen und Dokumenthashing müssen mit freigegebenen
Referenzakten und dem endgültigen Quellenmanifest abgeglichen werden.

Der grobe gemeinsame DB-Mutex ist für den Prototyp absichtlich konservativ.
Vor Pilot: I/O-Zeiten messen, Quellstand atomar sichern, Job-Leases/Überwachung und
berechtigte Usage-Reconciliation für echte Provider ergänzen; keinen automatischen
Blind-Retry für unklare externe Vorgänge zulassen. Tatsächliche MariaDB-/Webbenutzer-
und PHP-CLI-Rechte auf IONOS getrennt verifizieren. Datenschutz-/Providerfreigabe,
Kostenfreigabe und echte Tarifgrenzen fehlen weiterhin.

PR #402 wurde nicht übernommen. Notiz-/Claimsforce-Skripte wurden nicht geändert;
der neue Controller ruft keine Notiz-/Importendpunkte auf. Der spätere gemeinsame
Einbau muss die tatsächlich freigegebene Stabilisierung erneut prüfen. PR #403
bleibt unverändert; Phase 2 besitzt einen eigenen Branch auf dessen Quellstand.
Kein Merge, Deployment oder produktiver Eingriff. Rücknahme: diesen Branch nicht
übernehmen; lokale Testdatenbank/Server sind getrennt von allen Produktivdaten.
