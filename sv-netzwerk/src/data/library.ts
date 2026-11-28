Warning: truncated output (original token count: 23345)
Total output lines: 1587

export type LibraryType = 'article' | 'video';

export type LibraryItem = {
  title: string;
  description: string;
  href: string;
  category: string;
  tags: string[];
  date: string;
  publishedAt?: string;
  type: LibraryType;
  featured?: boolean;
  earlyAccess?: boolean;
};

export const library: LibraryItem[] = [
  {
    title: 'Schlussrechnung nach der Schadensanierung: Aufmaß, Nachträge und Restarbeiten trennen',
    description: 'Wie Schlussrechnungen nach einer Schadensanierung anhand von Aufmaß, ausgeführten Leistungen, Nachträgen und offenen Restarbeiten nachvollziehbar geprüft werden.',
    href: '/fachwissen/schlussrechnung-schadensanierung-aufmass-nachtraege-restarbeiten/',
    category: 'Schadenregulierung',
    tags: ['Schlussrechnung', 'Schadensanierung', 'Aufmaß', 'Nachträge', 'Restarbeiten', 'Rechnungsprüfung', 'Kostenprüfung'],
    date: '2026-11-28',
    publishedAt: '2026-11-28T05:00:00.000Z',
    type: 'article',
  },

  {
    title: 'Mieterregress bei einer Geräteleckage: Anschluss, Kausalität und Schadenumfang sauber trennen',
    description: 'Wie bei einer Geräteleckage Anschluss, technische Ursache, Mietgebrauch, Schadenumfang und ein möglicher Regress fachlich getrennt geprüft werden.',
    href: '/fachwissen/mieterregress-geraeteleckage-anschluss-kausalitaet-schadenumfang/',
    category: 'Leitungswasser und Haftung',
    tags: ['Geräteleckage', 'Waschmaschine', 'Mieterregress', 'Leitungswasser', 'Kausalität', 'Schadenabgrenzung', 'Beweissicherung', 'Kostenprüfung'],
    date: '2026-11-27',
    publishedAt: '2026-11-27T05:00:00.000Z',
    type: 'article',
  },
  {
    title: "Mietminderung nach einem Gebäudeschaden: Nutzungsbeeinträchtigung, Zeitraum und Nachweis trennen",
    description: "Wie bei einer Mietminderung nach einem Gebäudeschaden die tatsächliche Gebrauchseinschränkung, der betroffene Zeitraum und der Schadenbezug nachvollziehbar dokumentiert werden.",
    href: '/fachwissen/mietminderung-gebaeudeschaden-nutzungsbeeintraechtigung-zeitraum-nachweis/',
    category: "Schadenregulierung",
    tags: ["Mietminderung","Gebäudeschaden","Nutzbarkeit","Schadenzeitraum","Dokumentation","Schadenregulierung"],
    date: '2026-11-21',
    publishedAt: '2026-11-21T05:00:00.000Z',
    type: 'article',
  },
  {
    title: "Schutz- und Abdeckmaßnahmen im Gebäudeschaden: Erforderlichkeit, Dauer und Kosten prüfen",
    description: "Wie Schutz- und Abdeckmaßnahmen nach einem Gebäudeschaden technisch begründet, zeitlich kontrolliert und von der eigentlichen Wiederherstellung getrennt werden.",
    href: '/fachwissen/schutz-und-abdeckmassnahmen-gebaeudeschaden-erforderlichkeit-kosten/',
    category: "Schadenregulierung",
    tags: ["Schadenminderung","Schutzmaßnahmen","Abdeckarbeiten","Gebäudeschaden","Kostenprüfung","Dokumentation"],
    date: '2026-11-22',
    publishedAt: '2026-11-22T05:00:00.000Z',
    type: 'article',
  },
  {
    title: "Baustellenzugang bei bewohntem Gebäude: Nutzbarkeit, Schutz und Sanierungsablauf trennen",
    description: "Wie Zugang, Schutz der Bewohner und der laufende Sanierungsablauf bei einem bewohnten Gebäude nachvollziehbar abgestimmt und kostenmäßig getrennt werden.",
    href: '/fachwissen/baustellenzugang-wohnbetrieb-schadensanierung-schutzkoordination/',
    category: "Sanierungsplanung und Baukoordination",
    tags: ["Bewohntes Gebäude","Baustellenzugang","Nutzbarkeit","Schadensanierung","Baukoordination","Schadenminderung"],
    date: '2026-11-23',
    publishedAt: '2026-11-23T05:00:00.000Z',
    type: 'article',
  },
  {
    title: "Verdeckte Leistungen in der Schadensanierung: Öffnungen, Aufmaß und Nachweis sichern",
    description: "Wie verdeckte Leistungen vor dem Schließen von Bauteilen mit Befund, Aufmaß und Fotodokumentation so gesichert werden, dass Umfang und Kosten prüffähig bleiben.",
    href: '/fachwissen/verdeckte-leistungen-schadensanierung-dokumentation-pruefung/',
    category: "Sanierungsplanung und Baukoordination",
    tags: ["Verdeckte Leistungen","Bauteilöffnung","Aufmaß","Fotodokumentation","Schadensanierung","Kostenprüfung"],
    date: '2026-11-24',
    publishedAt: '2026-11-24T05:00:00.000Z',
    type: 'article',
  },
  {
    title: "Vorläufige Kostenfreigaben im Schadenfall: Leistungsstand, Prüfvorbehalt und Schlussrechnung trennen",
    description: "Wie vorläufige Kostenfreigaben im Schadenfall so formuliert und fortgeschrieben werden, dass Umfang, Annahmen und spätere Schlussprüfung nachvollziehbar bleiben.",
    href: '/fachwissen/vorlaeufige-kostenfreigabe-schadensanierung-pruefvorbehalt-schlussrechnung/',
    category: "Schadenregulierung",
    tags: ["Kostenfreigabe","Prüfvorbehalt","Schadenregulierung","Leistungsstand","Schlussrechnung","Dokumentation"],
    date: '2026-11-25',
    publishedAt: '2026-11-25T05:00:00.000Z',
    type: 'article',
  },
  {
    title: "Wiederinbetriebnahme nach Gebäudeschäden: Funktionsprüfung, Freigabe und Kosten trennen",
    description: "Wie technische Anlagen nach einem Gebäudeschaden sicher geprüft und in einzelnen Stufen wieder in Betrieb genommen und schadenbedingte Kosten von Verbesserungen abgegrenzt werden.",
    href: '/fachwissen/wiederinbetriebnahme-gebaeudeschaden-funktionspruefung-freigabe-kosten/',
    category: "Gebäudetechnik und Schadenregulierung",
    tags: ["Wiederinbetriebnahme","Funktionsprüfung","Gebäudetechnik","Wasserschaden","Brandschaden","Kostenabgrenzung"],
    date: '2026-11-26',
    publishedAt: '2026-11-26T05:00:00.000Z',
    type: 'article',
  },
  {
    title: 'Projektwechsel in der Schadensanierung: Leistungsstand, Restarbeiten und Kosten sauber übergeben',
    description: 'Wie bei einem Wechsel der Projektleitung Leistungsstand, offene Arbeiten, Mängel und Kosten nachvollziehbar übernommen und weitergeführt werden.',
    href: '/fachwissen/projektwechsel-schadensanierung-leistungsstand-restarbeiten-kosten/',
    category: 'Sanierungsplanung und Baukoordination',
    tags: ['Schadensanierung', 'Projektwechsel', 'Leistungsstand', 'Restarbeiten', 'Baukoordination', 'Dokumentation', 'Kostenprüfung', 'Abnahme'],
    date: '2026-11-20',
    publishedAt: '2026-11-20T05:00:00.000Z',
    type: 'article',
  },
  {
    title: 'Wie viele Quadratmeter Blechdach sind tatsächlich betroffen?',
    description: 'Ein Rechenbeispiel zeigt, wie sich Außenmaß, Dachüberstand und Dachneigung auf die Mengen im Angebot auswirken.',
    href: '/fachwissen/blechdach-aufmass-grundflaeche-dachneigung/',
    category: 'Sturm- und Hagelschäden',
    tags: ['Dachaufmaß', 'Profilblech', 'Hagelschaden', 'Kostenprüfung'],
    date: '2026-11-19',
    publishedAt: '2026-11-19T05:00:00.000Z',
    type: 'article',
  },
  {
    title: 'Sachverständigenberichte im Gebäudeschaden: Befund, Bewertung und offene Punkte trennen',
    description: 'Wie technische Feststellungen, fachliche Bewertungen, offene Prüfungen und Kostenabgrenzung in einem Schadenbericht nachvollziehbar bleiben.',
    href: '/fachwissen/sachverstaendigenbericht-befund-bewertung-offene-punkte/',
    category: 'Schadenregulierung',
    tags: ['Sachverständigenbericht', 'Schadenaufnahme', 'Befund', 'Beweissicherung', 'Kostenabgrenzung', 'Qualitätssicherung', 'Dokumentation'],
    date: '2026-11-18',
    publishedAt: '2026-11-18T05:00:00.000Z',
    type: 'article',
  },

  {
    title: 'Drohnenaufnahmen bei mehreren Hagelschäden: Vergleichbarkeit, Objekttrennung und Beweiskette',
    description: 'Wie Drohnenaufnahmen bei mehreren Hagelschäden einheitlich geplant, objektbezogen dokumentiert und von der technischen Bewertung getrennt werden.',
    href: '/fachwissen/drohnen-serienbefliegung-hagelschaeden-vergleichbarkeit/',
    category: 'Sturm- und Hagelschäden',
    tags: ['Hagelschaden', 'Drohne', 'Serienbefliegung', 'Beweissicherung', 'Fotodokumentation', 'Dach', 'Schadenabgrenzung', 'Kostenprüfung'],
    date: '2026-11-17',
    publishedAt: '2026-11-17T05:00:00.000Z',
    type: 'article',
  },

  {
    title: 'Wenn Regenwasser über ein Entlüftungsrohr in das Gebäude gelangt',
    description: 'Warum ein Entlüftungsrohr bestimmungsgemäß kein häusliches Leitungswasser führt und wie Niederschlagswasser, Einregnung, Rohrfunktion und Vertragsprüfung fachlich getrennt werden.',
    href: '/fachwissen/entlueftungsrohr-niederschlagswasser-einregnung-leitungswasserabgrenzung/',
    category: 'Regulierung und Versicherungsrecht',
    tags: ['Entlüftungsrohr', 'Niederschlagswasser', 'Einregnung', 'Leitungswasserschaden', 'Korrosion', 'Schadenregulierung'],
    date: '2026-11-16',
    publishedAt: '2026-11-16T05:00:00.000Z',
    type: 'article',
    featured: false,
  },

  {
    title: 'Wiederholte Kamerabefahrung bei Leitungswasserschäden: technische Notwendigkeit und Rechnungsprüfung',
    description: 'Wie technische Fragestellung, Kamerabefund und Rechnungspositionen bei wiederholten Untersuchungen nach Leitungswasserschäden prüffähig getrennt werden.',
    href: '/fachwissen/lws-kamerabefahrung-rechnungspruefung/',
    category: 'Leitungswasser und Feuchte',
    tags: ['Leitungswasser', 'Feuchteschaden', 'Kamerabefahrung', 'Leckageortung', 'Rechnungsprüfung', 'Ursachenprüfung', 'Dokumentation', 'Kostenprüfung'],
    date: '2026-11-16',
    publishedAt: '2026-11-16T05:00:00.000Z',
    type: 'article',
  },

  {
    title: 'Drohnenaufnahmen bei PV-Hagelschäden: Bildbefund, Prüfgrenzen und Vor-Ort-Nachweis trennen',
    description: 'Wie Drohnenbilder bei PV-Hagelschäden sinnvoll eingesetzt werden und warum Bildbefund, elektrische Prüfung und Reparaturentscheidung getrennt bleiben müssen.',
    href: '/fachwissen/pv-drohne-hagelschaden-pruefgrenzen/',
    category: 'Photovoltaik und Gebäudetechnik',
    tags: ['Photovoltaik', 'PV-Anlage', 'Hagelschaden', 'Drohne', 'Bildbefund', 'Elektrosicherheit', 'Thermografie', 'Kostenprüfung'],
    date: '2026-11-15',
    publishedAt: '2026-11-15T05:00:00.000Z',
    type: 'article',
  },
  {
    title: 'Wiederkehrende Feuchte nach einer Reparatur: Ursache, Nachbesserung und neue Schadenfolge trennen',
    description: 'Wie Reparaturzustand, aktuelle Messwerte, Kamerabefund und neuer Schadenumfang bei wiederkehrender Feuchte nachvollziehbar getrennt werden.',
    href: '/fachwissen/lws-wiederkehrende-feuchte-reparatur-nachbesserung-schadenfolge/',
    category: 'Leitungswasser und Feuchte',
    tags: ['Leitungswasser', 'Feuchteschaden', 'Nachbesserung', 'Leckageortung', 'Kamerabefahrung', 'Ursachenprüfung', 'Schadenabgrenzung', 'Kostenprüfung'],
    date: '2026-11-14',
    publishedAt: '2026-11-14T05:00:00.000Z',
    type: 'article',
  },
  {
    title: 'Nach der Freigabe einer Brandstelle: Übergabe, Befund und Sanierungsstart fachlich sichern',
    description: 'Wie nach der Freigabe einer Brandstelle Befund, Zuständigkeiten, Sicherungsstatus und Sanierungsbeginn prüffähig getrennt dokumentiert werden.',
    href: '/fachwissen/brandschaden-freigabe-uebergabe-sanierungsstart/',
    category: 'Brand und Kontamination',
    tags: ['Brandschaden', 'Brandstelle', 'Freigabe', 'Übergabe', 'Beweissicherung', 'Sanierungsbeginn', 'Dokumentation', 'Kostenprüfung'],
    date: '2026-11-13',
    publishedAt: '2026-11-13T05:00:00.000Z',
    type: 'article',
  },
  {
    title: 'Brandschaden im Wohnraum: Brandspuren, Ermittlungsstand und erste Maßnahmen fachlich trennen',
    description: 'Wie Brandspuren, Ermittlungsstand, Sicherheitsfreigabe, Reinigung und begrenzter Rückbau nach einem Wohnraumbrand nachvollziehbar getrennt bewertet werden.',
    href: '/fachwissen/brandschaden-wohnraum-beweissicherung-ermittlungsstand/',
    category: 'Brand und Kontamination',
    tags: ['Brandschaden', 'Brandursachenermittlung', 'Beweissicherung', 'Rauchschaden', 'Sicherheitsfreigabe', 'Rückbau', 'Schadenminderung', 'Kostenprüfung'],
    date: '2026-11-12',
    publishedAt: '2026-11-12T05:00:00.000Z',
    type: 'article',
  },
  {
    title: 'Naturgefahrenschäden an Holzkonstruktionen: Feuchtepfad, Tragfähigkeit und Rückbau fachlich begrenzen',
    description: 'Wie Eintrittsweg, Schichtenaufbau, Holzfeuchte, Tragfähigkeit, Trocknung und erforderlicher Rückbau nach einem Naturgefahrenschaden nachvollziehbar getrennt bewertet werden.',
    href: '/fachwissen/naturgefahrenschaden-holzkonstruktion-feuchte-tragfaehigkeit-rueckbau/',
    category: 'Elementar- und Naturgefahrenschäden',
    tags: ['Elementarschaden', 'Naturgefahr', 'Holzkonstruktion', 'Feuchte', 'Tragfähigkeit', 'Rückbau', 'Schadenminderung', 'Kostenprüfung'],
    date: '2026-11-11',
    publishedAt: '2026-11-11T05:00:00.000Z',
    type: 'article',
  },
  {
    title: 'Parkett nach einer Geräteleckage: Randzonen, Untergrund und Austauschumfang fachlich prüfen',
    description: 'Wie Austrittspfad, Parkettrandzonen, Klebung, Untergrund, Restfeuchte und der erforderliche Austauschumfang nach einer Leckage am Haushaltsgerät bewertet werden.',
    href: '/fachwissen/parkett-leckage-haushaltsgeraet-randzonen-untergrund-austauschumfang/',
    category: 'Leitungswasser',
    tags: ['Leitungswasserschaden', 'Parkett', 'Geräteleckage', 'Untergrund', 'Schadenabgrenzung', 'Kostenprüfung'],
    date: '2026-11-10',
    publishedAt: '2026-11-10T05:00:00.000Z',
    type: 'article',
  },
  {
    title: 'Leitungswasserschäden im Mehrfamilienhaus: Leitungsstrang, Gemeinschaftseigentum und Wohnungsschäden trennen',
    description: 'Wie Leitungsstrang, gemeinschaftliche Bauteile, Sondereigentum, Folgeschäden und Trocknungsumfang bei einem Rohrbruch über mehrere Geschosse getrennt bewertet werden.',
    href: '/fachwissen/leitungswasserschaden-mehrfamilienhaus-leitungsstrang-gemeinschaftseigentum/',
    category: 'Leitungswasser',
    tags: ['Leitungswasserschaden', 'Mehrfamilienhaus', 'Leitungsstrang', 'Gemeinschaftseigentum', 'Sondereigentum', 'Trocknung'],
    date: '2026-11-09',
    publishedAt: '2026-11-09T05:00:00.000Z',
    type: 'article',
  },
  {
 …16345 tokens truncated…
    tags: ['Starkregen', 'Rückstau', 'Rückstauebene', 'Schadenabgrenzung', 'Dokumentation'],
    date: '2026-08-03',
    type: 'article',
    featured: false,
  },
  {
    title: 'Frostbedingte Leitungswasserschäden: Rohrbruch, Auftauen und Vorschäden fachlich abgrenzen',
    description: 'Wie frostbedingte Leitungswasserschäden von Korrosion, Materialermüdung, Montagefehlern und Vorschäden getrennt und ohne vorschnelle Deckungsannahmen bewertet werden.',
    href: '/fachwissen/frostbedingte-leitungswasserschaeden-rohrbruch-abgrenzung/',
    category: 'Leitungswasser',
    tags: ['Leitungswasser', 'Frostschaden', 'Rohrbruch', 'Schadenabgrenzung', 'Dokumentation'],
    date: '2026-08-02',
    type: 'article',
    featured: false,
  },
  {
    title: 'Brandschaden mit mehreren betroffenen Gebäuden: Struktur für Erstaufnahme und Regulierung',
    description: 'Brandschaden: Vorgehen für Schadenaufnahme, Plausibilitätsprüfung, Dokumentation, Sanierungssteuerung und belastbare Regulierung bei hoher Schadenfrequenz.',
    href: '/fachwissen/brandschaden-mehrere-gebaeude-koordination/',
    category: 'Brandschaden',
    tags: ['Brandschaden', 'Mehrere Gebäude', 'Schadenaufnahme', 'Regulierung'],
    date: '2026-08-01',
    type: 'article',
    featured: false,
  },
  {
    title: 'Rückstauschaden im Fitnessstudio: Gebäude- und Inhaltsschaden fachlich trennen',
    description: 'Wie Rückstauschäden mit Schwarzwasserkontamination in gewerblich genutzten Fitnessstudios technisch eingeordnet, Gebäude- und Inhaltsschäden abgegrenzt und Maßnahmen prüffähig dokumentiert werden.',
    href: '/fachwissen/rueckstauschaden-im-fitnessstudio-gebaeude-und-inhaltsschaden-trennen/',
    category: 'Starkregen und Rückstau',
    tags: ['Rückstau', 'Schwarzwasser', 'Fitnessstudio', 'Gebäudeversicherung', 'Inhaltsversicherung'],
    date: '2026-07-31',
    type: 'article',
    featured: false,
  },
  {
    title: 'Großflächige Leitungswasserschäden: Sanierungssteuerung unter hoher Schadenfrequenz',
    description: 'Wie bei regional gehäuften Leitungswasserschäden Erstmaßnahmen, Plausibilitätsprüfung, Trocknung, Rückbau und Kostenprüfung strukturiert gesteuert werden – für belastbare Regulierungsergebnisse bei hoher Fallzahl.',
    href: '/fachwissen/grossflaechige-leitungswasserschaeden-sanierungssteuerung/',
    category: 'Leitungswasserschäden',
    tags: ['Leitungswasser', 'Schadenregulierung', 'Sanierungsplanung', 'Kostenprüfung', 'Kumulschäden'],
    date: '2026-07-28',
    type: 'article',
    featured: false,
  },
  {
    title: 'Kumulschäden nach Sturm und Hagel – belastbare Prüffolge für die Regulierungspraxis',
    description: 'Praxisorientierte Prüffolge für Versicherer, Sachverständige und Schadenregulierer zur einheitlichen, objektbezogenen Bearbeitung vieler Sturm- und Hagelschäden: Kausalitätsprüfung, Sofortmaßnahmen, Freigaben und Nachtragskontrolle.',
    href: '/fachwissen/sturm-hagel-serienschaeden-prueffolge-2026-07-21-morning/',
    category: 'Sturm- und Hagelschäden',
    tags: ['Kumulschaden', 'Sturm', 'Hagel', 'Plausibilitätsprüfung', 'Schadenregulierung'],
    date: '2026-07-21',
    type: 'article',
    featured: false,
  },
  {
    title: 'Kumulschäden nach Hochwasser und Überflutung – Priorisierung, Sofortmaßnahmen und strukturierte Regulierung',
    description: 'Praxisleitfaden für Versicherer, Sachverständige und Schadenregulierer zur strukturierten Bearbeitung vieler Einzelschäden in einer regionalen Kumullage: Priorisierung, Beweissicherung, Kontaminationsbewertung und Teilfreigaben.',
    href: '/fachwissen/hochwasser-ueberflutung-grossschadenkoordination-2026-07-20-morning/',
    category: 'Hochwasser und Überflutung',
    tags: ['Kumulschaden', 'Hochwasser', 'Überflutung', 'Kumulschadenmanagement', 'Schadenregulierung'],
    date: '2026-07-20',
    type: 'article',
    featured: false,
  },
  {
    title: 'Starkregen und Rückstau: Schadenaufnahme und Regulierung im Kumulereignis',
    description: 'Starkregen und Rückstau: Vorgehen für Schadenaufnahme, Plausibilitätsprüfung, Dokumentation, Sanierungssteuerung und belastbare Regulierung bei hoher Schadenfrequenz.',
    href: '/fachwissen/starkregen-rueckstau-schadenaufnahme-regulierung-2026-07-20-morning/',
    category: 'Starkregen und Rückstau',
    tags: ['Starkregen', 'Rückstau', 'Schadenregulierung', 'Beweissicherung', 'Kumulschäden'],
    date: '2026-07-20',
    type: 'article',
    featured: false,
  },
  {
    title: 'Brandschaden nach Erstmaßnahmen: Übergang zur Wiederherstellung sauber steuern',
    description: 'Wie nach Löschung und Sicherung die technische Trennung von akuter Gefahrenabwehr, schadenbedingter Wiederherstellung und Instandhaltung gelingt.',
    href: '/fachwissen/brandschaden-notmassnahmen-uebergang-zur-wiederherstellung/',
    category: 'Brandschaden',
    tags: ['Brandschaden', 'Notmaßnahmen', 'Schadenabgrenzung', 'Dokumentation'],
    date: '2026-07-17',
    type: 'article',
    featured: true,
  },
  {
    title: 'Kumulschäden in der Region: Priorisierung, Koordination und belastbare Dokumentation',
    description: 'Wie bei regional gehäuften Schadenlagen Prioritäten gesetzt, Rollen geklärt und die Schadenaufnahme unter Zeitdruck belastbar gehalten wird.',
    href: '/fachwissen/kumulschaeden-in-der-region-priorisierung-koordination-dokumentation/',
    category: 'Kumulschadenmanagement',
    tags: ['Kumulschaden', 'Schadenlage', 'Priorisierung', 'Koordination'],
    date: '2026-07-19',
    type: 'article',
    featured: true,
  },

  {
    title: 'Sturmschaden: Windwirkung, Vorschaden und Bauteilversagen technisch abgrenzen',
    description: 'Wie Windangriffsfläche, Befestigungszustand, Alterung und zeitliche Plausibilität nach Sturm- und Hagelereignissen methodisch getrennt werden.',
    href: '/fachwissen/sturmschaden-windwirkung-vorschaden-abgrenzung/',
    category: 'Sturm- und Hagelschäden',
    tags: ['Sturmschaden', 'Hagelschaden', 'Schadenabgrenzung', 'Vorschaden'],
    date: '2026-07-17',
    type: 'article',
  },
  {
    title: 'Fachliche Zuständigkeit im Schadenfall klar zuordnen',
    description: 'Wie Prüfziel, Fachgebiet, Region und Verantwortung zu einer belastbaren Expertenzuordnung zusammengeführt werden.',
    href: '/fachwissen/fachliche-zustaendigkeit-im-schadenfall/',
    category: 'Prozessqualität',
    tags: ['Experten', 'Zuständigkeit', 'Schadenregulierung', 'Dokumentation'],
    date: '2026-07-15',
    type: 'article',
    featured: false,
  },
  {
    title: 'Technische Schadenabgrenzung als Grundlage der Regulierung',
    description: 'Wie Ursache, Vorschaden, Folgeschaden, erforderliche Wiederherstellung und Kosten methodisch getrennt werden.',
    href: '/fachwissen/schadenabgrenzung/',
    category: 'Schadenregulierung',
    tags: ['Schadenabgrenzung', 'Kausalität', 'Vorschaden'],
    date: '2026-07-27',
    type: 'article',
    featured: true,
  },
  {
    title: 'Technische Dokumentation bei komplexen Gebäudeschäden',
    description: 'Wie Feststellungen, Bewertungen, Maßnahmen und Kosten bei mehrgewerklichen Schäden prüffähig zusammengeführt werden.',
    href: '/fachwissen/prueffaehige-dokumentation/',
    category: 'Prozessqualität',
    tags: ['Dokumentation', 'Prüffähigkeit', 'Gebäudeschaden'],
    date: '2026-07-27',
    type: 'article',
  },
  {
    title: 'Wasserschaden: Rückbau technisch abgrenzen',
    description: 'Warum ein vollständiger Rückbau nicht automatisch erforderlich ist und welche Feststellungen für eine belastbare Abgrenzung entscheidend sind.',
    href: '/fachwissen/wasserschaden-rueckbau-technische-abgrenzung/',
    category: 'Leitungswasser',
    tags: ['Rückbau', 'Feuchteschaden', 'Schadenabgrenzung'],
    date: '2026-07-14',
    type: 'article',
    featured: true,
  },
  {
    title: 'Kontrollierter Rückbau bei Leitungswasserschäden',
    description: 'Wie Schadenminderung, gezielte Öffnungen und technische Trocknung ohne pauschalen Komplettausbau zusammenwirken.',
    href: '/fachwissen/kontrollierter-rueckbau-bei-leitungswasserschaeden/',
    category: 'Leitungswasser',
    tags: ['Leitungswasser', 'Schadenminderung', 'Rückbau'],
    date: '2026-07-27',
    type: 'article',
  },
  {
    title: 'Lichtbogen an einer LED-Lichtleiste',
    description: 'Technische Einordnung eines elektrischen Entstehungsbrandes mit Fokus auf Brandursache, Spurenbild und Schadenumfang.',
    href: '/fachwissen/lichtbogen-led-lichtleiste-brandschaden/',
    category: 'Brandschaden',
    tags: ['Elektrotechnik', 'Brandursache', 'Lichtbogen'],
    date: '2026-07-14',
    type: 'article',
    featured: true,
  },
  {
    title: 'Sachverständiger für Versicherungsschäden in Aalen: Warum die erste Schadenaufnahme entscheidend ist',
    description: 'Warum die erste Schadenaufnahme bei Gebäude- und Sachschäden in Aalen und der Region Ostwürttemberg über die Qualität der gesamten Regulierung entscheidet: Ursache, Schadenbereiche, Sofortmaßnahmen und Dokumentation strukturiert erfassen.',
    href: '/fachwissen/sachverstaendiger-versicherungsschaeden-aalen-schadenaufnahme/',
    category: 'Schadenregulierung',
    tags: ['Aalen', 'Versicherungsschäden', 'Schadenaufnahme', 'Ostalbkreis'],
    date: '2026-07-27',
    type: 'article',
  },
  {
    title: 'Regiekosten im Schadenfall prüffähig bewerten',
    description: 'Wie Stundenlohnarbeiten, Materialnachweise und Fremdleistungen im Schadenfall nachvollziehbar geprüft und freigegeben werden.',
    href: '/fachwissen/regiekosten-im-schadenfall-pruefen/',
    category: 'Schadenregulierung',
    tags: ['Regiekosten', 'Rechnungsprüfung', 'Leistungsnachweis', 'Dokumentation'],
    date: '2026-07-16',
    type: 'article',
  },
  {
    title: 'Leitungswasserschaden in Aalen: Aufgaben eines Sachverständigen für Versicherungsschäden',
    description: 'Was ein Sachverständiger für Versicherungsschäden bei Leitungswasserschäden in Aalen prüft: Schadenursache, Rückbauumfang, Kostenabgrenzung und Regulierungsbegleitung von der Erstbesichtigung bis zur Schlussrechnungsprüfung.',
    href: '/fachwissen/leitungswasserschaden-aalen-sachverstaendiger-aufgaben/',
    category: 'Leitungswasser',
    tags: ['Leitungswasser', 'Aalen', 'Sachverständiger', 'Ostalbkreis', 'Rückbau'],
    date: '2026-07-27',
    type: 'article',
  },
  {
    title: 'Schadenakte nachvollziehbar strukturieren und führbar halten',
    description: 'Wie eine einheitliche Aktenstruktur Entscheidungen beschleunigt, Rückfragen reduziert und Übergaben im Schadenfall absichert.',
    href: '/fachwissen/schadenakte-nachvollziehbar-strukturieren/',
    category: 'Prozessqualität',
    tags: ['Schadenakte', 'Dokumentation', 'Prozessqualität', 'Schadenregulierung'],
    date: '2026-07-17',
    type: 'article',
  },
  {
    title: 'Leitungswasserschaden: Die ersten Schritte richtig setzen',
    description: 'Welche Sofortmaßnahmen nach einem Leitungswasserschaden entscheidend sind, wie Eintrittspfad und Schadenumfang von Beginn an dokumentiert werden und wo Fachgrenzen zur weitergehenden Sanierung liegen.',
    href: '/fachwissen/leitungswasserschaden-erstmassnahmen/',
    category: 'Leitungswasser',
    tags: ['Leitungswasser', 'Sofortmaßnahmen', 'Schadenminderung', 'Dokumentation'],
    date: '2026-07-18',
    type: 'article',
  },
  {
    title: 'Fenster, Türen und Fassaden technisch beurteilen',
    description: 'Bewertung typischer Schadenbilder an Gebäudehülle, Anschlüssen, Beschlägen und Verglasungen.',
    href: '/fachwissen/fenster-tueren-fassaden/',
    category: 'Gebäudehülle',
    tags: ['Fenster', 'Fassade', 'Bautechnik'],
    date: '2026-07-03',
    type: 'article',
  },
  {
    title: 'Schadenregulierer für Versicherungen: Technische Prüfung und strukturierte Schadensteuerung',
    description: 'Welche Aufgaben ein Schadenregulierer für Versicherungen übernimmt, wie technische Prüfung, Kostenfreigaben und Dokumentation strukturiert ablaufen und wo die Fachgrenzen zur versicherungsrechtlichen Deckungsentscheidung liegen.',
    href: '/fachwissen/schadenregulierer-versicherungen-technische-pruefung-schadensteuerung/',
    category: 'Schadenregulierung',
    tags: ['Schadenregulierung', 'Schadenregulierer', 'Kostenfreigabe', 'Prüffähige Dokumentation'],
    date: '2026-07-27',
    type: 'article',
  },
  {
    title: 'Gutachter-Plattform: SEO und Schadensteuerung',
    description: 'Wie strukturierte digitale Prozesse Sichtbarkeit, Auftragsklarheit und Qualität in der Schadensteuerung verbessern.',
    href: '/fachwissen/svos-foundation/',
    category: 'Gutachter-Plattform',
    tags: ['Digitalisierung', 'SEO', 'Schadensteuerung'],
    date: '2026-07-01',
    type: 'article',
  },
];

