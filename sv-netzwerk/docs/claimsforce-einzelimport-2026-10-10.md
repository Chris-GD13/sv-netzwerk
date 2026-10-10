# ClaimsForce-Einzelimport: Umfang und Importstation absichern

Der Server speicherte einen manuellen Einzelauftrag mit Schadennummer korrekt,
akzeptierte aber auch Ergebnisse mit 26 Fällen. Offene zentrale Portal-Tabs
konnten denselben aktiven Auftrag erneut starten. Gespeicherte Browserläufe
wurden nur anhand von Job-ID und Profil wiederaufgenommen; Modus und
Schadennummer wurden nicht verglichen. Die Pause beendete nur den Serverstatus.

Version 1.4.58 bindet einen Auftrag an eine einzelne Portalstation. Eine atomare
serverseitige Sperre verhindert parallele Übernahmen. Ältere Stationen dürfen
keine Aufträge übernehmen oder deren Abschluss schreiben. Wiederaufnahme
erfordert identische Job-ID, Profil, Modus und Schadennummer. Vor jeder
Einzelfallübernahme müssen Suchtreffer und Detaildaten exakt passen.

Die Brücke übernimmt auch client-files und fallbezogene offene Aufgaben.
CLAIM_FILE-Mailanhänge werden über den Falldateibestand aufgelöst. Nachrichten
erhalten eindeutige Dateinamen; Notizen behalten Originaldaten. Aufgaben,
Notizen und Bestandszahlen werden im Fall gespeichert. Ein Einzelimport wird
nur bestätigt, wenn genau ein Fall erfolgreich aktualisiert oder unverändert
geprüft wurde und ein passender Inhaltsbestand vorliegt. Abbruch ist kein Erfolg.

Geprüft mit den Import-, Umfangs-, Anhangzuordnungs-, Stationsberechtigungs- und
Abschlusstests. Ein vollständiger Live-Nachweis erfordert die geladene Version
1.4.58, anschließend den tatsächlichen Einzelimport und den Vergleich mit
ClaimsForce. Ein bereitgestelltes Paket allein bestätigt keinen Import.

Lokaler Brückenordner laut Nutzer:
`C:\Users\chris\OneDrive - SV Büro Marc Schütt e.K\Brücke`.

## Zweiter Live-Test und Worker-Prüfung

Nach dem Chrome-Fensterneustart meldete das Manifest 1.4.58, Job 82997
(`single`, `26-085905-9`) verarbeitete aber erneut die Planung mit 26 Fällen.
Der Lauf wurde pausiert. Die Manifestversion allein belegt nicht den laufenden
Worker-Code und war daher kein ausreichender Bereitschaftsnachweis.

1.4.59 verlangt zusätzlich die im ausführbaren Worker festgeschriebene
Codeversion und Protokoll 2. Das neue Startsignal hat einen eigenen Namen;
alte Brücken können es nicht als Sammelimport interpretieren. Der Server
verlangt die bestätigte Worker-Version ebenfalls für die Stationsberechtigung.
Regressionstest: neues Manifest mit altem Worker bleibt erkennbar, altes
Startsignal startet keinen neuen Import, Schadennummer und Modus bleiben beim
V2-Start vollständig erhalten. Eine Bestätigung als vollständiger Live-Import
ist weiterhin offen und erfordert den echten Einzelimport mit Bestandsabgleich.

## Identische Fortschrittsmeldungen

Nach echter Erweiterungs-Neuladung bestätigte Chrome den Worker 1.4.59.
Job 82999 startete korrekt nur 26-085905-9 (Auftrag 1/1, Datei 3/151).
Zwei identische Heartbeats innerhalb derselben Sekunde erhielten jedoch 200
und anschließend 409 „Importauftrag läuft nicht mehr“, obwohl der Auftrag
noch lief. MySQL `rowCount()` zählt geänderte, nicht alle passenden Zeilen.
Die falsche Ablehnung löste STOP_IMPORT aus. Bei null geänderten Zeilen
prüft der Server daher erneut den aktiven Stationsbesitzer. Nur tatsächlich
beendete oder fremde Aufträge bleiben abgelehnt. Regressionstest deckt den
gültigen unveränderten Heartbeat und beide echten Ablehnungsfälle ab.
