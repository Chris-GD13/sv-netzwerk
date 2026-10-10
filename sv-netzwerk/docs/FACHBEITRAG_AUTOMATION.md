# Tägliche Veröffentlichung – verbindlicher Ablauf

Stand: 07.10.2026. Direkter Nutzerauftrag: täglich genau ein Fachartikel um 06:00 Europe/Berlin; dessen verifizierter Live-Stand täglich ab 07:00 auf dem persönlichen LinkedIn-Profil von Christian Wächter veröffentlichen. Dies ersetzt frühere Arbeitstags-, Zwei-Slot- und Zapier-Regeln. Fachliche Qualitätsregeln des FACHBEITRAG_STANDARD bleiben verbindlich. Die ausdrückliche LinkedIn-Freigabe ersetzt für diesen Auftrag die ältere Beschränkung auf vorbereitete Texte.

## Ausführung

Die beiden bestehenden lokalen Codex-Aufträge sind ACTIVE und projektlos. Der Computer und Codex müssen laufen. Kanonisches Repository: Chris-GD13/sv-netzwerk, origin/main; lokaler Git-Verwalter C:\Users\chris\OneDrive\Dokumente\SV-Netzwerk. Ausschließlich einen sauberen Worktree verwenden. SV-Portal ist kein Veröffentlichungsprojekt.

06:00: Tagesdatum in Europe/Berlin, aktuelle Drive-Standards, origin/main, CSV-Protokoll, Tagesregister und tatsächliche Website abgleichen. Bei vorhandenem Tagesartikel dessen Veröffentlichung prüfen/reparieren; niemals einen zweiten erstellen. Fachliche Quellenprüfung, Qualitätsprüfungen, Build und erfolgreicher IONOS-Livegang sind erforderlich. HTTP 200 der Übersicht oder ein grüner Build allein genügt nicht: kanonische Detailseite, Datum, Titel, Inhalt und deploy-version.txt mit Deployment-Commit prüfen.

07:00: Nur den erfolgreich live geprüften Tagesartikel übernehmen. Bei fehlendem Livegang bis 08:00 kontrolliert warten; danach Fehler melden, nichts als veröffentlicht behaupten. Ein verspäteter Lauf darf den heutigen fehlenden LinkedIn-Beitrag nachholen. Vor Absenden persönliche Profilaktivitäten, Artikel-URL und Tagesregister prüfen. Fachlicher Text mit 3–5 Kernpunkten, Link und geprüftem ImageGen-Symbolbild, keine erfundenen Fälle. Bei unklarem Absendeergebnis zuerst Live-Stand prüfen, nicht erneut senden. Erfolg erst nach sichtbarem Beitrag, Bild, Text, Datum und dauerhaftem Permalink protokollieren.

## Gemeinsame Reservierung vor jeder Veröffentlichung

Pflichtdatei: docs/fachbeitrag-tagesregister.json im Projekt; CSV bleibt das fachliche Veröffentlichungsprotokoll. Alle schreibenden Läufe müssen denselben Register-Mechanismus verwenden. Vom sauberen Repository-Root aus mit Node und Git aus dem gebündelten Codex-Runtime:

`node sv-netzwerk/scripts/publication-day-guard.mjs inspect '{}'`

`claim-article` benötigt JSON mit owner (eindeutige Lauf-ID), title, slug und kanonischer url. Nur outcome updated erlaubt die neue Erstellung. outcome existing bedeutet vorhandenen Tagesartikel verwenden. Nach geprüftem Deployment folgt `verify-website` mit echtem commit und run_url. Vor LinkedIn folgt `claim-linkedin` mit owner; nur outcome updated erlaubt das Absenden. Nach sichtbarem Erfolg folgt `verify-linkedin` mit derselben owner und permalink. Reservierungen verfallen nicht automatisch: bei Abbruch zuerst Live-Stand klären und dokumentiert wiederaufnehmen, keine blinde Freigabe.

Der Helfer liest frisch origin/main, erstellt nur die Registeränderung über einen isolierten Git-Index und pusht ohne force. Bei konkurrierendem Push liest er neu; dadurch gewinnt nur eine Tagesreservierung. Anschließend origin/main holen und den sauberen Worktree per Fast-forward aktualisieren, bevor weitere Dateien committed werden. Ausgaben und Commit-ID sichern. Eine Reservierung allein ist kein Veröffentlichungsbeweis.

