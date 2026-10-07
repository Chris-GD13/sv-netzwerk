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

Die zusätzliche ChatGPT-Aufgabe muss pausiert oder auf reine Prüfung/Redaktionsvorbereitung ohne externe Veröffentlichung umgestellt werden. Alternativ muss sie denselben atomaren Registermechanismus vor jedem Schreiben ausführen können. Ohne diese gemeinsame Sperre ist ein globaler Doppelveröffentlichungsschutz nicht nachgewiesen. Am 07.10.2026 war die Aufgabenverwaltung ohne ChatGPT-Anmeldung nicht zugänglich; eine Deaktivierung ist daher noch nicht belegt. Bis zur Klärung bleibt diese Einschränkung ausdrücklich offen.

Alte Einmal-Nachholautomation ist PAUSED. Alte Beschreibungen von zwei täglichen Slots, SVG-Platzhaltern, Zapier und nicht mehr vorhandenen GitHub-Workflows sind keine aktive Ausführungsarchitektur.
