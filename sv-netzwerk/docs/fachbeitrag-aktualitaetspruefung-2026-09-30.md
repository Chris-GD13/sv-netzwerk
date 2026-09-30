# Rückwirkende Aktualitätsprüfung der Fachbeiträge

Prüfdatum: 30.09.2026  
Masterquelle: GitHub `Chris-GD13/sv-netzwerk`, Branch `main`  
Prüfumfang: 88 vor dem Tagesbeitrag veröffentlichte Dateien in `src/content/knowledge/`

## Vorgehen

Alle bestehenden Beiträge wurden auf Frontmatter, Veröffentlichungs- und Aktualisierungsdatum, Canonical, `noindex`, Quellenabschnitt, interne Verlinkung und erkennbare fachliche Berührungspunkte mit den geprüften Quellen kontrolliert. Ergänzt wurde nur dort, wo eine belastbare Primär- oder Behördenquelle eine konkrete Verbesserung der Einordnung trägt. Entscheidungs- beziehungsweise Veröffentlichungsdatum der Quelle und ihr Geltungsstatus werden im jeweiligen Beitrag vom redaktionellen Veröffentlichungsdatum getrennt.

## Belastbar ergänzte Beiträge

| Beitrag | Ergänzung | Quelle und Datum | Geltungsstatus | Praktische Bedeutung |
|---|---|---|---|---|
| `baufeuchte-schadenfeuchte-feuchtequellen-abgrenzung` | Aktualitätsabschnitt zur Reihenfolge aus Ursachenklärung, Befund, Sanierungsumfang und Erfolgskontrolle | Umweltbundesamt, Schimmelleitfaden aktualisierte Auflage April 2024; UBA-Informationsseite zuletzt aktualisiert 17.01.2025 | fachliche Leitlinie, keine Deckungszusage | Messwerte und Trocknungsfreigaben bleiben ohne Ursachen- und Bauteilbezug unvollständig | 
| `geruchsschaden-ursache-sanierung-erfolgskontrolle` | Einordnung mikrobiell bedingter Gerüche und Trennung von Quelle, Material, Maßnahme und Kontrolle | Umweltbundesamt, April 2024 / 17.01.2025 | fachliche Leitlinie | Geruchsbehandlung wird nicht als pauschale Sanierungsfreigabe verwendet |
| `schwarzwasserschaden-kontamination-hygiene-rueckbau-entsorgung` | Präzisierung von Kontamination, Folgefeuchte, Rückbau und Erfolgskontrolle | Umweltbundesamt, April 2024 / 17.01.2025 | fachliche Leitlinie; objektspezifische Hygiene- und Arbeitsschutzprüfung bleibt erforderlich | direkte Kontamination und reine Folgefeuchte werden getrennt bewertet |
| `technische-trocknung-leitungswasserschaeden-messkonzept-rueckbau-erfolgskontrolle` | Aktualitätsabschnitt und fehlendes `noindex: false` ergänzt | Umweltbundesamt, April 2024 / 17.01.2025 | fachliche Leitlinie; kein Preis- oder Deckungsregelwerk | Messkonzept, Rückbaugrund und Erfolgskontrolle bleiben getrennte Prüffelder |
| `schimmel-nach-wasserschaden-ursache-sanierungsumfang-kostenabgrenzung` | neuer Tagesbeitrag mit Quellen-, Geltungs- und Aktualitätsangaben | Umweltbundesamt, April 2024 / 17.01.2025; §§ 82, 83 VVG, amtlicher Abruf 30.09.2026 | UBA fachliche Leitlinie; VVG geltendes Gesetz, Einzelfall- und Vertragsprüfung erforderlich | Ursache, Sanierungsziel und Kostenfolge werden nicht vermischt |

## Bewusst nicht ergänzt

Bei den übrigen 84 geprüften Beiträgen wurde keine aktuelle Rechtsprechung, Normänderung, verbindliche Regeländerung oder belastbare Markt-/Kostenquelle gefunden, die ohne Spekulation eine konkrete Ergänzung rechtfertigt. Es wurden deshalb keine pauschalen Aktualisierungen, erfundenen Entscheidungsdaten oder nicht belegten Preisangaben eingetragen. Norm-Entwürfe wurden nicht als geltende Regeln verwendet.

## Technische Folgeschritte

Die vier rückwirkend ergänzten Beiträge und der Tagesbeitrag werden gemeinsam in Übersicht, Suchindex und Sitemap gebaut. Das Veröffentlichungsprotokoll weist je Beitrag Quellen, Bildstatus, LinkedIn-Status, Commit, Deployment und Live-Prüfung getrennt aus. Nach dem Deployment werden mehrere alte Beiträge und der Tagesbeitrag stichprobenartig auf HTTP 200, Canonical, `noindex=false` und sichtbare Aktualisierungen geprüft.
