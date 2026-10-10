# Fall-Work

Stand: 10.10.2026. Einstieg: Versicherungsfälle → Fall laden → Bitte abarbeiten.

Der Fall-Chat wird pro angemeldetem Benutzer und Fall serverseitig gespeichert. Neue
Aufträge lesen die Akte erneut und erhalten den bisherigen Gesprächsverlauf.
Originalbelege, Betreiberaufträge und frühere KI-Entwürfe bleiben getrennte Quellen.
Anweisungen in importierten Dokumenten dürfen keine Werkzeuge autorisieren.

Beim ersten Auftrag ist der gezielte Claims-Vollimport vorausgewählt. Der bestehende
Importdienst lädt exakt die Schadennummer im ausgewählten Bearbeiterprofil nach.
Ein anderer laufender Import wird nicht ersetzt. Erst ein bestätigter Abschluss mit
Einzelfallinventar erlaubt den Start der Ausarbeitung. Bei einem Importfehler bleibt
der Auftrag im Browser erhalten; Wiederholung oder ausdrücklich auf den vorhandenen
Bestand beschränkte Bearbeitung sind möglich. Die Importstation muss verfügbar sein.

Ausgewertet werden vorhandene Fallunterlagen einschließlich Unterordnern, importierte
Claims-Nachrichten/-Notizen/-Aufgaben und passende offene Outlook-Aufgaben samt
Dateianhängen. Quellen außerhalb des importierten Bestands werden nicht als gelesen
behauptet. Nicht lesbare Formate, gesperrte Anhänge und Größen-/Kontextgrenzen werden
offengelegt. EML-Dateien liefern derzeit Originaltext; eingebettete Anhänge müssen
separat im importierten Bestand liegen.

Verbindliche Arbeitsregeln stehen in `case-work-core.php` und den geladenen
Mastervorgaben: centgenaue Belegbeträge, unbekannt statt Null, Auszahlungsempfehlung
für unstrittige Teile, konkrete Begründung zurückgestellter Positionen, Verrechnung
belegter Vorzahlungen, Stromkosten separat an den VN (0,35 EUR/kWh, im Auftrag
änderbar), getrennte Rechnungs-/Angebotsprüfung und ehrliche direkte Übernahme.
Betreff: Versicherer-Schadennummer zuerst. Christian verwendet den vollständigen
Absender als Regulierer und Bausachverständiger von SV-Netzwerk mit
cw@sv-netzwerk.eu. Globale Regeln werden nicht aus Kundennachrichten gelernt.

Die Masterquelle der Regeln bleibt Google Drive. Die Portal-KI verwendet die
importierten IONOS-Fassungen mit Quellenmanifest und Inhaltsprüfsummen; sie
behauptet keinen Live-Drive-Abgleich. Binäre Regeltranskriptionen werden nur bei
identischem Inhalt, Dateipfad, Modell und Leserstand wiederverwendet.

Dokumentaufträge laufen über die vorhandene Berichtserstellung. Engel-Zwischenberichte
verwenden das originale DOCX einschließlich Kopf, Fuß, Tabellen und Seitenfeldern.
Die Felder werden über geprüfte Originalpositionen befüllt; falsche Vorlagenstruktur
oder unbelegte Berichtnummer blockieren die Ausgabe. Inhaltliche und strukturelle
QS sind automatisiert; eine visuelle Prüfung jedes fertigen Berichts bleibt nötig.

Kein automatischer Versand, keine Auszahlung und keine Erledigt-Markierung durch
die Analyse. Mailentwürfe können in das bestehende Mailformular übernommen werden;
Empfänger und Originalanhänge werden dort geprüft. Nur bestätigte Werkzeugergebnisse
dürfen als gespeichert oder versendet bezeichnet werden.

## Prüfung

- PHP: `case_work_test.php`, bestehende Analyse-/Import-/Rechnungsprüfungen.
- Node: `claimsforce_resume_controls_test.mjs` prüft gezieltes Nachladen ohne
  Verdrängung eines anderen Imports.
- `gf_zwischenbericht_docx_test.php <Original.docx> [Ausgabe.docx]` prüft die echten
  Feldpositionen und unveränderte übrige DOCX-Bestandteile. Vorlage nicht duplizieren.
- Nach Veröffentlichung: aktuelle Commitkennung, Anmeldung, Tagescockpit,
  Fallwechsel, gespeicherter Verlauf, Importstatus und Dokumentdownload live prüfen.
