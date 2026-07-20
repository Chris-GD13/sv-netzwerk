import { mkdir, readFile, readdir, writeFile, appendFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import crypto from 'node:crypto';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const knowledgeDir = path.join(root, 'src', 'content', 'knowledge');
const linkedinDir = path.join(root, 'src', 'content', 'linkedin');
const videosDir = path.join(root, 'src', 'content', 'videos');
const imageDir = path.join(root, 'public', 'assets', 'images', 'linkedin');
const automationDir = path.join(root, '.automation');
const runtimeFile = path.join(automationDir, 'latest-publication.json');
const publicationLogFile = path.join(root, 'docs', 'fachbeitrag-veroeffentlichungsprotokoll.csv');
const libraryFile = path.join(root, 'src', 'data', 'library.ts');
const changelogFile = path.join(root, 'CHANGELOG.md');

const BERLIN = 'Europe/Berlin';
const SLOT_WINDOWS = {
  morning: { from: '05:15', to: '06:40', label: 'morgens' },
  afternoon: { from: '16:15', to: '17:30', label: 'nachmittags' },
};

const REGION_HINTS = [
  'Aalen', 'Ostalbkreis', 'Schwäbisch Gmünd', 'Heidenheim', 'Ulm', 'Göppingen', 'Stuttgart',
  'Ludwigsburg', 'Esslingen', 'Ansbach', 'Nördlingen', 'Ellwangen', 'Backnang', 'Rems-Murr',
];
const EVENT_HINTS = [
  'starkregen', 'hochwasser', 'überflutung', 'ueberflutung', 'rückstau', 'rueckstau',
  'hagel', 'sturm', 'tornado', 'schneedruck', 'unwetter', 'gebäudebrand', 'gebaeudebrand',
  'brand', 'feuerwehreinsatz', 'evakuierung', 'katastrophe',
];

const TOPICS = [
  {
    id: 'starkregen-rueckstau',
    category: 'Starkregen und Rückstau',
    tags: ['Starkregen', 'Rückstau', 'Schadenregulierung', 'Beweissicherung'],
    damageTypes: ['starkregen', 'rueckstau', 'gebaeude'],
    slugBase: 'starkregen-rueckstau-schadenaufnahme-regulierung',
    titleBase: 'Starkregen und Rückstau: Schadenaufnahme und Regulierung im Kumulereignis',
    intro: 'Bei Starkregenlagen mit zahlreichen Meldungen in kurzer Zeit entscheidet eine strukturierte Erstaufnahme über die spätere Prüffähigkeit.',
    tech: 'Wesentlich ist die Trennung zwischen Niederschlagseinwirkung, Rückstaueinfluss, baulichem Zustand und Vorzustand. Technisch sind Eintrittswege, Wasserstände, Durchfeuchtungszonen und betroffene Gewerke getrennt zu erfassen.',
    practice: ['fehlende Höhenbezüge und Zeitachsen', 'Vermischung aus Sofortmaßnahme und Wiederherstellung', 'ungeklärte Rückstausicherung als Vorzustandsthema'],
  },
  {
    id: 'hochwasser-grossschadenkoordination',
    category: 'Hochwasser und Überflutung',
    tags: ['Hochwasser', 'Überflutung', 'Großschaden', 'Einsatzplanung'],
    damageTypes: ['hochwasser', 'ueberflutung', 'gebaeude'],
    slugBase: 'hochwasser-ueberflutung-grossschadenkoordination',
    titleBase: 'Hochwasser und Überflutung: Koordination im Großschadenbestand',
    intro: 'Bei regionalen Hochwasserlagen laufen Besichtigungen, Sofortmaßnahmen und Reservierung parallel und erfordern belastbare Einsatzsteuerung.',
    tech: 'Für die technische Einordnung sind Wasserstandsentwicklung, Einwirkdauer, betroffene Konstruktionen und Trocknungspfad maßgeblich. Regulatorisch bleibt die saubere Trennung zwischen versichertem Schadenanteil und nicht versicherten Vorzuständen zentral.',
    practice: ['zu späte Priorisierung kritischer Objekte', 'fehlende Synchronisierung zwischen Sachverständigen und Sanierern', 'Reserveschätzungen ohne belastbaren Mengengerüstbezug'],
  },
  {
    id: 'sturm-hagel-serienschaeden',
    category: 'Sturm- und Hagelschäden',
    tags: ['Sturm', 'Hagel', 'Kumulschaden', 'Plausibilitätsprüfung'],
    damageTypes: ['sturm', 'hagel', 'gebaeude'],
    slugBase: 'sturm-hagel-serienschaeden-prueffolge',
    titleBase: 'Sturm- und Hagel-Serienschäden: Prüffolge für belastbare Freigaben',
    intro: 'Bei großflächigen Sturm- und Hagellagen führt nur eine bauteilbezogene Prüffolge zu nachvollziehbaren Entscheidungen.',
    tech: 'Windwirkung, Befestigungszustand, Materialalterung und Schadenzeitpunkt sind gemeinsam zu prüfen. Nur dokumentierte Feststellungen dürfen in Freigaben und Reserven überführt werden.',
    practice: ['Pauschalanerkennung ohne Bauteilprüfung', 'Vorschäden werden nicht getrennt geführt', 'Notabdichtung und Endinstandsetzung werden in einer Position vermischt'],
  },
  {
    id: 'grossflaechig-leitungswasser',
    category: 'Leitungswasser und Kumulmanagement',
    tags: ['Leitungswasser', 'Schadenmanagement', 'Sanierungsplanung', 'Kostenprüfung'],
    damageTypes: ['leitungswasser', 'gebaeude'],
    slugBase: 'grossflaechige-leitungswasserschaeden-sanierungssteuerung',
    titleBase: 'Großflächige Leitungswasserschäden: Sanierungssteuerung unter hoher Schadenfrequenz',
    intro: 'Bei regional gehäuften Leitungswasserschäden müssen Erstmaßnahmen, Trocknung und Rückbau eng gesteuert werden, um Folgekosten zu begrenzen.',
    tech: 'Technisch sind Leckageort, Durchfeuchtungsgrad, Materialverträglichkeit und Trocknungsfähigkeit je Bauteil zu bewerten. Kaufmännisch braucht es früh prüffähige Mengen- und Leistungstrennung.',
    practice: ['vorschneller Komplettausbau ohne Messkonzept', 'fehlende Abgrenzung zwischen Schadenminderung und Modernisierung', 'KVA ohne Bezug zur dokumentierten Schadenzone'],
  },
  {
    id: 'brandschaden-mehrere-gebaeude',
    category: 'Brandschaden',
    tags: ['Brandschaden', 'Mehrere Gebäude', 'Schadenaufnahme', 'Regulierung'],
    damageTypes: ['brand', 'gebaeude'],
    slugBase: 'brandschaden-mehrere-gebaeude-koordination',
    titleBase: 'Brandschaden mit mehreren betroffenen Gebäuden: Struktur für Erstaufnahme und Regulierung',
    intro: 'Bei Brandereignissen mit mehreren betroffenen Einheiten steigt das Risiko für Dokumentationslücken und unklare Leistungsgrenzen.',
    tech: 'Erforderlich sind getrennte Objektakten, nachvollziehbare Schadenzonen, klare Trennung von Gefahrenabwehr und Wiederherstellung sowie abgestimmte Reservierung je Objekt.',
    practice: ['objektübergreifende Sammelpositionen', 'fehlende Trennung von Ruß-/Rauch- und Löschwasserschäden', 'unzureichende Zuordnung von Sofortmaßnahmen zu konkreten Einheiten'],
  },
  {
    id: 'schneedruck-winterschaeden',
    category: 'Schneedruck und Winterschäden',
    tags: ['Schneedruck', 'Winterschaden', 'Dach', 'Statik', 'Kumulschaden'],
    damageTypes: ['schneedruck', 'gebaeude'],
    slugBase: 'schneedruck-winterschaeden-bewertung-regulierung',
    titleBase: 'Schneedruck und Winterschäden: Statische Bewertung und Regulierung im Kumulereignis',
    intro: 'Bei regional auftretendem Schneedruck mit Dach- und Tragewerkschäden müssen statische Risiken, Beweissicherung und Regulierung parallel gesteuert werden.',
    tech: 'Relevante Parameter sind Schneelastzonen, Dachneigungs- und Dachkonstruktionstypen, Materialalterung und vorhandene Baumängel. Statische Überprüfungen durch Fachplaner sind zu koordinieren und Ergebnisse in die Akte zu integrieren.',
    practice: ['fehlende statische Einschätzung vor Rückbau', 'Schneelast als alleinige Ursache ohne Prüfung auf Vorzustand', 'zu früher vollständiger Rückbau ohne Beweissicherung'],
  },
  {
    id: 'tornado-lokale-sturmereignisse',
    category: 'Tornadoereignisse und lokale Sturmschäden',
    tags: ['Tornado', 'Sturm', 'Schadenzone', 'Plausibilitätsprüfung', 'Kumulschaden'],
    damageTypes: ['sturm', 'gebaeude'],
    slugBase: 'tornado-lokale-sturmereignisse-schadenaufnahme',
    titleBase: 'Tornadoereignisse und lokale Sturmereignisse: Schadenaufnahme und technische Einordnung',
    intro: 'Lokale Sturmereignisse mit Tornadobewegungen erzeugen konzentrierte Schadenzonen, die technisch und regulatorisch eine besonders saubere Abgrenzung erfordern.',
    tech: 'Windgeschwindigkeit, Druckverteilung und Schadenbilder variieren stark über kurze Distanzen. Spur, Breite und Schweregrad des Ereignisses sind mit Wetterdaten, Fotos und Fremdauskünften zu belegen, bevor Ursache und Umfang regulatorisch festgestellt werden.',
    practice: ['fehlender Abgleich mit Wetterstationsdaten', 'pauschale Übernahme von Schadenmeldungen ohne Plausibilitätsprüfung', 'keine Abgrenzung zwischen Winddruckschäden und vorhandenen Baumängeln'],
  },
  {
    id: 'erstbesichtigung-sofortmassnahmen',
    category: 'Erstbesichtigung und Sofortmaßnahmen',
    tags: ['Erstbesichtigung', 'Sofortmaßnahmen', 'Schadenaufnahme', 'Sicherung', 'Dokumentation'],
    damageTypes: ['gebaeude', 'leitungswasser', 'brand', 'sturm'],
    slugBase: 'erstbesichtigung-sofortmassnahmen-struktur-vorgehen',
    titleBase: 'Erstbesichtigung und Sofortmaßnahmen: Strukturiertes Vorgehen im Schadenerstereignis',
    intro: 'Die Erstbesichtigung legt die Grundlage für alle nachfolgenden Regulierungsschritte – Qualität und Vollständigkeit der Erstaufnahme bestimmen den späteren Prüfhorizont.',
    tech: 'Zur Erstbesichtigung gehören Sicherheitsbeurteilung, Fotodokumentation nach einheitlicher Logik, Schadenabgrenzung je Bauteil und Gewerk, Erfassung von Eintrittswegen und Vorzustandshinweisen sowie erste Einschätzung zu notwendigen Sofortmaßnahmen.',
    practice: ['fehlende Zeitstempel und Georeferenz in Fotos', 'keine Trennung von Sofortmaßnahme und vorläufiger Wiederherstellung', 'Sofortmaßnahmen ohne schriftliche Beauftragung oder Protokoll'],
  },
  {
    id: 'plausibilitaetspruefung-schadenbild',
    category: 'Plausibilitätsprüfung und Schadenbild',
    tags: ['Plausibilitätsprüfung', 'Schadenbild', 'Kausalität', 'Vorzustand', 'Dokumentation'],
    damageTypes: ['gebaeude', 'leitungswasser', 'sturm', 'brand'],
    slugBase: 'plausibilitaetspruefung-schadenbild-kausalitaet',
    titleBase: 'Plausibilitätsprüfung im Schadenfall: Schadenbild, Kausalität und Vorzustand belastbar bewerten',
    intro: 'Eine belastbare Plausibilitätsprüfung unterscheidet dokumentierte Feststellung von Vermutung – und schützt sowohl Versicherer als auch Geschädigte vor unbegründeten Entscheidungen.',
    tech: 'Schadenzeitpunkt, Ereignisart, physikalische Einwirkung, Bauteilaufbau und Spurenlage sind gemeinsam zu betrachten. Wo Messwerte, Bauteilöffnungen oder ergänzende Unterlagen fehlen, bleiben Ursachenaussagen ausdrücklich vorläufig.',
    practice: ['voreilige Kausalitätsfeststellung ohne vollständige Spurenauswertung', 'fehlender Abgleich mit bekannten Vorschäden oder Mängelberichten', 'keine Unterscheidung zwischen gesicherter und vermuteter Schadenursache'],
  },
  {
    id: 'beweissicherung-fotodokumentation',
    category: 'Beweissicherung und Fotodokumentation',
    tags: ['Beweissicherung', 'Fotodokumentation', 'Schadenakte', 'Archivierung'],
    damageTypes: ['gebaeude', 'leitungswasser', 'brand', 'sturm'],
    slugBase: 'beweissicherung-fotodokumentation-standards',
    titleBase: 'Beweissicherung und Fotodokumentation: Standards für belastbare Schadenakten',
    intro: 'Eine lückenhafte Beweissicherung gefährdet nicht nur die Regulierung, sondern kann im Streitfall die gesamte Sachverhaltsrekonstruktion unmöglich machen.',
    tech: 'Beweissichernde Fotodokumentation umfasst Übersichts-, Detail- und Messaufnahmen mit Zeitstempel, Georeferenz und eindeutiger Zuordnung zu Bauteil, Gewerk und Schadensphase. Rückbauöffnungen sind vor Verschluss vollständig zu dokumentieren.',
    practice: ['Fotos ohne Zeitstempel oder Ortsreferenz', 'fehlende Maßstabsangaben bei Detailfotos', 'unzureichende Dokumentation vor und nach Sofortmaßnahmen'],
  },
  {
    id: 'sanierungsplanung-rueckbau-trocknung',
    category: 'Sanierungsplanung und Trocknung',
    tags: ['Sanierungsplanung', 'Rückbau', 'Trocknung', 'Gebäudetrocknung', 'Schadenminderung'],
    damageTypes: ['leitungswasser', 'hochwasser', 'gebaeude'],
    slugBase: 'sanierungsplanung-rueckbau-trocknung-strategie',
    titleBase: 'Sanierungsplanung, Rückbau und Trocknung: Strategie für nachvollziehbare Wiederherstellung',
    intro: 'Eine vorschnelle oder zu weitgehende Sanierungsentscheidung erzeugt unnötige Kosten und verhindert belastbare Schadenabgrenzung – strukturierte Planung ist der entscheidende Hebel.',
    tech: 'Rückbau und Trocknung sind stufenweise zu planen: Erst Schadenbegrenzung und Messung, dann Trocknungskonzept auf Basis von Feuchtemessungen, anschließend Freigabe von Rückbau- und Wiederherstellungsschritten. Trocknungsstrategie und Messprotokolle sind Bestandteil der Akte.',
    practice: ['Gesamtrückbau ohne vorherige Messungen und Konzept', 'Trocknungsgeräte ohne qualifiziertes Monitoring und Protokoll', 'fehlende Abnahme und Schlusskontrolle nach Trocknung'],
  },
  {
    id: 'koordination-mehrere-sachverstaendige',
    category: 'Koordination im Sachverständigen-Netzwerk',
    tags: ['Koordination', 'Sachverständiger', 'Kumulschaden', 'Einsatzplanung', 'Qualitätssicherung'],
    damageTypes: ['gebaeude', 'kumulschaden'],
    slugBase: 'koordination-sachverstaendige-kumulereignis-einsatz',
    titleBase: 'Koordination mehrerer Sachverständiger im Kumulereignis: Rollen, Standards und Qualitätssicherung',
    intro: 'Wenn mehrere Sachverständige bei einem regionalen Kumulereignis parallel eingesetzt werden, entscheidet die Koordinationsstruktur über Konsistenz und Prüffähigkeit der Ergebnisse.',
    tech: 'Einheitliche Dokumentationsstandards, klare Eskalationskriterien und ein zentrales Änderungsprotokoll sind Voraussetzung für belastbare Ergebnisse. Regelmäßige Lageabstimmungen verhindern divergierende Einschätzungen und nicht abgestimmte Reservierungen.',
    practice: ['widersprüchliche Einschätzungen mangels Abstimmung', 'keine Einigung auf gemeinsame Fotologik und Mindestdaten', 'fehlende zentrale Übersicht über Bearbeitungsstand und Priorisierung'],
  },
  {
    id: 'rechnungs-kva-pruefung',
    category: 'Rechnungs- und KVA-Prüfung',
    tags: ['Rechnungsprüfung', 'KVA', 'Kostenvoranschlag', 'Freigabe', 'Schadenregulierung'],
    damageTypes: ['gebaeude', 'leitungswasser', 'brand'],
    slugBase: 'rechnungs-kva-pruefung-freigabe-schadenfall',
    titleBase: 'Rechnungs- und KVA-Prüfung im Schadenfall: Freigabe auf belastbarer Grundlage',
    intro: 'Kostenpositionen sind nur dann erstattungsfähig, wenn sie dem dokumentierten Schadenumfang entsprechen, erforderlich waren und nachvollziehbar kalkuliert wurden.',
    tech: 'Prüfkriterien umfassen Auftragsbezug, Leistungsnachweis, Materialmengenbezug, Schadenbezug je Position und marktübliche Preisansätze. KVAs sind auf die festgestellte Schadenzone und das notwendige Wiederherstellungsziel hin zu prüfen.',
    practice: ['Freigabe von Pauschalpositionen ohne Einzelnachweis', 'KVA enthält Leistungen außerhalb der dokumentierten Schadenfläche', 'fehlende Trennung von Schadenminderungs- und Wiederherstellungskosten'],
  },
  {
    id: 'reservierung-grosser-schadenbestand',
    category: 'Reservierung im Schadenbestand',
    tags: ['Reservierung', 'Schadenbestand', 'Regulierung', 'Mengengerüst', 'Prognose'],
    damageTypes: ['gebaeude', 'kumulschaden'],
    slugBase: 'reservierung-grosser-schadenbestand-methodik',
    titleBase: 'Reservierung bei größeren Schadenbeständen: Methodik für belastbare Prognosen',
    intro: 'Fehlerhafte Reserven belasten Versicherungsbilanzen und führen zu Nachregulierungen – methodisch belastbare Reservierungsansätze reduzieren dieses Risiko erheblich.',
    tech: 'Reservierungen im Großschadenbestand stützen sich auf belastbare Mengengerüste je Objektkategorie, Schadenschwere-Einschätzung, Sanierungsdauer und Erfahrungswerte aus vergleichbaren Ereignissen. Einzelreserven sind von Pauschalzuschlägen zu trennen.',
    practice: ['zu frühe Gesamtreserve ohne belastbare Einzelobjektdaten', 'Reserven ohne Bezug zu dokumentierten Schadenmengen', 'keine Aktualisierung der Reserve nach Fortschritt der Regulierung'],
  },
  {
    id: 'kommunikation-beteiligte-schadenfall',
    category: 'Kommunikation im Schadenfall',
    tags: ['Kommunikation', 'Versicherer', 'Versicherungsnehmer', 'Schadenmanagement', 'Transparenz'],
    damageTypes: ['gebaeude', 'kumulschaden'],
    slugBase: 'kommunikation-beteiligte-schadenfall-struktur',
    titleBase: 'Kommunikation mit Versicherern, Regulierern und Versicherungsnehmern: Struktur für klare Verfahren',
    intro: 'Strukturierte Kommunikation im Schadenfall verhindert Missverständnisse, spart Eskalationen und sichert nachvollziehbare Entscheidungsgrundlagen für alle Beteiligten.',
    tech: 'Jede kommunizierte Einschätzung zu Ursache, Umfang oder Kosten muss auf dokumentierten Feststellungen beruhen. Vorläufige Aussagen sind als solche zu kennzeichnen. Protokollierte Abstimmungen ersetzen mündliche Absprachen im Streitfall.',
    practice: ['mündliche Zusagen ohne schriftliche Grundlage', 'fehlende Abgrenzung zwischen vorläufiger Einschätzung und abschließender Regulierungsentscheidung', 'keine einheitliche Informationsbasis zwischen Versicherer, Regulierer und Sachverständigem'],
  },
  {
    id: 'schadenminderung-pflicht-praxis',
    category: 'Schadenminderung und Sofortmaßnahmenpflicht',
    tags: ['Schadenminderung', 'Sofortmaßnahmen', 'Obliegenheiten', 'Versicherungsnehmer'],
    damageTypes: ['gebaeude', 'leitungswasser', 'sturm'],
    slugBase: 'schadenminderung-obliegenheit-sofortmassnahmen-praxis',
    titleBase: 'Schadenminderungspflicht und Sofortmaßnahmen: Was Versicherungsnehmer und Regulierer wissen müssen',
    intro: 'Die Schadenminderungspflicht ist eine versicherungsrechtliche Obliegenheit – ihre Verletzung kann zur Leistungskürzung führen, ihre Erfüllung muss dokumentiert sein.',
    tech: 'Sofortmaßnahmen zur Schadenminderung sind von Wiederherstellungsmaßnahmen zu trennen. Erstattungsfähig sind nur Maßnahmen, die objektiv erforderlich, verhältnismäßig und nachvollziehbar durchgeführt wurden. Der Zeitpunkt der Beauftragung und Ausführung ist zu belegen.',
    practice: ['fehlende Dokumentation des Zeitpunkts der Schadenminderungsmaßnahme', 'Sammelrechnungen ohne Zuordnung zu Schadenminderung oder Wiederherstellung', 'keine Rückfrage beim Versicherer bei unklarer Maßnahmenerforderlichkeit'],
  },
  {
    id: 'massenanfall-schadenfrequenz',
    category: 'Massenanfall von Einzelschäden',
    tags: ['Massenanfall', 'Schadenfrequenz', 'Kumulschaden', 'Priorisierung', 'Einsatzsteuerung'],
    damageTypes: ['kumulschaden', 'gebaeude'],
    slugBase: 'massenanfall-einzelschaeden-einsatzsteuerung',
    titleBase: 'Massenanfall von Einzelschäden: Einsatzsteuerung bei außergewöhnlich hoher Schadenfrequenz',
    intro: 'Wenn in kurzer Zeit eine große Anzahl von Schadenmeldungen eingeht, entscheiden Einsatzplanung und Priorisierung über Qualität und Geschwindigkeit der Regulierung.',
    tech: 'Klassifizierung nach Dringlichkeit (Sicherheitsrisiko, Substanzgefährdung, Nutzungsausfall) und Komplexität (Standardschaden, Sonderfall, statisches/hygienisches Risiko) bildet die Grundlage. Ressourcensteuerung für Sachverständige, Regulierer und Dienstleister muss zentral koordiniert werden.',
    practice: ['keine Priorisierung – alle Schäden werden in Meldereihenfolge bearbeitet', 'fehlende Kapazitätsplanung für Sanierungsdienstleister', 'mangelhafte Übergabe zwischen Sachverständigen und Regulierern'],
  },
  {
    id: 'gutachter-plattform-regional',
    category: 'Gutachter-Plattform und regionale Netzwerke',
    tags: ['Gutachter-Plattform', 'Sachverständiger', 'Netzwerk', 'Kumulschaden', 'Regulierung'],
    damageTypes: ['gebaeude', 'kumulschaden'],
    slugBase: 'gutachter-plattform-regional-kumulschaden',
    titleBase: 'Gutachter-Plattform und regionale Sachverständigennetzwerke: Mehrwert bei Kumulereignissen',
    intro: 'Eine regional verankerte Gutachter-Plattform ermöglicht schnelle Verfügbarkeit qualifizierter Sachverständiger – entscheidend für belastbare Erst- und Nachbesichtigungen bei großen Schadenlagen.',
    tech: 'Qualitätssicherung über einheitliche Dokumentationsstandards, zertifizierte Sachverständige und strukturierte Abstimmungsprozesse ermöglicht konsistente Regulierungsqualität auch bei gleichzeitigem Einsatz vieler Beteiligter in einer Region.',
    practice: ['keine belastbaren Qualitätsnachweise für eingesetzte Sachverständige', 'fehlende Abstimmung zwischen Plattform und Auftraggeber bei Kapazitätsengpässen', 'uneinheitliche Dokumentationsqualität bei verschiedenen Gutachtern'],
  },
  {
    id: 'abgrenzung-versichert-nichtversichert',
    category: 'Abgrenzung versicherter Schadteile',
    tags: ['Schadenabgrenzung', 'Versicherungsdeckung', 'Vorzustand', 'Instandhaltung', 'Regulierung'],
    damageTypes: ['gebaeude', 'leitungswasser', 'sturm', 'brand'],
    slugBase: 'abgrenzung-versicherter-nichtversicherter-schadteil',
    titleBase: 'Abgrenzung versicherter und nicht versicherter Schadteile: Methodik für belastbare Freigaben',
    intro: 'Die Abgrenzung zwischen versichertem Schaden und nicht versichertem Vorzustand, Instandhaltungsrückstand oder Modernisierungsbedarf ist eine der anspruchsvollsten Aufgaben in der technischen Schadenregulierung.',
    tech: 'Maßgebliche Kriterien sind: zeitlicher Zusammenhang mit dem Schadenereignis, technische Kausalität, Nachweis des Vorzustands, Unterscheidung von Schadenminderung und Instandsetzung sowie schadenbedingter und nicht schadenbedingter Maßnahmenanteil.',
    practice: ['fehlende Vorzustandsdokumentation macht spätere Abgrenzung unmöglich', 'pauschale Anerkennung ohne Prüfung auf Instandhaltungsdefizite', 'keine Trennung zwischen schadenbedingtem und altersgemäß erforderlichem Sanierungsanteil'],
  },
  {
    id: 'katastrophenschaden-ueberflutung-region',
    category: 'Katastrophenschäden in der Region',
    tags: ['Katastrophenschaden', 'Unwetter', 'Überflutung', 'Großschaden', 'Koordination'],
    damageTypes: ['hochwasser', 'ueberflutung', 'gebaeude', 'kumulschaden'],
    slugBase: 'katastrophenschaeden-region-management-koordination',
    titleBase: 'Katastrophenschäden in der Region: Management, Koordination und fachliche Einordnung',
    intro: 'Bei Katastrophenschäden mit regionaler Ausdehnung sind Schadenmanagement, Behördenkoordination und fachliche Einordnung besonders komplex – strukturiertes Vorgehen ist entscheidend.',
    tech: 'Technische Einordnung umfasst Schadenart, Schwere, Betroffenheitsdichte und Priorität. Koordination mit Katastrophenschutzbehörden, Feuerwehr und Kommunen liefert Lagebilder, die regulierungsrelevante Einschätzungen fundieren, ohne diese zu ersetzen.',
    practice: ['Übernahme von Lageberichten ohne eigene fachliche Plausibilisierung', 'keine klare Trennung zwischen behördlichen Meldungen und versicherungsrechtlichen Feststellungen', 'Verzögerung bei Priorisierung kritischer Objekte durch unklare Zuständigkeiten'],
  },
  {
    id: 'zusammenarbeit-fachplaner-sanierer',
    category: 'Zusammenarbeit mit Fachplanern und Sanierern',
    tags: ['Fachplaner', 'Statik', 'Sanierung', 'Trocknung', 'Koordination', 'Schadenregulierung'],
    damageTypes: ['gebaeude', 'leitungswasser', 'brand'],
    slugBase: 'zusammenarbeit-fachplaner-sanierer-schadenfall',
    titleBase: 'Zusammenarbeit mit Fachplanern, Statikern und Sanierern im Schadenfall: Rollen und Schnittstellen',
    intro: 'Im komplexen Schadenfall sind mehrere Fachbeteiligte eingebunden – klare Rollenverteilung und dokumentierte Schnittstellen verhindern Informationsverluste und divergierende Einschätzungen.',
    tech: 'Statiker, Trocknungsunternehmen und Sanierer liefern Fachbeiträge zur Bewertung des Schadenbilds. Sachverständige und Regulierer müssen diese Beiträge in die Gesamtakte integrieren, gewichten und daraus Regulierungsentscheidungen ableiten.',
    practice: ['Fachplaner-Gutachten werden nicht in die Regulierungsakte integriert', 'fehlende Abgrenzung zwischen Sanierungsplanung und Schadenregulierung', 'widersprüchliche Empfehlungen mangels Abstimmung zwischen Fachbeteiligten'],
  },
];

const args = process.argv.slice(2);
const argValue = (name) => {
  const token = args.find((item) => item.startsWith(`--${name}=`));
  return token ? token.slice(name.length + 3) : undefined;
};
const hasArg = (name) => args.includes(`--${name}`);

const now = new Date();
const berlinParts = new Intl.DateTimeFormat('en-CA', {
  timeZone: BERLIN, year: 'numeric', month: '2-digit', day: '2-digit', weekday: 'short', hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
}).formatToParts(now);
const part = (type) => berlinParts.find((p) => p.type === type)?.value ?? '';
const berlinDate = `${part('year')}-${part('month')}-${part('day')}`;
const berlinTime = `${part('hour')}:${part('minute')}`;
const berlinWeekday = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'].indexOf((part('weekday') ?? '').toLowerCase().slice(0, 3));

const inWindow = (time, from, to) => time >= from && time <= to;
const detectSlot = () => {
  if (inWindow(berlinTime, SLOT_WINDOWS.morning.from, SLOT_WINDOWS.morning.to)) return 'morning';
  if (inWindow(berlinTime, SLOT_WINDOWS.afternoon.from, SLOT_WINDOWS.afternoon.to)) return 'afternoon';
  return null;
};

const selectedSlotInput = argValue('slot');
const selectedSlot = selectedSlotInput ?? detectSlot();
const force = hasArg('force');
if (!selectedSlot) {
  if (!force) {
    await mkdir(automationDir, { recursive: true });
    await writeFile(runtimeFile, JSON.stringify({
      status: 'skipped',
      reason: `outside-window:${berlinTime}`,
      berlinDate,
      berlinTime,
      berlinTimeZone: BERLIN,
    }, null, 2));
    process.exit(0);
  }
}

const slot = selectedSlot ?? 'morning';
if (!['morning', 'afternoon'].includes(slot)) {
  throw new Error(`Ungültiger Slot: ${slot}`);
}
if (!force && !inWindow(berlinTime, SLOT_WINDOWS[slot].from, SLOT_WINDOWS[slot].to)) {
  await mkdir(automationDir, { recursive: true });
  await writeFile(runtimeFile, JSON.stringify({
    status: 'skipped',
    reason: `outside-window:${slot}:${berlinTime}`,
    berlinDate,
    berlinTime,
    berlinTimeZone: BERLIN,
    slot,
  }, null, 2));
  process.exit(0);
}

const isWeekend = berlinWeekday === 0 || berlinWeekday === 6;
const runId = `${berlinDate}-${slot}`;
const publicationId = `${runId}-${crypto.createHash('sha256').update(runId).digest('hex').slice(0, 10)}`;

const safeSlug = (value) => value
  .toLocaleLowerCase('de-DE')
  .normalize('NFD')
  .replace(/[\u0300-\u036f]/g, '')
  .replace(/ß/g, 'ss')
  .replace(/[^a-z0-9]+/g, '-')
  .replace(/(^-|-$)/g, '');

const readText = (file) => readFile(file, 'utf8').catch(() => '');
const fileExists = async (file) => {
  try {
    await readFile(file);
    return true;
  } catch {
    return false;
  }
};

const csvEscape = (value) => `"${String(value ?? '').replaceAll('"', '""')}"`;
const normalizeText = (value) => value
  .toLocaleLowerCase('de-DE')
  .normalize('NFD')
  .replace(/[\u0300-\u036f]/g, '')
  .replace(/ß/g, 'ss')
  .replace(/[^a-z0-9]+/g, '-')
  .replace(/(^-|-$)/g, '');

const readPublicationRows = async () => {
  const source = await readText(publicationLogFile);
  const rows = source.trim().split(/\r?\n/).filter(Boolean);
  if (rows.length <= 1) return [];
  const headers = rows[0].split(',').map((h) => h.replace(/^"|"$/g, ''));
  return rows.slice(1).map((line) => {
    const cols = [];
    let cur = '';
    let quoted = false;
    for (let i = 0; i < line.length; i += 1) {
      const c = line[i];
      if (c === '"' && line[i + 1] === '"') {
        cur += '"';
        i += 1;
        continue;
      }
      if (c === '"') {
        quoted = !quoted;
        continue;
      }
      if (c === ',' && !quoted) {
        cols.push(cur);
        cur = '';
        continue;
      }
      cur += c;
    }
    cols.push(cur);
    const entry = {};
    headers.forEach((header, idx) => { entry[header] = cols[idx] ?? ''; });
    return entry;
  });
};

const extractRssItems = (xml) => {
  const raw = [...xml.matchAll(/<item>([\s\S]*?)<\/item>/g)].map((m) => m[1]);
  return raw.map((item) => ({
    title: (item.match(/<title><!\[CDATA\[([\s\S]*?)\]\]><\/title>/)?.[1] ?? item.match(/<title>([\s\S]*?)<\/title>/)?.[1] ?? '').replace(/\s+/g, ' ').trim(),
    link: (item.match(/<link>([\s\S]*?)<\/link>/)?.[1] ?? '').trim(),
    pubDate: new Date((item.match(/<pubDate>([\s\S]*?)<\/pubDate>/)?.[1] ?? '').trim() || 0),
    source: (item.match(/<source[^>]*>([\s\S]*?)<\/source>/)?.[1] ?? 'News').trim(),
  })).filter((item) => item.title && item.link);
};

const pickTopic = (rows) => {
  const recentTopicIds = rows.slice(-10).map((r) => r.topic_id).filter(Boolean);
  const usedToday = rows.filter((r) => r.date === berlinDate).map((r) => r.topic_id);
  const available = TOPICS.filter((topic) => !usedToday.includes(topic.id) && !recentTopicIds.includes(topic.id));
  if (available.length > 0) return available[0];
  return TOPICS.find((topic) => !usedToday.includes(topic.id)) ?? TOPICS[0];
};

const createHeadline = (topic, regionalSignal) => {
  if (!regionalSignal) return topic.titleBase;
  return `${topic.titleBase} – fachliche Einordnung zur aktuellen Lage`;
};

const makeImageSvg = (title, subtitle) => `<?xml version="1.0" encoding="UTF-8"?>
<svg width="1200" height="630" viewBox="0 0 1200 630" xmlns="http://www.w3.org/2000/svg" role="img" aria-labelledby="title desc">
  <title id="title">${title}</title>
  <desc id="desc">${subtitle}</desc>
  <defs>
    <linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0%" stop-color="#0a3c53"/>
      <stop offset="100%" stop-color="#0f5f4f"/>
    </linearGradient>
  </defs>
  <rect width="1200" height="630" fill="url(#bg)"/>
  <rect x="70" y="90" width="1060" height="450" rx="24" fill="rgba(255,255,255,0.10)" stroke="rgba(255,255,255,0.28)"/>
  <text x="120" y="190" fill="#d9f4ff" font-family="Arial, Helvetica, sans-serif" font-size="30" font-weight="700">SV-Netzwerk · Fachbeitrag</text>
  <text x="120" y="255" fill="#ffffff" font-family="Arial, Helvetica, sans-serif" font-size="50" font-weight="700">${title.replaceAll('&', '&amp;')}</text>
  <text x="120" y="325" fill="#e8f7f2" font-family="Arial, Helvetica, sans-serif" font-size="31">${subtitle.replaceAll('&', '&amp;')}</text>
  <text x="120" y="490" fill="#d5efe8" font-family="Arial, Helvetica, sans-serif" font-size="27">Kumulschaden · Schadenregulierung · Beweissicherung</text>
</svg>
`;

await mkdir(automationDir, { recursive: true });
const publicationRows = await readPublicationRows();
const slotLabel = SLOT_WINDOWS[slot].label;
const existingSlotRows = publicationRows.filter((row) => row.date === berlinDate && row.slot === slotLabel);
const alreadySuccessful = existingSlotRows.some((row) => row.deploy_status === 'success' && row.live_pruefung === 'success');
if (alreadySuccessful) {
  await writeFile(runtimeFile, JSON.stringify({
    status: 'skipped',
    reason: 'slot-already-published',
    berlinDate,
    slot,
    publicationId,
  }, null, 2));
  process.exit(0);
}
if (existingSlotRows.length > 0 && !force) {
  await writeFile(runtimeFile, JSON.stringify({
    status: 'skipped',
    reason: 'slot-already-recorded',
    berlinDate,
    berlinTime,
    berlinTimeZone: BERLIN,
    slot,
    publicationId,
    existingPublicationId: existingSlotRows[0].publication_id || '',
  }, null, 2));
  process.exit(0);
}

const weekdayRegional = !isWeekend;
let regionalSignal = null;
if (weekdayRegional) {
  const query = encodeURIComponent('(Starkregen OR Hochwasser OR Überflutung OR Rückstau OR Hagel OR Sturm OR Gebäudebrand) (Aalen OR Ostalbkreis OR Heidenheim OR Ulm OR Göppingen OR Stuttgart) when:1d');
  const rssUrl = `https://news.google.com/rss/search?q=${query}&hl=de&gl=DE&ceid=DE:de`;
  try {
    const response = await fetch(rssUrl, { headers: { 'User-Agent': 'sv-netzwerk-automation/1.0' } });
    if (response.ok) {
      const xml = await response.text();
      const nowTs = Date.now();
      const candidates = extractRssItems(xml)
        .filter((item) => Number.isFinite(item.pubDate.getTime()) && Math.abs(nowTs - item.pubDate.getTime()) <= 1000 * 60 * 60 * 72)
        .filter((item) => REGION_HINTS.some((hint) => item.title.toLowerCase().includes(hint.toLowerCase())))
        .filter((item) => EVENT_HINTS.some((hint) => item.title.toLowerCase().includes(hint)))
        .sort((a, b) => b.pubDate.getTime() - a.pubDate.getTime());
      if (candidates.length > 0) regionalSignal = candidates[0];
    }
  } catch {
    regionalSignal = null;
  }
}

const topic = pickTopic(publicationRows);
const title = createHeadline(topic, regionalSignal);
const slug = safeSlug(`${topic.slugBase}-${berlinDate}-${slot}`);
const articleUrl = `https://www.sv-netzwerk.eu/fachwissen/${slug}/`;
const imageFileName = `${slug}.svg`;
const imageWebPath = `/assets/images/linkedin/${imageFileName}`;
const imageUrl = `https://www.sv-netzwerk.eu${imageWebPath}`;
const imageAlt = `Symbolbild: ${topic.category} im Kontext der Schadenregulierung und Kumulschadensteuerung`;
const metaDescription = `${topic.category}: Vorgehen für Schadenaufnahme, Plausibilitätsprüfung, Dokumentation, Sanierungssteuerung und belastbare Regulierung bei hoher Schadenfrequenz.`;
const teaser = regionalSignal
  ? `Öffentlich berichtete Lagemeldungen aus der Region zeigen erhöhten Handlungsdruck. Entscheidend bleibt die fachlich saubere Trennung von Feststellung, Bewertung und Empfehlung.`
  : `${topic.intro} Die Qualität der Dokumentation entscheidet über belastbare Entscheidungen bei hoher Schadenfrequenz.`;

const sources = regionalSignal
  ? [`${regionalSignal.source}: ${regionalSignal.title} (${regionalSignal.link})`]
  : ['Kein belastbarer aktueller Regionalanlass im Suchraum; daher allgemeiner Fachbeitrag gemäß Wochenend-/Fallback-Regel.'];

const body = [
  `${teaser}`,
  '',
  `## Anlass und Einordnung`,
  regionalSignal
    ? `Als fachlicher Aufhänger dienen aktuell öffentlich zugängliche Meldungen aus dem regionalen Umfeld (Suchraum um Aalen). Nach derzeit öffentlich berichteter Lage geht es vor allem um **${topic.category.toLowerCase()}**. Unabhängig von Einzelmeldungen bleibt für die Regulierung entscheidend, dass nur dokumentierte und plausibilisierte Feststellungen in Freigaben überführt werden.`
    : `Dieser Beitrag ordnet ein typisches Kumulschaden-Szenario ohne konkreten Einzelfallbezug ein. Damit werden keine Vor-Ort-Aussagen zu laufenden Ereignissen getroffen, sondern belastbare Vorgehensstandards für Sachverständige und Großschadenregulierer dargestellt.`,
  '',
  `## Technische und regulatorische Einordnung`,
  topic.tech,
  '',
  `Technische Bewertung und versicherungsrechtliche Entscheidung sind getrennt zu behandeln. Dieser Beitrag liefert die fachliche Grundlage für Begutachtung und Regulierung, ersetzt jedoch keine individuelle Deckungsprüfung.`,
  '',
  `In Kumulereignissen ist zudem die Reihenfolge der Bearbeitung entscheidend: Sicherheitsrelevante Objekte werden zuerst stabilisiert, parallel erfolgt die Erstklassifizierung nach Schadenumfang und Komplexität. Dadurch lassen sich Vor-Ort-Ressourcen, Nachunternehmer und Regulierkapazitäten priorisiert steuern, ohne dass die Dokumentationsqualität sinkt.`,
  '',
  `## Koordination im Schadencluster`,
  `Bei hohen Schadenmengen braucht jede Region eine klare Einsatzlogik. Bewährt hat sich ein Dreiklang aus (1) zentraler Lageübersicht, (2) standardisierter Erstbesichtigung und (3) qualitätsgesicherter Nachprüfung. Nur so bleiben Reserven, Freigaben und spätere Rechnungsprüfungen konsistent.`,
  '',
  `Für Sachverständige und Großschadenregulierer bedeutet das konkret:`,
  '- einheitliche Fotologik mit Zeit-/Ortbezug pro Objekt,',
  '- abgestimmte Mindestdaten für Erstbesichtigung,',
  '- klare Eskalationskriterien bei statischen, hygienischen oder brandschutzrelevanten Risiken,',
  '- und ein zentral geführtes Änderungsprotokoll für nachträgliche Erkenntnisse.',
  '',
  `## Beweissicherung und Plausibilitätsprüfung`,
  `Je dichter die Taktung der Besichtigungen, desto höher das Risiko für verkürzte Kausalitätsannahmen. Deshalb müssen Schadenspuren, Vorzustandshinweise und zeitliche Abläufe getrennt erfasst werden. Aussagen zu Ursache und Umfang bleiben ausdrücklich vorläufig, solange Messwerte, Bauteilöffnungen oder ergänzende Unterlagen fehlen.`,
  '',
  `Eine belastbare Plausibilitätsprüfung umfasst mindestens:`,
  '1. zeitlichen Abgleich zwischen gemeldetem Ereignis und dokumentiertem Schadenbild,',
  '2. technische Konsistenz von Einwirkungsart, Spurenlage und Bauteilaufbau,',
  '3. Abgleich mit bereits bekannten Vorschäden, Instandhaltungsdefiziten oder Mängelhinweisen,',
  '4. nachvollziehbare Herleitung, warum einzelne Positionen freigegeben, zurückgestellt oder abgelehnt werden.',
  '',
  `## Sanierungsplanung, Rückbau und Trocknung`,
  `Unter hohem Zeitdruck entstehen häufig zu breite Sanierungsumfänge. Fachlich sinnvoller ist eine stufenweise Strategie: Erst sichern und eingrenzen, dann auf Basis belastbarer Mess- und Sichtbefunde den erforderlichen Rückbau festlegen. Die Trocknungsstrategie muss am Material, an Hohlräumen, an Nutzungsvorgaben und an der wirtschaftlich vertretbaren Wiederherstellung ausgerichtet werden.`,
  '',
  `Für Auftraggeber ist wichtig, dass Sofortmaßnahmen nicht automatisch eine Freigabe aller Folgepositionen bedeuten. Jede Folgemaßnahme braucht einen dokumentierten Bezug zur festgestellten Schadenzone und zum notwendigen Wiederherstellungsziel.`,
  '',
  `## Typische Probleme in der Praxis`,
  ...topic.practice.map((item, index) => `${index + 1}. ${item}`),
  '',
  `## Vorgehen bei Besichtigung, Dokumentation und Regulierung`,
  '1. Lage und Schadenhergang mit Zeitachse dokumentieren; offene Punkte als vorläufig kennzeichnen.',
  '2. Schadenzonen je Bauteil/Gewerk trennen und mit Foto-, Mess- und Protokollkette belegen.',
  '3. Sofortmaßnahmen von Wiederherstellung und Instandhaltung klar abgrenzen.',
  '4. Kostenpositionen nur mit nachvollziehbarem Leistungs- und Mengenbezug reservieren oder freigeben.',
  '5. Abstimmung zwischen Versicherer, Sachverständigen, Regulierern und Dienstleistern protokollieren.',
  '',
  `## Hinweise für Versicherer, Eigentümer und Geschädigte`,
  '- Frühzeitig strukturierte Unterlagen (Fotos, Zeitpunkte, Rechnungen, KVA) bereitstellen.',
  '- Vorläufige Maßnahmen kenntlich machen und spätere Endentscheidungen separat dokumentieren.',
  '- Bei Serienereignissen Priorisierung nach Sicherheits- und Substanzrisiko vornehmen.',
  '',
  `## Fachliches Fazit`,
  `Bei ${topic.category.toLowerCase()} ist nicht die Geschwindigkeit allein entscheidend, sondern die Prüffähigkeit jeder Einzelentscheidung. Wer Feststellung, Bewertung und Empfehlung konsequent trennt, reduziert Nachträge, Konflikte und regulatorische Unsicherheit.`,
  '',
  `Für die operative Umsetzung unterstützen wir mit strukturierter [Schadenmeldung](/schaden-melden/), methodischer [technischer Schadenabgrenzung](/fachwissen/schadenabgrenzung/), [prüffähiger Dokumentation](/fachwissen/prueffaehige-dokumentation/) und – je nach Fragestellung – der [Gutachter-Plattform](/gutachter-plattform/).`,
].join('\n');

const frontmatter = [
  '---',
  `title: "${title}"`,
  `description: "${metaDescription}"`,
  `category: "${topic.category}"`,
  `tags: [${topic.tags.map((tag) => `"${tag}"`).join(', ')}]`,
  'author: "christian-waechter"',
  'featured: false',
  'dailyStandard: true',
  'contentLevel: "B"',
  `teaser: "${teaser}"`,
  `linkedinSummary: "${topic.intro}"`,
  `videoScript: "Wissen in 180 Sekunden: ${topic.intro} Entscheidend sind dokumentierte Feststellungen, klare Abgrenzung von Sofortmaßnahme und Wiederherstellung sowie eine nachvollziehbare Kostenprüfung."`,
  'cta:',
  '  label: "Schaden strukturiert melden"',
  '  href: "/schaden-melden/"',
  'relatedLinks: ["/schaden-melden/", "/fachwissen/schadenabgrenzung/", "/fachwissen/prueffaehige-dokumentation/", "/gutachter-plattform/"]',
  `damageTypes: [${topic.damageTypes.map((item) => `"${item}"`).join(', ')}]`,
  'publication:',
  `  publishedAt: ${berlinDate}`,
  `  updatedAt: ${berlinDate}`,
  '  status: published',
  'seo:',
  `  title: "${title}"`,
  `  description: "${metaDescription}"`,
  `  canonical: "${articleUrl}"`,
  `  image: "${imageUrl}"`,
  `  imageAlt: "${imageAlt}"`,
  '---',
  '',
].join('\n');

const knowledgePath = path.join(knowledgeDir, `${slug}.md`);
if (await fileExists(knowledgePath)) {
  throw new Error(`Beitrag existiert bereits: ${knowledgePath}`);
}
const titleKey = normalizeText(title);
const knowledgeFiles = (await readdir(knowledgeDir)).filter((file) => /\.mdx?$/.test(file));
for (const file of knowledgeFiles) {
  const source = await readText(path.join(knowledgeDir, file));
  const existingTitle = source.match(/^title:\s*"([^"]+)"/m)?.[1] ?? '';
  if (existingTitle && normalizeText(existingTitle) === titleKey) {
    throw new Error(`Titel bereits vorhanden: ${existingTitle} (${file})`);
  }
}

