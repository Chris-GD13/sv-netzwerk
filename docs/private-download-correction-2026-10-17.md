# Korrektur: Netzwerklogo, 429 und PDF-Download

Nutzerkorrektur vom 17.10.2026: Maßgeblich ist das Original von
https://sv-netzwerk.de/sv-netzwerk/ (grau/violette Fahrzeugsilhouette, gelbes SV,
grauer Schriftzug Netzwerk.de). Das fälschlich verwendete dunkelblaue
Sechsecklogo ist aus dem neuen Formularmodul entfernt. Originalquelle:
`default.asp?qs_servlet=downloadIxServlet&rq_RecId=34&qs_fileId=4&qs_fileControl=A8EC2A26FA44C207AF6B986869ED79A2AF021F23&qs_lastModified=1653301795369&qs_imageSize=scaled%40500x325-clo`
unter der genannten Domain und dem genannten Pfad. Die JPEG-Datei im Modul ist
eine unveränderte Formatkonvertierung des heruntergeladenen Originals.

Die umfangreiche nachgelagerte Archivsortierung wurde bei tatsächlichem
Microsoft HTTP 429 angehalten. Sie darf nicht erneut ungedrosselt laufen.
Der vorbereitete Sortierer besitzt eine gemeinsame Ratenbegrenzung und
beachtet Retry-After; am Ende dieses Reparaturschritts bleibt sein tatsächlicher
Laufzustand gesondert nachzuweisen. Kein abgeschlossener Dokumentimport behaupten.

Auftragslisten verwenden einen auf Bearbeiter und konfigurierten Quellpfad
begrenzten IONOS-Datenbankcache. Bei einer vorübergehenden Drosselung bleibt der
zuletzt bestätigte Index mit sichtbarem Hinweis nutzbar. Anlage und Übernahme
invalidieren den Index. Das Öffnen einer Akte sortiert sie nicht mehr erneut um
und lädt nicht mehr ungeöffnet sämtliche Dokumentunterordner.

Generierte PDFs werden je Datensatz und Revision mit SHA-256 in der
Portal-Datenbank gesichert. Download und Vorschau verwenden diese authentifiziert
ausgelieferten Bytes ohne OneDrive-Abfrage. Die zusätzliche Cloud-Ablage erfolgt
getrennt und darf einen bereits gespeicherten PDF-Download nicht verhindern.
Bei unveränderten Eingaben entsteht durch Download/Druck keine unnötige Revision.
Historische gespeicherte Stände werden nicht stillschweigend ersetzt.

Der Klick zeigt sofort eine Vorschau mit Status, einen direkten HTTP-Download
mit Dateiname und Inhaltslänge und einen sichtbaren Download-Link. Der frühere
asynchron erzeugte Blob-Link wurde entfernt. Fehler erscheinen zusätzlich im
Aktionsbereich und im geöffneten Dialog. Neue, noch nicht in der Cloud
gespeicherte Angebote bleiben im Portal-Index sichtbar. Ein tatsächlicher
Mailversand wartet weiterhin auf die vollständige zusätzliche Ablage.

Regressionstests: PDF-Bytes/Integrität/Revision, 429-Fallback und Profilgrenzen,
sichtbarer PDF-Dialog, native Download-URL und sichtbarer Fehlerfall. Die
Freigabe der Erstellung bleibt Christian/Susanne/Katja vorbehalten.
