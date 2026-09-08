# WhatsApp-Integration für Ortstermine

## Freigegebene Profile

Die WhatsApp-Anbindung ist ausschließlich für folgende Sachverständigenprofile vorgesehen:

| Profil | Geschäftsnummer | Servervariable für die Meta Phone Number ID |
| --- | --- | --- |
| Christian Wächter | +49 7367 3103045 | `WHATSAPP_CHRISTIAN_PHONE_NUMBER_ID` |
| Holger Roth | +49 173 1645162 | `WHATSAPP_HOLGER_PHONE_NUMBER_ID` |
| Marc Schütt | +49 2392 6592751 | `WHATSAPP_MARC_PHONE_NUMBER_ID` |

Susanne Wächter und Jens Maurer erhalten kein WhatsApp-Profil.

## Sichere Inbetriebnahme

Die drei vorhandenen WhatsApp-Business-App-Konten dürfen nicht abgemeldet oder über eine normale Nummernmigration ausgetauscht werden. Die Anbindung erfolgt über das von Meta bereitgestellte Coexistence-/Embedded-Signup-Verfahren. Bis zum Abschluss dieses Vorgangs zeigt das Portal für das jeweilige Profil den Status `Meta-Coexistence noch nicht verbunden` und verhindert den Versand.

Christian, Holger und Marc erhalten im Bereich `Ortstermin planen` jeweils den Knopf `WhatsApp verbinden`. Der Ablauf prüft serverseitig, ob exakt die für das Profil hinterlegte WhatsApp-Business-Nummer ausgewählt wurde und ob Meta `is_on_biz_app=true` meldet. Ohne diese ausdrückliche Coexistence-Bestätigung wird die Verbindung verworfen, damit die bestehende WhatsApp-Business-App nicht versehentlich durch eine normale Migration ersetzt wird.

Erforderliche Servervariablen:

- `WHATSAPP_ACCESS_TOKEN`
- `WHATSAPP_APP_SECRET`
- `WHATSAPP_META_APP_ID`
- `WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID` mit aktiviertem Business-App-Onboarding/Coexistence
- `WHATSAPP_TOKEN_ENCRYPTION_KEY` (eigener, langer Zufallswert)
- `WHATSAPP_VERIFY_TOKEN`
- `WHATSAPP_APPOINTMENT_TEMPLATE`
- `WHATSAPP_TEMPLATE_LANGUAGE` (Standard `de`)
- `WHATSAPP_GRAPH_VERSION` (Standard `v25.0`)
- die jeweilige profilbezogene Phone Number ID aus der Tabelle oben

Die globale Phone Number ID und `WHATSAPP_ACCESS_TOKEN` bleiben nur als Übergangsfallback bestehen. Nach einer erfolgreichen Selbstverbindung werden Phone Number ID, WABA-ID und das verschlüsselte profilbezogene Zugriffstoken in `whatsapp_profile_connections` verwendet. Ein Zugriffstoken wird nie an den Browser zurückgegeben.

Webhook-Adresse: `https://www.sv-netzwerk.eu/intern/api/whatsapp-case.php?action=webhook`

Die freigegebene Terminvorschlagsvorlage erwartet vier Textparameter in dieser Reihenfolge: Schaden-Nr., Datum/Uhrzeit, Besichtigungsadresse und Name des Sachverständigen.

## Fallzuordnung und Dateien

Jeder aus dem Portal versandte Terminvorschlag verknüpft Empfängernummer, Sachverständigenprofil, Fallordner und WhatsApp-Nachrichten-ID für 30 Tage. Eine Antwort wird zuerst über die konkrete Antwort-ID und nur ersatzweise über genau eine aktive Rufnummern-/Fallkombination zugeordnet. Bei mehreren möglichen Fällen bleibt sie im Status `unassigned` und wird nicht automatisch abgelegt.

- Bilder werden in `02_Fotos` gespeichert.
- eindeutig als KVA, Angebot oder Rechnung bezeichnete Dokumente werden in `04_Rechnungen_KVA` gespeichert.
- sonstige zulässige Dokumente werden in `07_Korrespondenz` gespeichert.

Der Webhook akzeptiert ausschließlich korrekt mit dem Meta-App-Secret signierte Ereignisse. Dateien sind auf 25 MB und eine feste Positivliste von Bild-, PDF-, Word- und Excel-Formaten begrenzt. Die Zugangsdaten liegen ausschließlich in der Serverumgebung und werden nie an den Browser ausgeliefert.