await mkdir(imageDir, { recursive: true });
await mkdir(linkedinDir, { recursive: true });
await mkdir(videosDir, { recursive: true });

await writeFile(knowledgePath, `${frontmatter}${body}\n`);
await writeFile(path.join(imageDir, imageFileName), makeImageSvg(topic.category, slot === 'morning' ? 'Morgendlicher Fachimpuls' : 'Nachmittägliche Facheinordnung'));

const hashtagTokens = ['Kumulschaden', 'Schadenregulierung', ...topic.tags]
  .map((tag) => safeSlug(tag))
  .filter(Boolean);
const hashtags = [...new Set(hashtagTokens)]
  .slice(0, 7)
  .map((token) => `#${token.charAt(0).toUpperCase()}${token.slice(1)}`);

const linkedinText = [
  `${topic.intro}`,
  '',
  regionalSignal
    ? 'Öffentlich gemeldete regionale Lagen zeigen: Bei hoher Schadenfrequenz müssen Feststellung, Plausibilitätsprüfung und Freigabe sauber getrennt bleiben.'
    : 'Gerade ohne konkreten Einzelfallbezug hilft ein klarer Fachstandard, um im nächsten Ereignis strukturiert und prüffähig zu arbeiten.',
  '',
  'Im Beitrag zeigen wir eine belastbare Vorgehensstruktur für Besichtigung, Dokumentation, Sanierungsplanung und Kostenprüfung.',
  '',
  articleUrl,
  '',
  hashtags.join(' '),
].join('\n');
await writeFile(path.join(linkedinDir, `${berlinDate}_${slug}.txt`), `${linkedinText}\n`);

