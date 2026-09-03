SV-Netzwerk ClaimsForce-Brücke

1. ZIP-Datei entpacken.
2. In Chrome chrome://extensions öffnen und den Entwicklermodus einschalten.
3. "Entpackte Erweiterung laden" wählen und diesen Ordner auswählen.
4. "Details" / "Erweiterungsoptionen" öffnen und die persönlichen ClaimsForce-Zugangsdaten einmalig speichern. Ist der lokale Zugangsdaten-Helfer mit dem freigegebenen Zugangsdatenordner verbunden, übernimmt die Brücke die benötigten Zugänge automatisch und speichert sie verschlüsselt im Chrome-Profil.
5. Im SV-Netzwerk unter Versicherungsfälle "Aufträge aus Claims einlesen" wählen.

Bei einer automatischen Aktualisierung lädt die Brücke eine bereits geöffnete Versicherungsfall-Seite einmal kontrolliert neu. Wurde währenddessen gerade importiert, den Import anschließend im Portal erneut starten.

Bei Susanne bestimmt die Auswahl "Bearbeitung für", in welchen persönlichen Fallordner importiert wird.
Manuell geänderte Falldaten werden nicht überschrieben. Neue ClaimsForce-Werte ergänzen nur bisher leere Felder.
Bereits vollständig importierte und unveränderte Fälle werden übersprungen. Bei Änderungen werden nur neue oder geänderte Unterlagen, Nachrichten und Termine ergänzt.
Vor einer namentlichen Anmeldung schließt die Brücke alle vorhandenen ClaimsForce-Seiten und entfernt ausschließlich die Sitzungsdaten von ClaimsForce und ClaimsForce-Auth0. Dadurch können auch flüchtige alte Anwendungs- oder SSO-Sitzungen nicht mehr versehentlich unter dem neu ausgewählten Profil weiterlaufen. Ist die angemeldete E-Mail im Sitzungstoken erkennbar, wird sie vor dem ersten Fall nochmals mit dem ausgewählten Profil verglichen.

Werktags um 03:00 Uhr öffnet oder lädt die Brücke die zentrale Importstation und reiht dort nacheinander die Aufträge aller vier hinterlegten SV-Profile ein. Chrome und der Rechner müssen dafür laufen. Wird Chrome erst später gestartet, wird der Import bis 10:00 Uhr nachgeholt. Ein gespeicherter ClaimsForce-Zugang wird nur noch verwendet, wenn seine E-Mail-Adresse eindeutig zum ausgewählten Bearbeiterprofil gehört.