export const PAGE_SIZE = 6;

export const slugify = (value: string) => value
  .toLocaleLowerCase('de-DE')
  .normalize('NFD')
  .replace(/[\u0300-\u036f]/g, '')
  .replace(/ß/g, 'ss')
  .replace(/[^a-z0-9]+/g, '-')
  .replace(/(^-|-$)/g, '');

export const formatDate = (value: string) => new Intl.DateTimeFormat('de-DE', {
  day: '2-digit',
  month: '2-digit',
  year: 'numeric',
}).format(new Date(`${value}T12:00:00`));

import { filterPublishedLibraryItems } from '../utils/publication';

const publicationTime = (item: LibraryItem) => {
  const value = item.publishedAt ?? `${item.date}T00:00:00Z`;
  const parsed = Date.parse(value);
  return Number.isNaN(parsed) ? 0 : parsed;
};

export const sortedLibrary = filterPublishedLibraryItems([...library]).sort((a, b) => publicationTime(b) - publicationTime(a));
export const categories = [...new Set(sortedLibrary.map((item) => item.category))]
  .sort((a, b) => a.localeCompare(b, 'de'));

export const tags = [...new Set(sortedLibrary.flatMap((item) => item.tags))]
  .sort((a, b) => a.localeCompare(b, 'de'))
  .filter((tag, index, all) => all.findIndex((candidate) => slugify(candidate) === slugify(tag)) === index);