const videoText = `Wissen in 180 Sekunden: ${topic.intro} Heute im Fokus: ${topic.category}. Erst Feststellung und Plausibilität sichern, dann Maßnahmen und Kosten freigeben. Mehr dazu: ${articleUrl}`;
await writeFile(path.join(videosDir, `${berlinDate}_wissen-in-180-sekunden_${slug}.txt`), `${videoText}\n`);

const librarySource = await readText(libraryFile);
if (!librarySource.includes(`href: '/fachwissen/${slug}/'`)) {
  const entry = [
    '  {',
    `    title: '${title.replaceAll("'", "\\'")}',`,
    `    description: '${metaDescription.replaceAll("'", "\\'")}',`,
    `    href: '/fachwissen/${slug}/',`,
    `    category: '${topic.category.replaceAll("'", "\\'")}',`,
    `    tags: [${topic.tags.map((tag) => `'${tag.replaceAll("'", "\\'")}'`).join(', ')}],`,
    `    date: '${berlinDate}',`,
    "    type: 'article',",
    "    featured: false,",
    '  },',
  ].join('\n');
  const updatedLibrary = librarySource.replace('export const library: LibraryItem[] = [', `export const library: LibraryItem[] = [\n${entry}`);
  await writeFile(libraryFile, updatedLibrary);
}

