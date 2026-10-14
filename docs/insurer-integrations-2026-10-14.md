# Beauftragungswege: Stand 14.10.2026

## Umgesetzt

Die Versichererseite verlinkt einen direkten Auftrag mit Unterlagen über das bestehende Schadenformular und eine eigene Abstimmungsseite für GDV, BiPRO und KUSS. Die Auswahl wird aus einer festen Kanalliste vorausgefüllt. Das neue Formular verarbeitet Dienstleister-Verknüpfungsanfragen über den bestehenden PHP-Mailhandler an info@sv-netzwerk.eu; es aktiviert keine externe Schnittstelle und legt keine externen Benutzer an. Keine Zugangsdaten oder Falldaten werden auf der öffentlichen Seite hinterlegt.

## Voraussetzungen für echte Fremdsystem-Anbindungen

- GDV Schaden-Service: Teilnahme/Leistungsumfang, Dienstleisterkennung, freigegebene Authentisierung und aktuelle technische Spezifikation sowie Testzugang. Der bereitgestellte GDV-Zugang verweist auf Z@Online (Zentralruf); dies ist eine Kfz-Versichererauskunft und kein Nachweis einer Schaden-Service-Teilnahme.
- BiPRO: Versicherer und technischer Ansprechpartner, tatsächlich angebotener Schadenprozess, Normgeneration und Release, freigegebener Endpoint mit Schemas, Authentisierung und Berechtigungen, Testumgebung. Norm 503 bezeichnet Schadenservice; Norm 430.5 schadenbezogene Übermittlung. Eine vorhandene VHV-Maklerschnittstelle belegt keine Sachverständigen-Beauftragungsstrecke.
- KUSS: vertragliche Dienstleisterfreischaltung und das korrekte Profil im ServicePortal. Der vorhandene Browser-Brücken-Scan liest die angemeldete Sitzung; er ist keine öffentliche API für neue Aufträge.

## Abnahme für automatischen Eingang

Für jeden freigeschalteten Kanal einen Testauftrag mit eindeutiger Referenz senden, Eingangsquittung und Unterlagen vollständig prüfen, Wiederholung ohne Duplikat testen und Mandant/Sachverständigenzuordnung samt Zugriffstrennung nachweisen. Ein Link oder erfolgreicher Website-Build gilt nicht als erfolgreiche Schnittstellenanbindung. Bestehende personenbezogene Zugangsdaten bleiben vertraulich und werden nicht als gemeinsamer Dienstleisterzugang verwendet.

## Offizielle Grundlagen

- https://www.gdv-dl.de/services
- https://www.zentralruf.de/fileadmin/media/pdf/Verfahrensbeschreibung_ZAOnline.pdf
- https://bipro.net/ueber-bipro/bipro-normen/
- https://www.kussgmbh.de/service-portal.html
