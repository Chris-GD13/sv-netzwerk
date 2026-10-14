# Regionale Suchseiten und Schadenart-Einstiege

Auftrag vom 14.10.2026: Versicherungsnehmer und Versicherer sollen nach Schadenart und tatsächlichem Expertenstandort passende Fachkompetenz finden. Kfz- und Rechtsschutzthemen sind nicht Gegenstand dieses Ausbaus.

## Quellen der Ortszuordnung

| Experte | Ort | Beleg |
| --- | --- | --- |
| Christian Wächter | Aalen | Vorhandenes Expertenprofil und https://www.sv-schuett.de/niederlassungen/ |
| Marc Schütt | Werdohl | Büroanschrift https://sv-schuett.de/impressum |
| Carmen Gohl | Heilbronn | Büroanschrift https://www.sv-gohl.de/impressum/ |
| Holger Roth | Schwäbisch Gmünd | Vorhandene explizite Zuordnung in experts.ts; Christian bestätigt den Standort am 14.10.2026 |
| Claudius Freiberg | Forchtenberg-Sindringen | Büroanschrift https://claudius-freiberg.de/impressum/ |
| Lenna Maria Walczok | Region Marburg | Eigene öffentliche Profilangabe https://de.linkedin.com/in/lenna-maria-walczok-6bb468291 ; keine bestätigte Büroanschrift |

Die öffentlichen Quellen wurden am 14.10.2026 über Websuche eingelesen. Keine benachbarten Orte als zusätzliche Niederlassungen erfunden, keine Straße oder Geo-Koordinate aus einem Ortsnamen abgeleitet. Bei Lenna wird ausdrücklich nur die Region geführt; keine workLocation-Adresse in Person-Strukturdaten. Holger bleibt Regulierer. Backoffice-Mitarbeiter werden nicht als lokale Gutachter geführt.

## Umsetzung

- Sechs Regionaldetailseiten und eine Übersicht unter /regionen/, jeweils mit konkreter Fachkompetenz, zuständigem Experten, passenden Schadenarten und Fachartikeln.
- Tatsächliche Standorte bzw. Region in Expertenprofilen, Suchmetadaten, Expertenkarten und Ortsfilter. Person-Strukturdaten nur mit belegter Ortszuordnung, keine erfundenen LocalBusiness-Niederlassungen.
- Elf bestehende Schadenartseiten mit individuellen Suchtexten, Prüfaufgaben für Versicherungsnehmer und Versicherer, fachlicher Frage, Service-Strukturdaten und echten Artikelverweisen.
- Einstieg nach Schadenart und Standort auf der Startseite. Regional- und Schadenartseiten im lokalen Suchindex und in der automatisch erzeugten Sitemap.
- Keine Änderungen an Google-Unternehmensprofilen oder Maps-Einträgen. Organische lokale Sichtbarkeit und Maps-Platzierung sind getrennte Aufgaben; kein Ranking versprochen.

## Validierung

npm run build erfolgreich. 107 Fachartikel-URLs, 185 öffentliche Sitemap-Seiten und 20.140 interne Links geprüft. Zusätzlich die sechs regionalen Expertenzuordnungen, Rollen, Canonicals/Indexierbarkeit durch bestehende SEO-Prüfung, elf Schadenarttexte und alle expliziten Fachartikelverweise im erzeugten HTML kontrolliert.

Eine grafische Browserprüfung wurde lokal versucht; die Browser-Binärdatei war nicht verfügbar. Kein visueller Test wird dadurch als bestanden behauptet.