## ChatGPT-Überschneidung

Die zusätzliche ChatGPT-Aufgabe muss pausiert oder auf reine Prüfung/Redaktionsvorbereitung ohne externe Veröffentlichung umgestellt werden. Alternativ muss sie denselben atomaren Registermechanismus vor jedem Schreiben ausführen können. Ohne diese gemeinsame Sperre ist ein globaler Doppelveröffentlichungsschutz nicht nachgewiesen. Am 07.10.2026 wurde die zusätzliche Aufgabe „Täglicher SV-Netzwerk-Fachartikel“ in der angemeldeten Chrome-Sitzung geöffnet und pausiert. Die Aufgabenliste zeigt „Pausiert · Täglich um 6:00“, das Menü „Fortsetzen“. Damit ist kein weiterer automatischer ChatGPT-Veröffentlichungslauf geplant. Vor einer Wiederaktivierung muss die gemeinsame Reservierung angebunden oder der Auftrag auf reine Vorbereitung begrenzt werden.

Alte Einmal-Nachholautomation ist PAUSED. Alte Beschreibungen von zwei täglichen Slots, SVG-Platzhaltern, Zapier und nicht mehr vorhandenen GitHub-Workflows sind keine aktive Ausführungsarchitektur.

## Verbindliche Themenvorgabe und Nachholung am 10.10.2026

Die direkte Nutzeranweisung vom 09.10.2026 verlangt für das Wochenende Baugewerke statt weiterer Wasser-/Überflutungsschwerpunkte: am Samstag, 10.10.2026, Fensterbau (Fenstermontage, Anschlussfugen, Ausführungsqualität, Mängelbefund und Nachbesserung); am Sonntag, 11.10.2026, Schreinerhandwerk (Innentüren, Türblätter, Zargen, Beschläge, Passung, Oberflächen und fachgerechte Nachbesserung). Diese Themenvorgabe vor jeder Themenreservierung prüfen. Sie gilt für alle auf das gemeinsame Repository zugreifenden Veröffentlichungswege. Ein vorhandenes, abweichendes Thema nicht ungeprüft weiterverwenden.

Aktueller Befund vom 10.10.2026: Trotz der am 07.10. dokumentierten Pause schrieb ein weiterer Veröffentlichungsweg eine abweichende Reservierung und veröffentlichte über PR #369. Dessen Scheduler-Herkunft ist noch nicht abschließend belegt; die historische Pause beweist daher keinen aktuell vollständigen Ausschluss konkurrierender Publisher. Die am 09.10. gespeicherten Themenvorgaben der beiden lokalen Codex-Automationen gelten unverändert. Die gemeinsame Vorgabe steht mit dieser Ergänzung auch im Repository.

Am 10.10.2026 war bereits ein abweichender Brandmeldeanlagen-Artikel live. LinkedIn wurde deshalb mit `blocked-topic-mismatch` nicht veröffentlicht. Der anschließende direkte Nutzerauftrag „dann jetzt veröffentlichen“ autorisiert die dokumentierte Fensterbau-Nachholung. Ausschließlich für diesen Ausnahmefall wird ein zweiter Websiteartikel erstellt; der ältere Artikel und sein Register-Nachweis bleiben unter `recovery.prior_publications` erhalten. Dies ist keine allgemeine Freigabe für zwei Tagesartikel. Für LinkedIn gilt weiterhin genau ein eigener Tagesartikel-Beitrag und die unmittelbare Live-Duplikatprüfung. Die neue aktive Registerreservierung ist `2026-10-10-fenstermontage-anschlussfugen-maengel-nachbesserung`.

## Rücknahme und Neufassung 10.10.2026

Direkter Nutzerauftrag: den abgelehnten Fensterbau-Beitrag überall löschen und auf Basis recherchierter Primärquellen einen neuen Beitrag zur Abdichtung beim Fenstereinbau erstellen. Die bisherige aktive Fensterbau-Reservierung wird deshalb zurückgenommen und anschließend mit erhaltenem Rücknahmenachweis durch die Neufassung ersetzt. Keine erfundenen Fälle oder Messwerte; keine pauschale Behauptung gegen fachgerechtes Nachstellen. Für den neuen Artikel gelten die vollständigen Qualitäts- und Live-Prüfungen sowie genau ein tatsächlich veröffentlichter eigener LinkedIn-Beitrag.
