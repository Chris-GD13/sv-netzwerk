> Historischer Integrationsstand vor der erweiterten Betriebsfreigabe. Aktueller Betrieb: [sv-assistent-betrieb.md](sv-assistent-betrieb.md).

# Phase 3 – Claimsforce und SV-Assistent gemeinsam integrieren

Status: Entwicklungsstand zur Prüfung, 17.10.2026. Kein Merge nach main, kein Deployment,
kein produktiver Claims-Schreibzugriff, keine kostenpflichtigen Modellaufrufe.
Diese Beschreibung ergänzt die historischen Berichte aus Phase 1/2 und ersetzt deren
Hinweise zur noch fehlenden Integration bzw. pauschalen Phase-2-Transportsperre.

## Zusammengeführte Stände

Eigener Branch `codex/sv-assistent-phase3`, aus #404 erstellt, #402 mit echtem
Mergecommit integriert. #403 ist bereits Vorfahr von #404. Referenzstände:

- main: `4f8419c0e0eada26c97e570fd020ed37f22ca234`
- #402: `6aee98c6b85026dc392e7d60f39a44f7588ba104`
- #403: `7cee6e6e1cc19b28cb49986262ee8075ace50684`
- #404: `8b13385d9b09bbcd23d9323fb257538f424f9024`

Die drei ursprünglichen Branches/PRs werden nicht geändert. Der neue PR richtet
sich gegen main, damit der gesamte gemeinsame Integrationsstand sichtbar ist.
Ein späterer Merge benötigt eine eigene Freigabe; bestehende main-Workflows würden deployen.

## Gelöster Konflikt und zusätzliche Übergangsfehler

Der einzige Git-Inhaltskonflikt lag in der großen Inline-Skriptzeile der bestehenden
Versicherungsfallseite. Erhalten wurden die vollständige `_loaded`-Prüfung, das
bereinigte Rücklesen des Browserstorage, die bestätigte Fallidentität, die manuelle
Notizaktualisierung und die Notes-saved-Übernahme aus #402 sowie der Assistentenbereich
und seine Einbindung aus #404.

`show()` veröffentlicht genau ein bestätigtes Öffnungs- oder ein Schließereignis.
Beide Controller verwerfen ungeöffnete Browserdaten. Der Notizcontroller behandelt
auch das Schließereignis; der Assistent ignoriert reine Wiederholungen desselben
bestätigten Kontexts. Sonst konnte ein erneutes Anzeigen den laufenden Assistenten
zurücksetzen oder ein Fallwechsel alte Notizen stehen lassen.

Ein neuer Öffnungsversuch entzieht dem alten Kontext sofort die UI-Berechtigung.
Eine Generation schützt Suche, Wiederherstellung, Speichern und Uploadfortsetzungen
vor verspäteten Antworten. Eine geladene Ordner-ID muss der angeforderten ID
entsprechen. Eine laufende Uploadserie bleibt an ihrem ursprünglichen Ordner und
stoppt nach einem Fallwechsel; sie setzt sich nicht im neuen Fall fort.
Ein bereits versandter Schreibrequest wird dadurch nicht rückwirkend ungeschehen.

Bestehende Fall-Work-History und Legacy-Worker filtern `assistant:v1` aus. Legacy-
Dokumentaufträge verweigern diese Turns. Beide Fall-Work-Startwege serialisieren
Auftragsannahme und Fallbelegungsprüfung über denselben DB-Mutex; eine offene
Work-Übergabe sperrt konkurrierende Aufträge. Es gibt keine automatische Rückübernahme,
keinen Doppelimport und keinen zweiten Fall- oder Dokumentenspeicher.

## Gemeinsamer Budgetgateway

`ai-budget-gateway.php` enthält zentrale Lane-Policy, registrierte Legacy-
Transportkennungen und den transaktionalen Ledger. Jede Bestands-Transportstelle
übergibt eine feste Kennung im Servercode; der Browser wählt diese Zuordnung nicht.

| Bereich | Zuordnung | Grenze / Verhalten |
| --- | --- | --- |
| Bisherige KI-Funktionen | legacy | Bestehende Konfiguration bleibt wirksam; ausdrücklich keiner neuen 30-EUR-Grenze zugeordnet. Keine neuen Nutzungsgebührenmessungen für Altpfade behauptet. |
| Neuer SV-Assistent, später bezahlt | assistant | Gemeinsame harte Monatsgrenze 30 EUR, eigenes Konto. Bezahlter Transport weiterhin verweigert; kein aktivierbarer Provideradapter. |
| Lokale Modelltests | simulation | Getrenntes synthetisches 30-EUR-Testkonto, echte Gebühren immer 0. |
| Manuelle Work-Übergabe | kein Modelltransport | Bestehender Auftrag/Fall und Regeln, Kopieren nur auf Benutzeraktion. Keine technische Work-API behauptet. |

