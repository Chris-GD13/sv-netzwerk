# MASTER-Arbeitsstandard SV-Netzwerk

Stand: 11.07.2026
Status: verbindlich

## 1. Pflichtlektüre vor jeder Aufgabe

Vor jeder SV-Netzwerk-, Website-, Google-Drive-, Upload- oder Projektaufgabe sind die aktuelle MASTER-Datei und die Projektstandards aus Google Drive zu suchen und zu lesen. Dies erfolgt ohne gesonderten Hinweis des Nutzers.

Maßgebliche Reihenfolge:
1. Google-Drive-Connector aktiv testen.
2. Aktuelle MASTER-Datei und PROJEKTSTANDARDS.md suchen und lesen.
3. Letzten funktionierenden Website- bzw. Uploadstand bestimmen.
4. Aufgabe vollständig ausführen.
5. Ergebnis technisch prüfen.
6. Vollständige Upload-ZIP erzeugen.
7. ZIP in Google Drive ablegen.
8. Ausschließlich den echten Download-/Drive-Link ausgeben.

## 2. Connector-First-Prinzip

Vor jeder Aussage über fehlende Berechtigungen, nicht verfügbare Funktionen oder unmögliche Ausführung sind die erforderlichen Connectoren aktiv zu testen. Vermutungen über fehlenden Zugriff sind unzulässig.

Erst wenn ein konkreter Ausführungsschritt nachweislich fehlschlägt, wird der tatsächliche technische Fehler benannt. Ein Profiltest allein ersetzt nicht die Prüfung von Suche, Download, Dateibearbeitung und Upload.

## 3. Ausführen statt diskutieren

Bei standardisierten Arbeitsabläufen gilt: zuerst testen und ausführen, erst danach bei einem realen Fehler abbrechen. Wiederholte Erläuterungen, Vorschläge oder Rückfragen ersetzen nicht die Ausführung einer klar definierten Aufgabe.

## 4. Vollständige Upload-ZIP

Jeder Website-Lauf erzeugt eine vollständige Upload-ZIP auf Basis des letzten funktionierenden MASTER-Stands. Teilpakete, Patch-ZIPs, nur ergänzte Einzeldateien oder reine Umbenennungen sind unzulässig.

Die ZIP muss so aufgebaut sein, dass Christian Wächter sie vollständig entpacken und den vollständigen Inhalt in den Webspace-Ordner `sv-netzwerk` hochladen kann.

Vor Ausgabe sind mindestens zu prüfen:
- sämtliche Dateien des Ausgangs-MASTERs sind enthalten,
- neue und geänderte Dateien sind enthalten,
- interne Links und Zielpfade sind plausibel,
- Sitemap und robots.txt sind aktuell,
- Versionsbezeichnung ist korrekt,
- ZIP lässt sich fehlerfrei öffnen,
- kein Platzhalter- oder Testinhalt ist enthalten.

## 5. Keine Platzhalterlinks oder Scheinergebnisse

Downloadlinks, Drive-Links, Erfolgsmeldungen und Versionsangaben dürfen erst ausgegeben werden, wenn die betreffende Datei tatsächlich erzeugt, geprüft und – soweit beauftragt – hochgeladen wurde.

## 6. Versionslogik

Der 06:00-Uhr-Lauf ist der maßgebliche Hauptlauf. Der 14:00-Uhr-Lauf ist ein ergänzender Zwischenlauf und erhält eine halbe Zwischenversion, beispielsweise v6.3 zu v6.35 oder v7.0 zu v7.05. Er darf die Hauptversionslogik nicht verschieben und keine Doppelstruktur erzeugen.

## 7. Google Drive als Single Source of Truth

Für SV-Netzwerk- und Website-Aufgaben ist Google Drive die Master- und Zielablage. Primär ist der Ordner `SV-Netzwerk-Projekt` zu verwenden; ersatzweise der aktuelle Website-/Masterstand unter `Meine Ablage/Webseite`.
