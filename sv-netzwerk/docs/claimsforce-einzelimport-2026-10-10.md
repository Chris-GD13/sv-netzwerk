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
