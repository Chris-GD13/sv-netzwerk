# SV-Netzwerk

Master-Repository für den vollständigen Website- und Plattformstand von [sv-netzwerk.eu](https://sv-netzwerk.eu/).

## Projektorganisation

Die verbindliche Projektdokumentation liegt in [`docs/`](docs/):

- [Projektregeln](docs/PROJECT_RULES.md)
- [Projektlog](docs/PROJECT_LOG.md)
- [Roadmap](docs/ROADMAP.md)
- [Entscheidungen](docs/DECISIONS.md)
- [Lessons Learned](docs/LESSONS_LEARNED.md)
- [Workflow-Standard](docs/WORKFLOW_STANDARD.md)
- [Architecture Decision Records](docs/adr/)

Rollen: Christian ist Product Owner, ChatGPT verantwortet Architektur und Projektsteuerung, Codex ist die Entwicklungsumgebung und setzt Änderungen direkt im Repository um.

## Technischer Einstieg

Das Astro-Projekt liegt in `sv-netzwerk/`.

Die interne Seite `/intern/aufgaben/` zeigt Outlook-Nachrichten aus „Zu erledigen“
als kompakte Tabelle mit Eingang, Schadennummer, Absender, Betreff, IONOS-Ablage
und Aktionen. Suche, Fallfilter und Sortierung ermöglichen gezieltes Abarbeiten;
Mailvorschauen werden nur auf Wunsch aufgeklappt. Erkannte Schadennummern
verlinken den Fall mit der konkreten Aufgaben-ID in einem neuen Tab, sodass die
Arbeitsliste erhalten bleibt. „Aufgabe bearbeiten“ öffnet ebenfalls die
fallbezogene Nachricht, „Erledigt“ verschiebt sie in den gleichnamigen
Outlook-Ordner. Der automatische IONOS-Abgleich zeigt seinen Stand je Nachricht
separat an und ersetzt keine fachliche Erledigung.
Die Schadennummernerkennung berücksichtigt auch geteilte Zifferngruppen wie
`26-165 840-0` und `26-165-840-0` sowie zweistellige Endungen wie
`61-1181377-91`. Die Fallzuordnung ignoriert reine Trennzeichenunterschiede,
behält aber sämtliche Ziffern einschließlich der Endung bei.
Mehrteilige Versicherer-Nummern wie `00-031-404193-0001` und
`408-53-25000238-1` bleiben vollständig erhalten, einschließlich führender
Nullen. Eine ausdrücklich als Schadennummer bezeichnete Nummer hat Vorrang
vor anderen Referenzen; Telefon-, Rechnungs- und Auftragsnummern werden nicht
als solche übernommen.
Durchgehende Ziffernfolgen wie `22671159490` werden bei ausdrücklicher
Schadennummer-Bezeichnung ebenfalls unverändert erkannt. Unbeschriftete
Ziffernfolgen werden nicht allein aufgrund ihrer Länge einem Fall zugeordnet.
Ohne Schadennummer werden Straße und Hausnummer aus der Nachricht im
bestehenden Fallbestand gesucht. Adresstreffer erscheinen als prüfpflichtige
Fallvorschläge mit Aufgabenbezug; sie lösen weder eine automatische Ablage noch
eine Neuanlage aus.

Im Versicherungsfall startet „Fall analysieren und ausarbeiten“ eine
serverseitige OpenAI-API-Analyse direkt auf derselben Seite. Die aktuelle
Outlook-Aufgabe einschließlich Datei-Anhängen und der IONOS-Fallbestand mit
Unterordnern werden berücksichtigt; zusätzliche Originale werden vor dem Start
im bestehenden Fall gespeichert. Alle MD-Dateien aus den eindeutig erreichbaren
Drive-Masterbereichen `00_Standards_Regeln` (einschließlich `ab sofort immer gültig`),
`00_KI-Wissensbasis` und `SV-Netzwerk-Projekt` werden bei jedem Auftrag neu geladen,
nicht auf eine Auswahl der ersten Regeln gekürzt. Fehlende Masterquellen sperren
die Ausarbeitung. Liegt `00_Standards_Regeln` ausschließlich innerhalb der
KI-Wissensbasis, wird dieser eindeutig erreichbare Ordner ebenfalls verwendet.
Gemeinsam erreichbare MD-Dateien werden nur einmal geladen; die Pfade der
verbindlichen Standards bleiben erhalten. Fehlermeldungen unterscheiden fehlende
Freigaben (keine Treffer) von mehreren gleichnamigen Ordnern.

Das Ergebnis enthält belegte Feststellungen, fachliche Bewertung, offene Punkte,
Arbeitsschritte, Regelprüfung und Antwortentwurf und wird als JSON-Entwurf im
IONOS-Berichte-Unterordner gespeichert. Status und Ergebnis lassen sich im
gleichen Fall-/Aufgabenkontext wieder laden. Die Antwort gelangt nur per
ausdrücklichem Klick ins E-Mail-Feld; Versand, Freigaben und Erledigen bleiben
manuell. Die API wird unabhängig vom ChatGPT-Business-Abo abgerechnet.

Originale werden in Paketen vollständig übergeben (max. 30 MB je Datei,
40 MB je Paket, 200 MB insgesamt). PDF/DOCX/XLSX/PPTX, Textformate und
PNG/JPEG/WEBP/GIF werden verarbeitet. Nicht unterstützte Formate, übergroße oder
unlesbare Quellen erscheinen ausdrücklich als Lücken. EML wird als MIME-Originaltext
gelesen; eingebettete Anhänge sind nur geprüft, soweit sie separat vorliegen.
MSG/ZIP werden nicht stillschweigend als verarbeitet ausgegeben. MD- und
Fallkontextgrenzen führen zu einem Fehler statt einer gekürzten Ausarbeitung.

```text
cd sv-netzwerk
npm ci
npm run validate:knowledge
npm run build
```

Deployment: GitHub Actions überträgt den Inhalt von `dist/` direkt in das bestätigte IONOS-Document-Root `/sv-netzwerk`. Die Live-Version wird über `/deploy-version.txt` verifiziert.
