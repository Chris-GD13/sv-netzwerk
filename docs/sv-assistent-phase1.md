---
Titel: SV-Assistent – technische Bestandsaufnahme und Integrationsentscheidung
Projekt: SV-Netzwerk / Fall-Work
Dokumenttyp: Architektur und Integrationsplan
Status: IN PRÜFUNG
Version: 0.1.0
Verantwortlich: Product Owner / Entwicklung
Erstellt am: 2026-10-17
Letzte Änderung: 2026-10-17
Abhängigkeiten: bestehende Fall-Work-Verarbeitung, zentrale freigegebene Wissensquellen
---

# SV-Assistent im bestehenden Fall-Work

## Entscheidung und Umfang

Der Assistent erweitert die vorhandene Fall-Work-Oberfläche und Auftragsverarbeitung.
Keine neue Anwendung, Fallverwaltung, Dokumentenablage, Datenbank oder Browserplattform.
Die aktuelle explizite Architekturentscheidung des Auftraggebers vom 17.10.2026
hat Vorrang vor älteren allgemeinen Plattformkonzepten. Erste Funktionen:
Fallprüfung, Rechnungs-/Angebotsprüfung, Berichtsvorbereitung, offene Aufgaben und
individuelle Anweisung. Wirtschaftlichkeit, Zuverlässigkeit und vorhandenes Wissen
haben Vorrang vor Funktionsumfang.

Dieses Paket schließt die technische Bestandsaufnahme mit dokumentierten Grenzen ab.
Es enthält Offline-Werkzeuge, ein Regelschema und eine reproduzierbare Kostenplanung.
Es aktiviert weder neue UI-Funktionen noch KI-Aufrufe. Der vollständige interne
Quellen-/Konfliktbericht und das Inhaltsinventar gehören in eine geschützte Ablage,
nicht in dieses öffentliche Repository. Die Quellen wurden nicht verändert.

## Geprüfter Stand

- Basis: `origin/main` `4f8419c0e0eada26c97e570fd020ed37f22ca234`.
- Separater Branch: `codex/sv-assistent-bestandsaufnahme`.
- PR #402: offen, Draft, nicht gemergt; Head `6aee98c6b85026dc392e7d60f39a44f7588ba104`.
  Nur lesend geprüft; keine Übernahme oder Deployment.
- Das echte Portal wurde sichtbar in Chrome geöffnet. Fallauswahl und bestehender
  Bereich „KI · Fall-Work · gemeinsam abarbeiten“ waren erreichbar; kein Fallauftrag
  oder Modelllauf wurde ausgelöst. Dies ist kein Funktionstest des neuen Assistenten.
- Hostinginformationen aus der Projektübergabe sind datierte Befunde. Ein neuer
  SSH-Versuch mit verfügbarer Standardauthentifizierung wurde abgewiesen. Keine
  aktuelle Bestätigung von CLI-Rechten, Prozesslimits oder verfügbarer Serverkapazität.

## Wiederverwendung und konkrete Lücken

Alle folgenden Pfade liegen unter `sv-netzwerk/`:

| Bereich | Bestand | Wiederverwendung / erforderliche Ergänzung |
|---|---|---|
| Oberfläche | `src/pages/intern/versicherungsfaelle/index.astro`, `public/intern/case-work.js` | Vorhandenen Bereich einklappbar als SV-Assistent weiterführen, vier Aktionen und Freitext; vorhandene Portalstile verwenden. |
| Aufträge | `public/intern/api/case-work.php` | `case_work_turns`, Request-Key und Gesprächshistorie erhalten; Auftragsart, Kontextbindung, Regelrelease und Budgetreservierung ergänzen. |
| Worker | `case-work-worker-cli.php` | Bestehenden CLI-Pfad weiterverwenden; Lease, Schritte, Checkpoints, kontrollierte Wiederaufnahme und Rechteprüfung präzisieren. |
| Analyse | `case-analysis.php`, `case-analysis-core.php`, `case-work-core.php` | Manifest, Quellenhashes und Ausgabeschema erhalten; deterministische Prüfungen vor KI, begrenzte Extraktion und Anbieteradapter ergänzen. |
| Fallzugriff | `config.php::requireCaseFolderAccess`, `profile-routing.php`, `ionos-storage.php` | Bestehende serverseitige Zugriffskontrolle und Originalablage erhalten; zusätzlich tatsächlich geöffneten Kontext nachweisen. |
| Regeln | `case-rules.php`, `case-rules-import.php`, `insurance-knowledge*.php` | Bestehende geschützte Import-/Lesewege nutzen; führende Cloud-Quelle per unveränderlichem Release identifizieren und Import zurücklesen. |
| Dokumente | `case-work-documents.php`, `gf-ai-generate*.php`, `case-work-docx.php` | Vorlagen-/Dokumentpfade nutzen; kein automatischer Folgeauftrag aus neuem Assistenten ohne eigene Aktion, QS und Budget. |
| Belege/Kalkulation | `kva-release.php`, vorhandene Kalkulations- und BKI-Verarbeitung | Fachliche Extraktion von Freigabe-/Versandaktionen trennen; Beträge und Positionsüberlappungen deterministisch prüfen. |
| Claimsforce | `browser-extension/claimsforce-bridge/`, `claimsforce-central.js`, Queue | Bestehende Brücke weiterverwenden, keine parallelen Browseraufträge; Schreibwege bleiben gesperrt. |

