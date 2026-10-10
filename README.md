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
im bestehenden Fall gespeichert. Seit der Betreiberanweisung vom 10.10.2026
werden auch Vorgaben ausschließlich aus dem geprüften IONOS-Dateibestand gelesen,
ohne Google-Authentifizierung oder Drive-Fallback. Ausgangspunkt ist die bereits
konfigurierte Wissensbasis (ID aus `GOOGLE_DRIVE_KNOWLEDGE_FOLDER_ID`, bestehende
Standard-ID wie beim bisherigen Wissenszugriff). Zusätzlich werden alle vorhandenen
Bereiche `00_Standards_Regeln` und `SV-Netzwerk-Projekt` rekursiv berücksichtigt.
Historische Drive-Ordnernamen sind keine Voraussetzung für eine anders strukturierte
IONOS-Ablage. `MASTER-ARBEITSSTANDARD.md` muss vorhanden sein. Gemeinsam erreichbare
Originale werden nur einmal gelesen; es gibt keine Auswahl der ersten Regeln.
MD/TXT werden unverändert eingelesen, PDF- und Office-Vorgaben werden zuvor
quellentreu durch die API ausgelesen. Unlesbare Vorgaben und Größenüberschreitungen
sperren die Analyse. Die maschinelle Transkription ersetzt keine layoutgetreue
Originalvorlage und muss fachlich geprüft werden.

Unter `/intern/versicherungswissen/` können Administratoren den aktuellen
IONOS-Regelbestand prüfen und MD/TXT/PDF/DOCX/XLSX/PPTX-Originale importieren.
Der Import verwendet ausschließlich die vorhandene Wissensbasis und legt
gewählte Unterbereiche bei Bedarf an. Identische Dateien werden wiederverwendet;
andere gleichnamige Fassungen benötigen eine ausdrückliche Ersetzungsbestätigung
und werden durch die bestehende IONOS-Versionssicherung geschützt.
Jeder Import wird durch erneutes Lesen und SHA-256-Abgleich bestätigt.
Die Originale liegen im geschützten IONOS-Dateispeicher, nicht in öffentlich
abrufbaren Website-Dateien. Der Import ersetzt keine Prüfung, ob alle fachlich
erforderlichen Vorgaben und Vorlagen vorliegen.

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