Simulation behält die Tabellen `case_work_budget`/`case_work_usage` und vorhandene
Phase-2-Teststände. Das für spätere echte Zusatzkosten vorbereitete Konto verwendet
`case_work_ai_budget`/`case_work_ai_usage` in derselben Datenbank. Keine zweite Infrastruktur.
Legacy nutzt den gemeinsamen Mutex zur Auftragsannahme, reserviert dabei aber kein
Assistentenbudget und erzeugt keinen simulierten Verbrauch.

Reservierung und Istverbrauch laufen im gemeinsamen Ledger unter SQLite
`BEGIN IMMEDIATE` bzw. MySQL/InnoDB-Transaktion mit `FOR UPDATE`. Ohne seinen
Transaktionskontext lässt der Ledger keine Reservierung/Verbuchung zu. Der harte
Cap bleibt auch bei höherem Datenbanklimit wirksam. Monatszuordnung Europe/Berlin,
Reservationsmonat bleibt bis Abschluss erhalten. Idempotenz, UNKNOWN hält Reservierung,
kein Blind-Retry, vollständige Istverbrauchserfassung und Fehler bei Mehrverbrauch
bleiben aus Phase 2 erhalten. Die Simulation verwendet ausdrücklich einen künstlichen
Stresstarif und ist keine Tarif- oder Kostenprognose eines Providers.

`SVNET_ASSISTANT_REQUEST` wird serverseitig am Assistenten-Endpunkt gesetzt. In diesem
Kontext verweigert der Gateway alle Netztransporte auch mit gültiger Legacy-Kennung
oder abweichendem Zielhost. Der Assistentenkern besitzt überhaupt keinen Netzwerk-
Modelladapter. Es gibt keinen vom Request wählbaren Budget-/Transport-Fallback.

`SVNET_AI_TRANSPORT_MODE=deny` sperrt in lokalen Tests bekannte Providertransporte.
Ohne diesen Testschalter funktionieren bestehende Legacy-Transporte gemäß bisheriger
Konfiguration. Das schaltet keinen bezahlten Assistenten frei. Tests prüfen unveränderte
Handles und führen den tatsächlichen AiService gegen einen simulierten Loopback-
Provider aus. Kein echter API-Key oder externer Modellaufruf wird dafür verwendet.

## Inventar vorhandener KI-Pfade

Aktiver Deploymentbaum ist `sv-netzwerk/`. Dort wurden 13 tatsächliche `curl_exec`-
Transportstellen in neun Dateien erfasst. Auch Datei-/Vector-Store-Verwaltung und
File-Search sind berücksichtigt, weil sie Modellverarbeitung bzw. Folgekosten vorbereiten.
Alle folgenden Pfade bleiben **legacy / bisherige, nicht neu zugeordnete Kosten**:

| Transportdatei | Kennung / Anzahl | Direkte und indirekte Aufrufpfade |
| --- | --- | --- |
| bki-calculator.php | bki-http ×2, bki-upload ×1 | BKI Responses, Datei-Upload, File-Search/Vector-Store-Verwaltung; derselbe HTTP-Helfer bedient auch Drive. |
| cli-extract-missing-falldaten.php | extract-cli ×1 | CLI-Ergänzung bestehender Falldaten. |
| gf-ai-generate-core.php | gf-http ×1, gf-upload ×1 | GF-Berichte, Dokumentgenerierung und gf-calculation-draft.php einschließlich Vector Stores; Belegupload ergänzt gf-financial-evidence.php. |
| gf-ai-smart.php | gf-smart-http ×2 | Bestehende schnelle GF-Analyse, Uploads und Responses. |
| gf-financial-evidence.php | gf-evidence-upload ×1 | Bestehender GF-Belegupload über Generierungskern. |
| insurance-case-extract.php | case-extract ×1 | Bestehende Anlage/Extraktion von Fallmetadaten. |
| kva-release.php | kva-http ×1 | KVA-Prüfung inkl. kva-release-core-v2.php; case-analysis.php und case-work.php/Worker; document-review.php; Datei-Uploads/-Löschungen und Responses über krHttp. |
| polycam-measurement-extract.php | polycam-http ×1 | Bestehende Aufmaßextraktion, Upload und Responses. |
| services/AiService.php | ai-service ×1 | ai-import.php, Dokument-/Bildanalyse, OCR und Beschlagerkennung über chat/completions. |

Andere HTTP-Helfer für Graph/Drive/Kalender/Claims erhalten keine Modellkosten-
Zuordnung. Der Scan erfasst bekannte Providerziele; er ist kein universeller Beweis
für beliebigen zukünftigen Fremdcode. Neue Modellpfade müssen ins Inventar und die
Gatewayprüfung aufgenommen werden. Root-`public/`, `analysis_export/` und Patch-
Generatoren sind zusätzlich gesichtet, aber keine zweiten aktiven Deployments.
Der Buildnachweis prüft den tatsächlichen verschachtelten Deploymentbaum erneut.

## Wissen und Bedienung

Gleicher privater, per SHA256 gepinnter Teilrelease wie Phase 2; die führende
Drive-Masterdatei bleibt die Fachquelle. Keine internen Regeltexte, echte Falldaten,
privaten Quellverzeichnisse oder Originaldokumente werden mit diesem PR veröffentlicht.
Die aktuellen privaten Quellen und Prüfgrenzen gehören nur in den privaten Testbericht.