### Nachgewiesene Integrationsrisiken

1. `case-work.js::active()` liest Browserstorage. Die Epochensperre reagiert im
   aktuellen Pfad auf Ordnerwechsel; sie ist kein vollständiger Nachweis von
   Benutzer, Profil, Schadennummer und Dokumentstand. Die bestehende
   Serverberechtigungsprüfung belegt Zugriff, nicht den tatsächlich offenen Tab.
2. `case-work.php` nutzt benutzerbezogene Idempotenz; Worker prüfen eine konkrete
   Eigentümer-ID. Diese Prüfung muss gegen die bereits erlaubten geteilten
   Quellbestände und Backoffice-Identitäten getestet werden. Ein erlaubter API-Zugriff
   darf nicht später an einer abweichenden vereinfachten Worker-Prüfung scheitern.
3. `caIonosRuleFiles()` erwartet einen Masterdateinamen und durchsucht lokale
   Wissensbestände. Gleicher Name beweist keinen aktuellen gemeinsamen Cloud-Stand.
   Bereits importierte Quellen und führende Drive-Version müssen explizit abgeglichen werden.
4. `krOpenAiJson()` ruft Responses auf, liefert aber nur das fachliche JSON zurück.
   Im geprüften Pfad kein zentraler Verbrauchs-/Reservierungsledger. Mehrere
   Extraktionen, Ausarbeitung und Endkontrolle sowie weitere Dokumentläufe sind möglich.
5. `case-work-worker-cli.php` stößt nach Analyse die Dokumentqueue an; auch UI-Polling
   kann Dokumentaufträge anlegen. Diesen vorhandenen Pfad für den Assistenten
   nicht ungeprüft aktivieren. Ein Statusabruf soll keinen Folgeauftrag erzeugen.
6. Bestehende HTTP-/CLI-Wartezeiten, Detached-Start und ein nach einer Stunde als
   fehlgeschlagen markierter Auftrag sind keine robuste Schritt-Wiederaufnahme.
   Ein verlorenes Ergebnis darf keinen zweiten kostenpflichtigen Lauf auslösen.
7. Quellenhashes verhindern keine falsche Quellzuordnung oder fachlich falsche
   Extraktion. Native SE/ZB-Speicherung und unabhängiges Rücklesen bleiben offen.

## Gemeinsamer Regelstand mit Work

Drive bleibt fachlich führend. Ein Release enthält Quell-IDs, Änderungsversionen,
Hash des tatsächlich gelesenen Inhalts, Fundstellen, Originalvorlagenhashes,
Regel-IDs, Geltung und bestätigte Ersetzungsbeziehungen. Exporthash und Originalhash
getrennt führen: ein Google-Doc-Textexport hat keinen garantierten Binärhash des Originals.

Der Portalimport speichert denselben freigegebenen Snapshot in der bestehenden
geschützten Wissensablage und prüft ihn durch Rücklesen. Ein atomarer Aktivzeiger
referenziert genau ein Release. Ein Arbeitsauftrag bindet dieses Release unveränderlich.
Bei neuer bindender Regel vor Fertigstellung wird der betroffene Entwurf als veraltet
markiert und erneut geprüft; laufende Aufträge werden nicht still auf eine Mischung
alter und neuer Regeln umgestellt.

Work liest zu Arbeitsbeginn denselben Release-Deskriptor und die dazugehörigen
Original-/Detailquellen. Erreichbarkeit des Deskriptors in Work und Portal ist vor
Aktivierung nachzuweisen. Andere Work-Chats werden dadurch nicht automatisch
geweckt oder aktualisiert. Nicht exportierte Chatkorrekturen bleiben eine benannte
Quellenlücke. Keine zweite eigenständig gepflegte Prompt-Regelsammlung.

