# Private Angebote und Aufträge

## Nutzerauftrag und Zugang

Angebot anlegen, speichern, als PDF herunterladen/drucken, nach separater
Versandprüfung mailen und nach bestätigter Beauftragung in einen Auftrag mit
eigener Nummer überführen. Erstellung ausschließlich Christian, Susanne und
Katja (exakte Konten in private-business-access.php). Keine Freigabe durch Rolle
oder Anzeigename. Seite, API und bisherige Auftragserstellung prüfen dieselbe
Freigabe. Bestehende Lesebereiche bleiben erhalten; keine Erweiterung der
Versicherungsfallberechtigungen.

## Vorlagen und Preise

Maßgeblich ist die vom Nutzer nachgereichte Fassung
`Schlipf_Westhausen/Sonstige Unterlagen/Unterschrieben zurück an Gutachter.pdf`.
Netto: Begutachtung 220 EUR/h, Fahrtzeit 130 EUR/h, KFZ 0,98 EUR/km,
Nebenkosten 40 EUR; alle Werte editierbar. Originale und Unterschriften werden
nicht geändert oder übernommen. Schütt-Logo primär, Netzwerk-Logo kleiner.
Rechnungs- und Terminadresse sind getrennt. Die Route verwendet die Terminadresse;
Hin- und Rückweg sowie Anzahl Termine werden für Zeit und Kilometer berücksichtigt.

## Rechtliche Durchsicht, Stand 17.10.2026

Abgleich mit amtlichen Quellen:

- https://www.gesetze-im-internet.de/bgbeg/art_253anlage_1.html
- https://www.gesetze-im-internet.de/bgbeg/art_253anlage_2.html
- https://www.gesetze-im-internet.de/bgb/__356.html
- https://www.gesetze-im-internet.de/bgb/__357a.html
- https://www.gesetze-im-internet.de/bgb/__309.html
- https://www.gesetze-im-internet.de/bgb/__631.html

14-tägiges Verbraucherwiderrufsrecht, Erklärung per Brief oder E-Mail,
Musterformular und separate, zunächst leere Erklärungen zum vorzeitigen Beginn.
Erlöschen erst bei vollständiger Leistung und Vorliegen der gesetzlichen
Voraussetzungen. Wertersatz anteilig. Pauschaler Ausschluss einfacher
Fahrlässigkeit durch gesetzliche Haftungsregeln ersetzt; keine zwingende
Einschreibepflicht oder automatische Vollmacht. Gegenstand und Vergütung müssen
im konkreten Auftrag geprüft werden. Kein Erfolg im Rechtsstreit oder gegenüber
dem Versicherer zugesagt. Die Maske ist intern; sie schließt keinen Vertrag durch
einen öffentlichen Kunden-Checkout ab.

## Persistenz und Versand

Nummernreservierung mit eindeutigen DB-Schlüsseln; Zufallsblock fünfstellig,
Jahrespräfix, keine Wiedervergabe. Änderungen mit Versionskonfliktprüfung.
PDF-Paket, Einzelunterlagen und Datenstand je Revision im vorhandenen
Privatgutachten-Bestand; Download und Mail verwenden gespeicherte Revisionen.
Dateiablage durch Rücklesen und SHA-256 bestätigt. Kein Überschreiben
anderslautender gleichnamiger Dateien. Versand mit eindeutiger Kennung und
Sperre paralleler Vorgänge; tatsächliche Sendebestätigung aus Outlook und
zusätzliche EML-Ablage getrennt vom bloßen Übergeben an Outlook.

Automatisierte Prüfungen: Nummernkollision, Retry, Profilabgrenzung,
Versionskonflikt, Überführung, Rundfahrtberechnung, Preise, PDF-Bestandteile und
Kontenfreigabe. Keine Kundenmail als Funktionstest versenden.
