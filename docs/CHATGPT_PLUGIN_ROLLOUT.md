# Persönliche ChatGPT-Schadenberichte

## Zielbild

Jeder Sachverständige installiert einmal das Workspace-Plugin **SV-Netzwerk Schadenberichte** und meldet sich dabei mit seinem eigenen ChatGPT-/Work-Zugang sowie seinem persönlichen Prüfportal-Zugang an. Das Plugin kann ausschließlich die im Portal diesem Benutzer zugeordneten Schadenfälle lesen.

Susanne Wächter ist ausgenommen. Für ihren Portalbenutzer wird kein Installationsdialog angezeigt; sie arbeitet weiterhin über den Zugang von Christian Wächter.

Es werden keine ZIP-Dateien, Programme oder ausführbaren Dateien an die Sachverständigen verteilt.

## Technischer Ablauf

1. Das Plugin wird im administrierten ChatGPT-Workspace veröffentlicht und für die vorgesehenen Mitglieder freigegeben.
2. Die dabei entstehende direkte Installationsadresse wird auf dem Webserver als `SV_CHATGPT_PLUGIN_INSTALL_URL` gesetzt.
   Die Adresse zum anschließenden Öffnen des installierten Plugins kann zusätzlich als `SV_CHATGPT_PLUGIN_LAUNCH_URL` gesetzt werden.
3. Beim ersten Portalbesuch öffnet der Sachverständige diese Adresse über **Plugin jetzt installieren**.
4. Die OAuth-Freigabe verbindet ChatGPT mit dem persönlichen Portalbenutzer. Zugriffs- und Aktualisierungstoken werden nur gehasht gespeichert.
5. Sobald die Verbindung erkannt wurde, erscheint der Einrichtungsdialog nicht mehr.
6. Bei Berichtsausgaben öffnet das Portal den persönlichen ChatGPT-Zugang und legt den fallbezogenen Arbeitsauftrag in die Zwischenablage. Formulare und Kalkulationen verbleiben in der Portalverarbeitung.

Ohne abweichend gesetzte Installationsadresse öffnet das Portal direkt das veröffentlichte Workspace-Plugin in der ChatGPT-Desktop-App. Eine manuelle Suche oder die Eingabe des Pluginnamens ist nicht erforderlich.

## Berechtigungen und Datenschutz

- `cases:read`: eigene Falldaten und eigene Fallunterlagen lesen.
- `cases:drafts.write`: nur auf ausdrückliche Anweisung einen neuen ungeprüften Textentwurf ablegen.
- Jede Fall- und Dateiabfrage wird serverseitig gegen den angemeldeten Portalbenutzer geprüft.
- Originalunterlagen, Falldaten, Freigaben und bereits versendete Dokumente werden nicht verändert.
- Binärdateien werden nur für die konkrete Auswertung und höchstens bis 15 MB ausgeliefert.

## Pilot und Freigabe

Vor der allgemeinen Freigabe mit einem normalen Schaden und einem SV-GF-Schaden testen:

- normaler Schaden: feste ClaimsForce-Reihenfolge und kopierfertiger Text;
- SV-GF: unveränderte Engel-/Originalvorlage;
- fremde Schaden-Nr.: kein Zugriff;
- Susanne: kein Installationsdialog;
- ein normaler SV: Dialog bis zur erfolgreichen persönlichen Verbindung.