`tools/sv-assistent/rule.schema.json` trennt Regel, Einzelfall, historische Referenz
und offenen Befund. `AKTIV` verlangt Regelklassifikation, Quellen, Review, Prüffälle
und einen Freigabeverweis. Das Schema prüft die Form; der Server muss die echte
Freigabe aus vertrauenswürdiger Benutzeraktion gegen den Inhaltshash verifizieren.
Ein in einer Datei behaupteter Freigabestatus genügt nicht.

Neue Erkenntnis → Änderungsvorschlag mit Quellen und Auswirkungen → Nutzerbestätigung
an Inhaltshash → Masterpflege mit Versionskontrolle → Export/Prüfung → identisches
Release für beide Systeme. KI darf weder Master noch Aktivzeiger selbst umschalten.

## Sichere Auftragsbindung

Im bestehenden `load_case`-Erfolg später einen kurzlebigen serverseitigen Kontext
ausgeben: authentifizierte Nutzer-ID, Originalquellprofil, berechtigtes
Bearbeiterprofil, Ordner-ID, Schadennummer, Fallmetadatenversion, Dokumentmanifesthash,
Regelrelease, zufällige Kontext-ID und Tab-ID. Pro Tab eigener Kontext; keine einzelne
globale Sitzungsvariable, die andere offene Tabs unbemerkt umschaltet.

Jeder Start referenziert diesen Kontext. Server lädt Herkunft, Metadaten und Rechte
erneut; Clientwerte dienen nicht als Autorität. Vor jedem Ergebnis-/Speicherschritt
erneut prüfen. Fallwechsel invalidiert die lokale Generation und bindet eine neue
Kontext-ID; verzögerte Antworten verwerfen. Abbruch unterbindet weitere Schritte,
bereits gesendete KI-Aufrufe können dennoch abrechenbar sein. Ihr Ergebnis bleibt
am ursprünglichen Auftrag und darf nicht in einen anderen Fall übernommen werden.

Request-Key plus Hash der vollständigen Identität und Eingabe bildet die
Idempotenzbindung. Derselbe Key mit anderem Inhalt ergibt Konflikt. Ergebnisabruf
bleibt lesend. Berechtigungsentzug und Profilwechsel vor Worker-Schritten erneut
prüfen; Rechte Lesen, Entwurf, intern speichern, senden, freigeben und abschließen
bleiben getrennt. Externe Dokumente sind Daten, keine Steueranweisungen.

Geplante ergänzende Felder am vorhandenen `case_work_turns`: Auftragsart,
Kontext-ID/Snapshot, Quellenmanifesthash, Regelrelease, Berechtigungssnapshot,
Budgetreservierungs-ID und Ergebnisversion. Bestehende Auftrags-IDs und Verläufe
bleiben erhalten. Checkpoints referenzieren Originaldatei-/Ergebnis-IDs statt
Dateien in einer neuen Ablage zu duplizieren. Der zentrale Kostenledger ergänzt
die bestehende MariaDB um Hilfstabellen für Monatsbudget, Reservierungen und
Providerusage; er ist keine neue Fall- oder Auftragsdatenbank. Migrations- und
Rückfalltests sind vor einer späteren Freigabe erforderlich.

## Kostenmodell und technische Sperre

