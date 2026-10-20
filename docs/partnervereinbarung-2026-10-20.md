# Partnervereinbarung im Prüfportal

Auftrag: kostenloses fachliches Netzwerk, konkrete Anfrageweitergabe mit vorab
vereinbarter prozentualer Provision. Keine Auftragsgarantie oder Börse. Nutzer
bestätigt am 20.10.2026 das Sachverständigenbüro Marc Schütt e.K. als derzeitigen
Vertragspartner; eigene UG voraussichtlich Januar 2027 ist nur Planung.

Vor Arbeitsbeginn: Bootstrap vom Desktop, Cloud-Master 4.31 vom 20.10.2026,
Portal-Übergabe vom 12.10.2026, aktuelle Repo-AGENTS und Deployment gelesen.
Sauberer isolierter Stand auf origin/main; bestehende Arbeitsstände nicht verändert.

## Inhalt und Grenzen

Vorlage 2026-10-20.1: B2B-Rahmenvertrag, kostenlose Aufnahme, keine Annahmepflicht,
keine Exklusivität, Einzelfreigabe, Prozent vom eingegangenen eigenen Nettohonorar
ohne Umsatzsteuer/Auslagen/Fremdkosten, Teilzahlung und Rückzahlung, Abrechnung,
Qualifikation und Unabhängigkeit, Vertraulichkeit, Datenschutzinformation,
gesetzliche Haftung, Kündigung, keine automatische Vertragsübernahme durch UG.
Anlagen: Auftrags-/Provisionsbestätigung und getrennte Profil-/Bildfreigabe.
Keine automatische Annahme, Unterschrift, Provision oder Veröffentlichung im Portal.

Firmierung/Adresse/Register aus Nutzerbestätigung und öffentlichem Impressum:
https://sv-schuett.de/impressum (20.10.2026). Orangefarbenes SV: vorhandenes originales
Logo `public/intern/assets/private-business/sv-netzwerk-eu-logo.jpg`, kein Nachbau.

Rechtsquellen (20.10.2026 geprüft, keine anwaltliche Einzelfallprüfung):

- https://www.gesetze-im-internet.de/bgb/__652.html (Auftragserfolg/Provision)
- https://www.gesetze-im-internet.de/bgb/__307.html (Transparenz/AGB-Kontrolle)
- https://www.gesetze-im-internet.de/bgb/__305b.html (Individualabrede)
- https://www.gesetze-im-internet.de/bgb/__306.html (unwirksame AGB)
- https://www.gesetze-im-internet.de/zpo/__38.html (keine pauschale Gerichtsstandsklausel)
- https://eur-lex.europa.eu/eli/reg/2016/679/oj/deu (insbesondere Art. 6, 13, 26, 28)

Die Bezeichnung als Netzwerk ersetzt keine rechtliche Einordnung der tatsächlichen
Tätigkeit. Tatsächliche Zusammenarbeit muss der beschriebenen Selbstständigkeit
entsprechen. Berufsrechtlich unzulässige Provisionen sind ausgeschlossen. Eine
Garantie umfassender Rechtssicherheit oder eine anwaltliche Freigabe wird nicht behauptet.

## Technik und Prüfung

`/intern/partner/`: authentifiziert über bestehenden PHP-Seitenschutz. Eigene
Entwürfe je Benutzer-ID in bestehender Portal-Datenbank; keine Freigabe fremder
Fallbestände, keine Änderung vorhandener Tabellen oder Konfiguration. Neue Tabelle
`partner_contracts` wird additiv angelegt. API mit Authentifizierung, CSRF,
Prepared Statements, Besitzerprüfung und optimistischer Revisionierung. Kein
HTML aus Eingaben; Ausgabe escaped. Soft-Archivierung mit Rückgängig-Funktion.
PDF und HTML-Vorschau entstehen aus demselben Vertragsinhalt. Unvollständige
Pflichtangaben sichtbar als Entwurf. Speichern schließt keinen Vertrag.

Test: `php -d extension=pdo_sqlite tests/partner_contract_test.php`.
Zusätzlich PHP-Lint, JS-Syntax, voller Produktionsbuild und sichtbarer Live-Test.
Deployment ausschließlich über bestehenden Portalworkflow mit Domain-/Root-Probe;
keine Änderung am Deploymentverfahren, .env, SharePoint oder Claimsforce.

Live-Nachweise werden nach Veröffentlichung ergänzt; Quellcode oder grüne Tests
allein sind kein bestätigter Produktivbetrieb.