Nur `approved` mit Quellen und dokumentierter Prüfgrundlage wird angewandt; Auswahl
nach Versicherer und Schadenart. Ungeprüfte Kandidaten bleiben ausgeschlossen.
Die vier schon unterstützten Prüfarten sind Zahlungsnachweis, Berichtsschwelle,
Energieberechnung und Wiederherstellung aus belegten Grundlagen. Es ist weiterhin
nur ein geprüfter Teilbestand, keine vollständige Regelfreigabe oder Pilotabnahme.

Vier Aktionen, Freitextdokumentation, gespeicherte Aufträge/Ergebnisse, Recovery und
manuelle Work-Übergabe bleiben in derselben einklappbaren Fall-Work-Oberfläche.
Freitext wird ohne echtes Modell nicht frei interpretiert. Der Bericht bleibt ein
simulierter Textentwurf. Es gibt keine zweite Anmeldung und keine Automatik für
Claims-Notizen, Import, Versand, Zahlung, Freigabe oder Fallabschluss.

## Reproduzierbare Prüfung

```text
python tools/sv-assistent/run-phase3-tests.py --report ABSOLUTER_TESTPFAD/tests.json
php -d extension=pdo_sqlite -S 127.0.0.1:47843 sv-netzwerk/tests/assistant-integration-local-router.php
```

Für den lokalen Router `SVNET_TEST_DB` auf eine isolierte Testdatenbank setzen.
Optional dieselben privaten Release-/Hashvariablen wie Phase 2; ohne diese gelten
nur synthetische Regeln. `/mobile` bettet denselben Bereich in einen 375px-Rahmen ein.
Der Router liefert die tatsächlichen Controller, Notizmarkup und `keep/show` aus
der zusammengeführten Fallseite. Authentifizierung, Fälle, Bridgeantworten und
Notizspeicherung sind simuliert. Keine Verbindung zu echten Claims-/IONOS-Fällen.

39 lokale Testgruppen: sieben native Viewer-/QS-/Offline-Speichergruppen, Notizen,
Storage/Source-Recovery, bestehende Bridge/Import/Profile/Rekon/Dokument-/Fall-Work-
Regressionen, Assistentenkern, zusätzlicher Budget-/Benutzerintegrationstest, echter
HTTP-Endpunkt und Legacy-AiService mit Loopback-Provider, gemeinsamer UI-Controller-
Test und Transportinventar. Acht reale konkurrierende PHP-Prozesse testen in zwei
Vierergruppen getrennt Simulation und vorbereiteten echten Ledger: je ein Gewinner,
drei Budgetablehnungen, keine Gebühren oder Vermischung der Konten.

Astrocheck und vollständiger Build einschließlich Schutz interner Einstiegsseiten
und vorhandener Text-/SEO-Prüfungen wurden lokal ausgeführt. Die separate Phase-3-
CI wiederholt alle 39 Gruppen und den Kernlebenszyklus auf MySQL 8.0. Konkrete
Commit-/CI-/Browsernachweise stehen im privaten Abschlussbericht.

## Verbleibende Pilotvoraussetzungen und Grenzen

Die Beleg-/Profil-/Import-/Formularlogik aus #402 ist erhalten; keine vollständige
Migration historischer Dateien behauptet. `LEGACY_SOURCE_BINDING_REQUIRED` bleibt
bei uneindeutigen Altimporten ein kontrolliertes Gate. Ein Rückfall muss Dateien,
Index, Versionen und Journale gemeinsam berücksichtigen.

Keine echte Apryse-SDK-Speicheranbindung und kein nativer SE-/ZB-Speicher-/Rücklese-
Nachweis. Native Bereitschaft und Navigation werden hier synthetisch geprüft;
keinen neuen Live-Originalformulartest behaupten. Kein Nachweis aktueller IONOS-
Dienstrechte oder realer Produktions-ACL. Ein späterer freigegebener Pilot braucht:

- Freigegebene Referenzfälle und echte Ablauf-/Laufzeitmessung. Der Adapter hasht
  aktuell Belege; das kann bei großen Akten teuer sein. Testdaten beweisen keine
  unveränderte Produktionsgeschwindigkeit.
- Vollständigen fachlich abgenommenen Regelbestand, geprüfte Extraktion und
  atomaren Quellenstand; zugeordnete Versicherer-/Spartenbezeichnungen bestätigen.
- Separat genehmigten Provider-/Tarifadapter mit Worst-Case-Tokenlimit, Leases und
  berechtigter Verbrauchsreconciliation. Keine vorhandene Alt-KI heimlich einbeziehen.
- Host-/PHP-/MySQL-/SQLite-/Dateijournalrechte und konsistenten Backup-/Rückfalltest.
- Eigene Freigabe für Testumgebung, späteren echten nativen Speicher-/Rücklesetest
  und zuletzt Deployment. Dieser Branch erteilt keine solche Freigabe.