Reproduzierbare Kalkulation: `python tools/sv-assistent/costs.py`.
Alle Annahmen stehen in `planning.json`. Geprüfte Herstellerseiten, 17.10.2026:
[OpenAI API-Preise](https://developers.openai.com/api/docs/pricing),
[Modellübersicht](https://developers.openai.com/api/docs/models),
[ChatGPT-Abrechnung](https://learn.chatgpt.com/docs/pricing).

Planungsmodell: bedarfsweise kleine Extraktion, ausgewogene Ausarbeitung und
separate Prüfung. Standardpreise ohne Cache-/Batch-Rabatt; Eingabetarife gelten
für die angegebenen begrenzten Einzelaufrufe. Tokenannahmen schließen wiederholten
Kontext und Ausgabetokenbudgets ein; keine gemessene durchschnittliche Fallrechnung.

| Fallprofil | Modellierte Zusatzkosten je Fall | 10/Monat | 50/Monat | 100/Monat |
|---|---:|---:|---:|---:|
| Klein | 0,1241 € | 1,24 € | 6,20 € | 12,41 € |
| Mittel | 0,2529 € | 2,53 € | 12,64 € | 25,29 € |
| Umfangreich / langer Bericht | 0,9189 € | 9,19 € | 45,95 € | 91,89 € |

Enthaltene Planungsfaktoren: 1 USD = 1 EUR als explizite Rechenannahme, kein
abgerufener Wechselkurs; 10 % Regionalverarbeitung, 19 % Steuerreserve und 20 %
Wiederholungsreserve. Echte Konditionen, Region/Zulassung und steuerliche Behandlung
sind vor Freigabe festzustellen. OCR-/Bild-/Audio-Mehrkosten und weitere Iterationen
benötigen eine eigene Vorabbudgetierung. 100 mittlere Fälle passen rechnerisch,
aber mit wenig Reserve; 100 umfangreiche Fälle nicht. Keine Qualitätsgarantie durch
günstigen Tarif. Auswahl erst nach fachlicher Evaluation; heute keine Aktivierung.

Zusätzliche Fixkosten können bei ausreichender vorhandener VPS-Kapazität 0 € bleiben.
Rechnen, Quellen-/Dublettenprüfung, Vollständigkeitslisten, manuelle Entwürfe und
simulierte KI benötigen keine Modellgebühren. Bestehendes Hosting/Work ist bereits
bezahlter Bestand und keine unbegrenzte kostenlose API. Entwicklung und Pflege sind
separater Aufwand. Kein neuer Vektor-, Browser- oder Queue-Dienst erforderlich.

Erforderlicher späterer Budgetpfad in derselben MariaDB, kein neuer Datenbankdienst:

1. Kostenfreigabe standardmäßig AUS, genehmigtes Budget zunächst 0. Planungsziel
   maximal 30 €/Monat; nur ausdrückliche Freigabe setzt tatsächlichen Betrag >0.
2. Ein zentraler Gateway für **alle** kostenpflichtigen Portalpfade, einschließlich
   Altanalyse, Belege, OCR, Dokumente und Wiederholungen. Separate Schlüssel-/Projekt-
   Nutzung, sonst ist ein Gesamtlimit durch parallele unbekannte Nutzer nicht belegbar.
3. In Transaktion Monatszeile `SELECT ... FOR UPDATE`, gebuchte Beträge und offene
   Reservierungen prüfen. Obergrenze je Aufruf vor Netzstart reservieren, inklusive
   vollständigem Input, maximaler Output-/Reasoning-Tokens, Tarifversion und Zuschlägen.
   Betrag als ganzzahlige Mikro-/Centwerte aufrunden, keine Float-Budgetrechnung.
4. Unique Operation-ID, atomare Reservierung und genau ein Dispatch. Bei Fehler
   vor Dispatch freigeben; bei Timeout nach Dispatch Status UNKNOWN und Reservierung
   halten, bis Providerusage abgeglichen ist. Kein automatischer Blind-Retry.
5. Istverbrauch je Providerrequest speichern; Differenz erst nach sicherer Abrechnung
   lösen. Monat Europe/Berlin fix am Dispatch, auch über Monatsgrenze; keine
   Preis-/Modelländerung ohne neue Tarifversion. Eingabevolumen vorab zählen; bei
   nicht begrenzbarer Bild-/Toolkostenbewertung sperren.
6. Keine Reservierung bei fehlender Freigabe, unbekanntem Tarif, ungesicherter
   Tokenobergrenze, nicht erreichbarem Ledger oder ausgeschöpftem Budget. Selbst
   Administratorrolle darf den Gateway nicht umgehen. Anbieterbudget zusätzlich,
   nicht als Ersatz für die eigene Transaktion.

Dieses Paket implementiert die Kostenplanung, **noch keinen produktiven Budgetgateway**.

## IONOS, Anbieterwechsel und Originalformulare

Der Code sieht PHP-CLI plus `/usr/bin/setsid`, MariaDB/InnoDB und bestehende
Auftragstabellen vor. Die Projektübergabe dokumentiert einen passenden VPS.
Das trägt einen schlanken Auftrag-pro-Klick-Ansatz ohne zusätzlichen Dienst.
Vor Integration müssen echter Webbenutzer, CLI-Berechtigung, Laufzeit-/Speichergrenzen,
abgebrochene Prozesse und Konkurrenz auf dem tatsächlichen Host geprüft werden.
Keine dauerhafte neue Browserautomation auf dem VPS starten.

Ein kleiner Provideradapter soll `extract`, `draft`, `review`, Usage und Fehlerklasse
vereinheitlichen. Keine Providerlogik in UI/Fachrechnung. OpenAI ist wegen bestehendem
Responses-Pfad der kleinste Integrationsschritt; andere Anbieter bleiben austauschbar.
Kein Training/Fine-Tuning als Voraussetzung der Wissensübernahme. Zunächst lokale
Textextraktion und gezielter Abruf mit Quellabdeckung, kein blinder Vollkorpus je Fall.

Die bestehende Claimsforce-Brücke bleibt einziger Integrationspfad. PR #402 enthält
Kandidaten zur Stabilisierung; nichts davon ist Voraussetzung dieses Offline-Pakets.
Eine spätere Codeintegration muss dessen tatsächlichen Merge-/Releasezustand neu
prüfen. Alle hier neuen Dateien liegen außerhalb seiner geänderten Pfade; das
beweist Dateikompatibilität, keinen realen Ablauf nach gemeinsamer Integration.
Native SE/ZB verwenden nur Originalformulare. Reale Feldanbindung, erlaubter Save-Weg,
Speicherbeleg und unabhängiges inhaltliches Rücklesen bleiben Freigabevoraussetzungen.

## Erste Version und Abnahme

Der vorhandene Bereich wird später zu „SV-Assistent“: vier Aktionsbuttons,
Freitext, Fallnummer/Profil, Regelstand, Quellenstand, Kostenobergrenze und
Auftragsstatus. Auf Desktop mehrspaltig, mobil eine Spalte mit umbruchfähigen
Buttons/Quellen. Vor Fallöffnung Aktionen deaktiviert. Kein Start beim Aufklappen,
keine automatische Notizabfrage und keine Statusabfrage mit Nebenwirkungen.

Reihenfolge: (1) Quellenrelease und Fallbindung, (2) rein deterministische Checks,
(3) isolierte Mock-Anbindung in genau dieser Fall-Work, (4) fachlicher Pilot mit
synthetischen Akten, (5) budgetierter API-Pilot nur nach gesonderter Freigabe.
Einzelne Kandidatenregeln reichen nicht für das Versprechen vollständiger Fachkompetenz.

| Künftiger Abnahmefall | Muss-Ergebnis |
|---|---|
| Kein serverseitig geladener Fall / nur alter Storage | Kein Auftrag möglich |
| A → B, verspätete Antwort aus A | Keine Anzeige/Speicherung in B |
| Andere Nutzer-/Profil-/Quellzuordnung | Serverseitige Ablehnung, auch im Worker |
| Zwei Tabs und geteilte berechtigte Bestände | Kontext und Rechte je Tab erhalten |
| Manipulierte Dokumentanweisung | Keine Rechte-/Empfänger-/Regeländerung |
| Doppelclick, Prozessabbruch, verlorene Antwort | Keine zweite Bearbeitung, kein Doppelimport |
| Fehlender/ersetzter Beleg, OCR-Lücke | Quellenlücke sichtbar; keine Vollständigkeitsbehauptung |
| Unbekannte Zahlung, KVA/Rechnung-Überlappung | Keine erfundene Null, kein doppelter Ansatz |
| Konkurrierende Kostenreservierungen an 30-€-Grenze | Nur budgetgedeckte Dispatches |
| Timeout, Monatswechsel, Tarifwechsel | Reservierung bleibt korrekt gebunden |
| Regelrelease geändert / unbestätigter Vorschlag | Kein vermischter/ungeprüfter Regelstand |
| Native Form gespeichert, Rücklesen unklar | Erfolg gesperrt, kein Blind-Retry |

Diese Portal-/Fachtests sind Anforderungen und wurden in diesem Paket nicht als
neuer Portalworkflow ausgeführt. Ausgeführt wurden Offline-Tests für Inventar,
Quellenbehandlung und Kostenrechnung. Keine Fall-/Claims-/Serverdaten geändert.

## Werkzeuge und Rücknahme

`inventory.py --root DIR --out PRIVATE_DIR` erfasst lokale Quellstände mit SHA256,
Fundstellen und Lücken. Google-native Dateien benötigen Connector-Export; PDF-Text
ist keine Sichtprüfung; Office-Formeln werden nicht gerechnet und Makros nicht
ausgeführt. Extrakte/Manifeste können vertraulich sein und gehören niemals ins
öffentliche Repository. Lokalsynchronisation beweist keine vollständige Cloud-Inventur.

Prüfung: `python -m unittest discover -s tools/sv-assistent -p 'test_*.py' -v`.
Optional für PDF/Office-Extraktion: vorhandene lokale Pakete `pypdf`, `openpyxl`.
Es gibt keine Netzwerk- oder API-Funktion in diesen Werkzeugen.

Rücknahme: Branch/PR unübernommen lassen. Keine Migration, keine produktiven
Dateiänderungen und kein Deployment wurden ausgeführt. Phase 2 erhält erst dann
eine produktionsnahe Anbindung, wenn die dokumentierten Quellen-/Kontext-/Budget-
und Laufzeitbedingungen in isolierter Umgebung belegt sind.
