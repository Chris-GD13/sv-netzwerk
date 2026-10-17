# SV-Assistent: integrierter begrenzter Betrieb

Freigabe 17.10.2026: Veröffentlichung/Merge und notwendige Verbindungen; zusätzliche KI-Kosten maximal 30 EUR monatlich. Keine Claimsforce-Schreibaktionen, Zahlungen oder externen Versendungen.

## Oberfläche und Zuordnung

Unter `/intern/versicherungsfaelle/` führen Menüs zum SV-Assistenten, zur bisherigen Fall-Work, zu manuell abrufbaren Claimsforce-Notizen und zu Richtlinien. Vier Aufgaben plus freie Anweisung, Historie und manuelle Work-Übergabe sind integriert. Beide Wege verwenden dieselbe Akte. Keine automatische Work-Schnittstelle wird behauptet.

Der Assistent übernimmt den tatsächlich geöffneten Fall erst auf Klick. Keine automatischen Notizabrufe oder Analysen. Benutzer, Profil, Sitzung, Tab, Aktenrevision und Regelstand werden serverseitig geprüft. Der Produktionsadapter prüft aktuelle Benutzeraktivität und Ordnerinhaberschaft nach bestehenden Profilregeln erneut. Fehler werfen Exceptions statt PHP zu beenden: Bei Berechtigungsverlust während des Aufrufs wird Verbrauch gebucht, die Antwort verworfen. Die bestehende Produktionskonfiguration wird nicht ersetzt.

## Gemeinsames Fachwissen

`Verbindungen prüfen` kontrolliert IONOS-Regelablage, aktuellen Drive-Master und vorhandenen OpenAI-Schlüssel, ohne Geheimnisse auszugeben. Das private `SV-Assistent-Regelrelease.txt` enthält Quellen-ID, Freigaben und Master-SHA256; es gehört ausschließlich in private Ablagen. Der frische Drive-Originalinhalt muss vor Assistentenaktionen zum freigegebenen Hash passen. Änderungen sperren die Verarbeitung bis zur geprüften Freigabe.

`Gemeinsamen Master abgleichen` ist auf bestehende Regelverwalter beschränkt. Es aktualisiert vorhandene `MASTER-ARBEITSSTANDARD.md`-Kopien in der gemeinsamen IONOS-Regelablage aus Drive, mit vorhandener Speicherversionierung und Vergleichsschutz. Jede Kopie wird unabhängig zurückgelesen und gehasht. Keine Fallakte wird dabei verändert. Nicht freigegebene Fachregeln werden nicht automatisch verbindlich.

## Budget und Abrechnung

Jeder neue bezahlte Assistentenaufruf benötigt `SvAiBudgetLedger` und einen einmaligen, an seinen Payload gebundenen `SvAiPaidPermit`. Reservierungen und Übergänge sind atomar (SQLite BEGIN IMMEDIATE / MySQL FOR UPDATE). Das reale Monatskonto ist auf 30.000.000 Mikro-EUR begrenzt, getrennt von Simulation und vorhandenen KI-Funktionen. Die gemeinsame Auftragssperre verhindert parallele Bearbeitung desselben Falls.

Vor Netzwerkbeginn werden 1.100.000 Mikro-EUR reserviert. Fester OpenAI-Responses-Endpunkt, `gpt-6-luna`, Standardtarif, store=false, keine Tools, maximal 8.000 Ausgabetokens. Keine automatischen Wiederholungen. Konservative Budgetbewertung: 1 Mikro-EUR je Eingangstoken und 5 je Ausgangstoken; sie deckt den dokumentierten Gesamtkontext mit Sicherheitspuffer ab. Tatsächliche Tokenzahlen werden erfasst, Restreservierung freigegeben. Die EUR-Bewertung ist keine Anbieterrechnung oder Wechselkursabrechnung. Andere Projektverbraucher werden durch dieses Portalbudget nicht begrenzt.

Nach Timeout/Prozessabbruch oder fehlender Usage bleibt die volle Reservierung bestehen; keine erneute Ausführung bis zur nachweisbaren manuellen Abstimmung. Explizite Anbieterablehnungen werden als Fehler ohne Verbrauch erfasst. Ein Überlauf wird gebucht und sperrt Folgeaufträge. Spätestens **01.11.2026** ist eine Tarifprüfung erforderlich; danach sperrt der Adapter bis zur Aktualisierung.

Geprüft am 17.10.2026: [Modell](https://developers.openai.com/api/docs/models/gpt-6-luna), [Preise](https://developers.openai.com/api/docs/pricing), [Dateieingaben](https://developers.openai.com/api/docs/guides/file-inputs).

## Unterlagen und Grenzen

Originalbytes müssen den Hashwerten des Aktenmanifests entsprechen. Text, Bilder, PDF und Office-Dateien werden verarbeitet; XLSX/XLSM vollständig lokal als XML, auch nach Zeile 1.000. Keine Makros/Formelberechnung; Formeln und vorhandene Cachewerte bleiben erkennbar. Maximal 20 MiB und 250 Eingabeteile. Überschreitungen stoppen vor Kostenbeginn. Nicht lesbare Formate erscheinen ausdrücklich als Lücken in Ergebnis und Aufgabenliste. Große Akten benötigen später eine belegbare mehrstufige Verarbeitung.

Ergebnisse sind fachlich zu prüfende Arbeitsentwürfe mit Quellen- und Regelbezug. Keine Zahlungs-/Deckungsentscheidung oder Fallabschluss. Native SE-/ZB-Schreibaktionen bleiben gesondert freigabepflichtig.

## Nachweise

40 lokale synthetische Testgruppen, darin 27 Prüfungen des bezahlten Adapters mit simuliertem Transport. CI prüft zusätzlich MySQL-Reservierungen und Aufträge. Der sichtbare KI-Verbindungstest nutzt ausschließlich DEMO-SV-001 und legt keine echte Fallakte an. Tatsächliche Modellantwort, Verbrauch, freigegebene Reservierung sowie Deployment und mobile Darstellung werden separat im privaten Abschlussbericht belegt.