const changelogSource = await readText(changelogFile);
const marker = '# Changelog\n\n';
const logLine = `- automatischer ${SLOT_WINDOWS[slot].label} Fachbeitrag veröffentlicht: „${title}“`;
if (!changelogSource.includes(logLine)) {
  // Detect current highest version and increment patch for automation entry
  const versionMatches = [...changelogSource.matchAll(/^## (\d+)\.(\d+)\.(\d+)/gm)];
  const nextVersion = versionMatches.length > 0
    ? (() => {
        const versions = versionMatches.map((m) => [parseInt(m[1], 10), parseInt(m[2], 10), parseInt(m[3], 10)]);
        versions.sort((a, b) => (a[0] !== b[0] ? b[0] - a[0] : a[1] !== b[1] ? b[1] - a[1] : b[2] - a[2]));
        const [maj, min, patch] = versions[0];
        return `${maj}.${min}.${patch + 1}`;
      })()
    : '1.0.0';
  const dateSection = `## ${nextVersion} – ${berlinDate}`;
  const stampHeader = `${dateSection}\n${logLine}\n- LinkedIn- und Wissen-in-180-Sekunden-Begleitdateien automatisch erstellt\n- Beitragsbild unter ${imageWebPath} erzeugt\n\n`;
  const updated = changelogSource.includes(dateSection)
    ? changelogSource.replace(`${dateSection}\n`, `${dateSection}\n${logLine}\n`)
    : changelogSource.replace(marker, `${marker}${stampHeader}`);
  await writeFile(changelogFile, updated);
}

const headers = ['date', 'slot', 'title', 'url', 'category', 'anlass', 'quellen', 'bilddatei', 'bild_alt_text', 'linkedin_status', 'commit', 'deploy_status', 'live_pruefung', 'topic_id', 'publication_id'];
if (!(await fileExists(publicationLogFile))) {
  await mkdir(path.dirname(publicationLogFile), { recursive: true });
  await writeFile(publicationLogFile, `${headers.map(csvEscape).join(',')}\n`);
}

const anlass = regionalSignal ? `regionaler Anlass: ${regionalSignal.title}` : 'allgemeines Fachthema (kein belastbarer Regionalanlass)';
const csvRow = [
  berlinDate,
  SLOT_WINDOWS[slot].label,
  title,
  articleUrl,
  topic.category,
  anlass,
  sources.join(' | '),
  imageWebPath,
  imageAlt,
  'pending',
  'pending',
  'pending',
  'pending',
  topic.id,
  publicationId,
].map(csvEscape).join(',');
await appendFile(publicationLogFile, `${csvRow}\n`);

const runtime = {
  status: 'generated',
  publicationId,
  berlinDate,
  berlinTime,
  berlinTimeZone: BERLIN,
  slot,
  slotLabel: SLOT_WINDOWS[slot].label,
  isWeekend,
  regionalSignal,
  topicId: topic.id,
  title,
  slug,
  articleUrl,
  imageWebPath,
  imageUrl,
  imageAlt,
  knowledgePath: path.relative(root, knowledgePath).replaceAll('\\', '/'),
  linkedinPath: `src/content/linkedin/${berlinDate}_${slug}.txt`,
  videoPath: `src/content/videos/${berlinDate}_wissen-in-180-sekunden_${slug}.txt`,
  sources,
  hashtags,
  publicationLogFile: path.relative(root, publicationLogFile).replaceAll('\\', '/'),
};

await writeFile(runtimeFile, JSON.stringify(runtime, null, 2));
console.log(JSON.stringify(runtime));
