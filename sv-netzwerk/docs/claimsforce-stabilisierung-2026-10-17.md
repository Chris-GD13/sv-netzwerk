# Claimsforce-Stabilisierung – Prüfstand 17.10.2026

Entwicklungsbasis: `4f8419c0e0eada26c97e570fd020ed37f22ca234`, Branch `codex/claimsforce-stabilisierung`.
Keine Installation, kein IONOS-Deployment, keine echten Claims-Schreibaktionen, keine Zahlungen und keine kostenpflichtigen Modellaufrufe.

## Viewer: Beobachtung und Eingrenzung

Im gesteuerten Chrome lädt der direkte Aufruf eines bestehenden SE-Originalformulars die Claims-Außenseite. Das Apryse-Frame bleibt zunächst weiß und erscheint in der Accessibility-Ausgabe als `about:blank`. Sein vorhandenes `src` verweist auf Apryse 11.11.0.

CDP verweigert die Diagnose mit `Raw CDP is unavailable while Browser Use is resolving a paused document response.` Die erfasste Fehler-/Warnkonsole war leer. Es konnte deshalb kein vollständiger CDP-Netzwerktrace aufgezeichnet werden.

Ein anschließend ausgeführter, ausschließlich lesender Vergleich im selben gesteuerten Browser zeigt:

| Navigation | Tatsächliches Ergebnis |
| --- | --- |
| Direkter SE-Formularaufruf | Weißes eingebettetes Frame |
| Vorhandener Zurück-Link → Fallübersicht → vorhandener SE-Öffnen-Link | SE gerendert; 66 Textboxen einschließlich Viewer-Steuerung und neun Checkboxen im Snapshot |
| Fallübersicht → vorhandener ZB-Öffnen-Link | ZB gerendert; 59 Textboxen einschließlich Viewer-Steuerung im Snapshot |
| CDP nach erfolgreicher SE-Darstellung | Weiterhin dieselbe Sperrmeldung |

Damit ist die pausierte Dokumentantwort **keine hinreichende Erklärung** für den weißen Viewer. Die interne SPA-Navigation ist ein tatsächlich erfolgreicher Wiederöffnungsweg für diese beiden Originalformulare. Ein kausaler Browser-/Serverfehler oder eine allgemeine Heilung aller Formulare ist nicht bewiesen. Die Zählwerte sind keine unabhängig bestätigten kanonischen SDK-Feldzahlen. Keine Feldwerte wurden geändert, keine Bearbeiten-/Speichern-/Anhängen-/Signieren-Aktion ausgeführt.

Die bestehende Brücke erhält einen ausdrücklich lesenden Öffnungsweg mit exakter Fall-/Formular-/Profilbindung, vorhandenen internen Links und begrenzter Wartezeit. Sie erzeugt keinen zweiten Browserdienst. Ein äußeres `tab.complete` und eine Canvas-Fläche gelten nicht als Formularbereitschaft. Der Probe findet den Viewer anhand Herkunft und nativer Route; dynamische IDs wie `webviewer-2` werden berücksichtigt. `basePath` wird aus dem tatsächlich verwendeten URL-Fragment gelesen.

## Native Speicherung: konkrete Grenze

`native-draft-simulation.mjs` bleibt ein **Offline-Simulationsmodul** und ist an keinen Claims-Schreibhandler angeschlossen. Seine Tests prüfen bestehende Formular-/Nachtrags-IDs, Uploadfehler vor Bestätigung, Inhalts-/PDF-/Anlagenrücklesen, Quellen-/Profilwechsel und Wiederanlauf ohne blindes erneutes Speichern.

