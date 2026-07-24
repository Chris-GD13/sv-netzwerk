# Testfaelle

- Login mit gueltigem Konto funktioniert.
- Nicht angemeldete Nutzer werden auf `/intern/login/` umgeleitet.
- Dashboard aktualisiert sich nach Datensatz-Aenderungen.
- Drei unterschiedliche Benutzer koennen parallel verschiedene Fenster bearbeiten.
- Datensatzsperre blockiert parallele Bearbeitung desselben Fensters.
- Autosave speichert lokal und synchronisiert nach Wiederverbindung.
- Foto-Upload speichert Dateien im privaten Bucket.
- Gewichtsberechnung aktualisiert Glas-, Rahmen- und Pruefgewicht.
- Pflichtfelder blockieren den Abschluss unvollstaendiger Datensaetze.
- Audit-Log schreibt Feld- und Statusaenderungen.
- CSV-Export und Druckansicht funktionieren.