Das ist kein implementierter oder erfolgreich getesteter produktiver SE-/ZB-Speicheradapter. Noch fehlen eine unabhängig bestätigte Bindung an die echte Apryse-SDK-Instanz, kanonische Feld-/Widgetrechte, das vollständige native Dokumentversions-/Konkurrenzprotokoll und ein gesondert genehmigter echter Speicher-/Wiederöffnungstest. Die im Browser sichtbaren Originale werden nicht durch eigene PDFs ersetzt. Die SDK-Schnittstellen orientieren sich an der [Apryse-Dokumentation](https://sdk.apryse.com/api/web/Core.AnnotationManager.html); ein Mock bestätigt keine echte SDK-Anbindung.

## Notizen und Datenflüsse

Der alte Renderer las `svnet-case` aus Browser-Speichern und konnte so Notizen ohne wirklich geladenen Fall anzeigen. Er entfernte und erzeugte den Notizbereich bei jeder Mutation von `vf-active`; andere Beobachter dieses Bereichs lösten dabei weitere lokale Ladeaktionen aus. Ein Selbstzyklus dieses einen Observers ist wegen seines `disconnect()` nicht bewiesen. Das bisherige `load_case` enthält selbst keinen externen Notes-GET. Der bisherige Vollimport rief dagegen `/claims/{id}/notes` automatisch ab.

Der neue Notizbereich liegt außerhalb des beobachteten aktiven Fallkopfs. Ein explizit bestätigter geladener Fall bildet den Kontext. Öffnen lädt keine neuen Claims-Notizen; ausschließlich der Button „Notizen aktualisieren“ startet einen korrelierten, begrenzten Abruf. Fall-/Profilwechsel räumen die Anzeige sofort auf, alte Antworten werden verworfen. Persistenz prüft die vollständige Fall-/Profilidentität nochmals serverseitig und liest den gespeicherten Stand zurück. Bestehende Notizen und Aufgaben bleiben erhalten. Die automatische Notes-Abfrage ist aus dem Vollimport entfernt.

## Dateispeicher und Quelldokumente

Die vorherige Upload-Deduplizierung verglich Name, Größe und Änderungszeit. Gleich große unterschiedliche Inhalte konnten dadurch als Dublette gelten. Änderungen werden mit tatsächlichen Inhalts-Hashes geprüft. Stabile Claims-Quelle und Original-Datei-ID werden getrennt von Dateinamen behandelt. Nicht eindeutig gebundene Altdateien werden nicht automatisch über einen Namen zugeordnet.

**Kompatibilitätsgrenze für Altimporte:** Enthält eine bestehende Akte noch nicht eindeutig gebundene Altdateien, sperrt der neue Originaldateiimport mit `LEGACY_SOURCE_BINDING_REQUIRED`. Ein Name, Größe, Datum oder eine vermutete Zuordnung genügen nicht zur Migration. Diese Akten benötigen eine kontrollierte, belegte Quellbindung vor dem nächsten Originaldateiabgleich. Es gibt keinen stillen Fallback auf das alte Verfahren. Dies ist eine relevante Verhaltensänderung und ein Integrationsgate, keine bereits abgeschlossene Bestandsmigration.

Bei explizitem Einzel-/Vollabgleich werden originale Dateibytes auch bei identischer Fall-/Listsignatur, Dateiname, ID, Größe und Änderungszeit erneut gelesen. Die tatsächliche Hashänderung steuert das Update der bereits gebundenen ID. Der schnelle Deltaimport bleibt ein metadatenbasierter Abgleich und beweist keine aktuelle Inhaltsidentität unverändert übersprungener Dateien. Generierte Importverzeichnisse und Nachrichtensicherungen sind als unterstützende Dateien markiert und vom Claims-Originalbestand getrennt.

Der vorherige `ionosWrite` konnte nach erfolgreichem Dateiaustausch und anschließendem SQL-Fehler alte Metadaten mit neuen Bytes hinterlassen. Das Paket ergänzt Schreibjournal, gehashte Vorversion, SQLite-Schreibsperre, Wiederherstellung und bestätigte Rückleseprüfung. Source-Updates verwenden Versions-/Hash-Vorbedingungen und erhalten die vorhandene Portal-Datei-ID. Unbekannte Index-/Dateistände sperren den Vorgang.

Die lokalen Prozessabbruchtests belegen Recovery bei erzwungenem PHP-Prozessende. Sie belegen keinen Stromausfallschutz für das reale Dateisystem und keine vollständige Migration sämtlicher historischen PHP-Altaufrufer auf zwingende Versionsbedingungen. Unbedingte ältere Schreibaufrufe benötigen weiterhin eigene Prüfung; pauschalen Schutz vor jedem menschlichen Parallelzugriff nicht behaupten.

Zusätzlich geprüft: Ein Profil-/Ordnerverschieben zwischen Berechtigungsprüfung und CAS darf keine ungeprüfte Zeile binden. Prüfung und Fingerprint verwenden denselben Datenbanksnapshot. Die Notizversionsprüfung verhindert auch Zeitstempelregression bei gleichem Text. Notizabruf und normaler Import schließen sich in beiden Startrichtungen aus.

## Verwendete Arbeitsgrundlagen und Work-Übernahme

- Führende Cloud-MD `MASTER-ARBEITSSTANDARD_Stand-2026-10-12.md`, tatsächlich geändert am 17.10.2026 um 12:51:36 UTC, Fassung 4.4, insbesondere neue Notiz-/Zahlungs-/Formularregeln.
- Projektunterlagen `00_START_HIER`, `01_PROJEKTZIEL_UND_ARCHITEKTUR`, `02_QS_UND_FACHREGELN`, `03_STATUS_UND_UEBERGABE`, `04_TESTPLAN_UND_FEHLER` aus dem bestehenden Drive-Ordner Claimsforce-Agent.
- Quellenabgleich der SV-Formulare vom 11.10.2026, Portalübergabe vom 12.10.2026 und Repository-AGENTS/Deploymentregeln.
- Tatsächlich verfügbarer lokaler Work-Kandidat `bridge-candidate.patch`, SHA-256 `c9a1aebb394c543f18f5cfa2afd6d3820619f542c4b0d437347cc3d6617f40f7`. Relevante Bereitschafts-, QS-, Source- und Speichermodule übernommen, geprüft und weiter korrigiert. Keine privaten Referenzakten oder Originalbelege ins Repository übernommen. Tests im PR verwenden synthetische Daten; historische private Referenztests wurden nicht als portable grüne Tests ausgegeben.

GF-Grenztests prüfen 24.999,99 / 25.000,00 / 25.000,01 Euro. Belegte verbundene Gebäude-/Hausratschäden zählen für die Berichtsschwelle zusammen; SE und Finanzjournal bleiben je Schadennummer getrennt. Freigaben gelten nicht als Zahlungsnachweise. Diese lokalen deterministischen Prüfungen ersetzen keine Engel-Fachabnahme.

## Prüfung und kontrollierte Integration

Die neue CI `claimsforce-stabilisation.yml` führt ausschließlich isolierte Tests aus und hat keine Deployment-Schritte. Lokaler Astro-Check und vollständige statische Astro-Erzeugung wurden ausgeführt; 1.047 Seiten wurden gebaut. Das entspricht keinem IONOS-Livetest des geänderten Codes.

Lokaler Browsernachweis der tatsächlichen Notiz-UI mit synthetischer Bridge/Persistenz: keine Anzeige trotz altem Storage ohne Fall, Fall A öffnet mit null Notes-GETs, verzögerte A-Antwort nach Wechsel nach B speichert nichts und verändert B nicht, manueller B-Abruf speichert genau einmal, Schließen entfernt die Notizen. Kein produktiver Portal-/Claims-Schreibtest.

Abschließender lokaler Lauf: **25/25 Testsuiten bestanden**. Enthalten sind sieben native Bereitschafts-/QS-/Simulationsgruppen, Notizen (15 Fälle), Source-Transport (17), echte Worker-Source-Schleife mit simuliertem Netz, Source-/HTTP-Handler (38), Storageintegrität (20), Snapshot-Rennen (5) und bestehende Import-, Profil-, Queue-, Rekon-, Fallmerge- und Dokumentprüfungsregressionen. Alle Source- und Storage-Schreibtests verwenden isolierte SQLite-/Dateifixtures. Die nachgeschärften Supporting-/Metadata-Snapshot-Prüfungen wurden zusätzlich erneut ausgeführt. Astro-Check: null Fehler, Warnungen oder Hinweise. `git diff --check` bestanden.

Vor Integration:

1. Draft-PR samt Tests und offenen Grenzen prüfen. Nicht automatisch mergen: ein Merge nach `main` würde die vorhandenen produktiven Deploymentworkflows auslösen.
2. In einer genehmigten Testumgebung den gesamten Patch, SQLite-/Dateispeicher und Brücke derselben Version verwenden. PHP benötigt `pdo_sqlite`, `mbstring` und `fileinfo`; Journaling-/Versions-/Lockverzeichnisse müssen für denselben Dienst schreibbar sein. Vorher Dateispeicher, Index, Versions- und Journalverzeichnis gemeinsam sichern und Wiederherstellung prüfen.
3. Mit synthetischen Fällen Notizwechsel, verzögerte Antworten, konkurrierende Source-Imports und Prozesswiederanlauf ausführen. Dann genehmigten Originalformular-Lesevergleich durchführen.
4. Erst nach eigener ausdrücklicher Freigabe echten nativen Speicheradapter anbinden und SE **und** ZB jeweils speichern, unabhängig wiederöffnen sowie Werte/Originalidentität nachweisen. Bei unklarem Speicherstatus nur rücklesen, keine neue Anlage oder Wiederholung.
5. Produktivfreigabe gesondert entscheiden. Journal-/Source-Metadaten nicht bei einem Rückfall blind entfernen oder allein alte PHP-Dateien über einen neueren Speicher kopieren. Rückfall vorab mit konsistentem Gesamtsnapshot prüfen.

Aktueller Stand: reviewbarer Entwicklungsstand; **keine Produktionsfreigabe und kein vollständiger nativer Schreibnachweis**.
