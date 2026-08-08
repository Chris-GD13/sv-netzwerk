export type LibraryType = 'article' | 'video';

export type LibraryItem = {
  title: string;
  description: string;
  href: string;
  category: string;
  tags: string[];
  date: string;
  type: LibraryType;
  featured?: boolean;
};

export const library: LibraryItem[] = [
  {
    title: 'Schneedruck und Winterschäden: Statische Bewertung und Regulierung im Kumulereignis',
    description: 'Schneedruck und Winterschäden: Vorgehen für Schadenaufnahme, Plausibilitätsprüfung, Dokumentation, Sanierungssteuerung und belastbare Regulierung bei hoher Schadenfrequenz.',
    href: '/fachwissen/schneedruck-winterschaeden-bewertung-regulierung/',
    category: 'Schneedruck und Winterschäden',
    tags: ['Schneedruck', 'Winterschaden', 'Dach', 'Statik', 'Kumulschaden'],
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
    title: 'Rückstauschaden im Fitnessstudio: Gebäude- und Inhaltschaden trennen',
    description: 'Warum bei Rückstauschäden im Fitnessstudio die klare Trennung von Gebäude- und Inhaltschaden den Regulierungserfolg bestimmt.',
    href: '/fachwissen/rueckstauschaden-im-fitnessstudio-gebaeude-und-inhaltsschaden-trennen/',
    category: 'Starkregen und Rückstau',
    tags: ['Rückstau', 'Schwarzwasser', 'Fitnessstudio', 'Gebäudeversicherung', 'Inhaltsversicherung'],
    date: '2026-07-31',
    type: 'article',
    featured: false,
  },
  {
    title: 'Großflächige Leitungswasserschäden: Sanierungssteuerung unter hoher Schadenfrequenz',
    description: 'Leitungswasserschäden: Vorgehen für Schadenaufnahme, Plausibilitätsprüfung, Dokumentation, Sanierungssteuerung und belastbare Regulierung bei hoher Schadenfrequenz.',
    href: '/fachwissen/grossflaechige-leitungswasserschaeden-sanierungssteuerung/',
    category: 'Leitungswasserschäden',
    tags: ['Leitungswasser', 'Schadenregulierung', 'Sanierungsplanung', 'Kostenprüfung'],
    date: '2026-07-28',
    type: 'article',
    featured: false,
  },
  {
    title: 'Kumulschäden nach Sturm und Hagel – belastbare Prüffolge für die Regulierungspraxis',
    description: 'Praxisorientierte Prüffolge für Versicherer, Sachverständige und Schadenregulierer zur einheitlichen, objektbezogenen Bearbeitung vieler Sturm- und Hagelschäden.',
    href: '/fachwissen/sturm-hagel-serienschaeden-prueffolge-2026-07-21-morning/',
    category: 'Sturm- und Hagelschäden',
    tags: ['Kumulschaden', 'Sturm', 'Hagel', 'Plausibilitätsprüfung'],
    date: '2026-07-21',
    type: 'article',
    featured: false,
  },
  {
    title: 'Kumulschäden nach Hochwasser und Überflutung – Priorisierung, Sofortmaßnahmen und strukturierte Regulierung',
    description: 'Praxisleitfaden für Versicherer, Sachverständige und Schadenregulierer zur strukturierten Bearbeitung vieler Einzelschäden in einer regionalen Kumullage.',
    href: '/fachwissen/hochwasser-ueberflutung-grossschadenkoordination-2026-07-20-morning/',
    category: 'Hochwasser und Überflutung',
    tags: ['Kumulschaden', 'Hochwasser', 'Überflutung', 'Kumulschadenmanagement'],
    date: '2026-07-20',
    type: 'article',
    featured: false,
  },
  {
    title: 'Starkregen und Rückstau: Schadenaufnahme und Regulierung im Kumulereignis',
    description: 'Starkregen und Rückstau: Vorgehen für Schadenaufnahme, Plausibilitätsprüfung, Dokumentation, Sanierungssteuerung und belastbare Regulierung bei hoher Schadenfrequenz.',
    href: '/fachwissen/starkregen-rueckstau-schadenaufnahme-regulierung-2026-07-20-morning/',
    category: 'Starkregen und Rückstau',
    tags: ['Starkregen', 'Rückstau', 'Schadenregulierung', 'Beweissicherung'],
    date: '2026-07-20',
    type: 'article',
    featured: false,
  },
  {
    title: 'Brandschaden nach Erstmaßnahmen: Übergang zur Wiederherstellung sauber steuern',
    description: 'Wie nach Löschung und Sicherung die technische Trennung von Gefahrenabwehr, Wiederherstellung und Instandhaltung gelingt.',
    href: '/fachwissen/brandschaden-notmassnahmen-uebergang-zur-wiederherstellung/',
    category: 'Brandschaden',
    tags: ['Brandschaden', 'Notmaßnahmen', 'Schadenabgrenzung'],
    date: '2026-07-17',
    type: 'article',
    featured: true,
  },
  {
    title: 'Kumulschäden in der Region: Priorisierung, Koordination und belastbare Dokumentation',
    description: 'Wie bei regional gehäuften Schadenlagen Prioritäten gesetzt, Rollen geklärt und die Schadenaufnahme unter Zeitdruck belastbar gehalten wird.',
    href: '/fachwissen/kumulschaeden-in-der-region-priorisierung-koordination-dokumentation/',
    category: 'Kumulschadenmanagement',
    tags: ['Kumulschaden', 'Schadenlage', 'Priorisierung'],
    date: '2026-07-27',
    type: 'article',
    featured: true,
  },
  {
    title: 'Unwetterlage im Kreis Ludwigsburg: Schadensteuerung bei Starkregen, Hagel und Sturm',
    description: 'Wie lokale Unwetterlagen mit hoher Einsatzdichte durch Erstmaßnahmen, Priorisierung und belastbare Dokumentation gesteuert werden.',
    href: '/fachwissen/unwetter-ludwigsburg-starkregen-hagel-sturm-schadensteuerung/',
    category: 'Sturm- und Hagelschäden',
    tags: ['Starkregen', 'Hagelschaden', 'Sturmschaden'],
    date: '2026-07-19',
    type: 'article',
    featured: true,
  },
  {
    title: 'Sturmschaden: Windwirkung, Vorschaden und Bauteilversagen technisch abgrenzen',
    description: 'Wie Windangriffsfläche, Befestigungszustand, Alterung und zeitliche Plausibilität nach Sturm- und Hagelereignissen methodisch getrennt werden.',
    href: '/fachwissen/sturmschaden-windwirkung-vorschaden-abgrenzung/',
    category: 'Sturm- und Hagelschäden',
    tags: ['Sturmschaden', 'Hagelschaden', 'Schadenabgrenzung'],
    date: '2026-07-17',
    type: 'article',
  },
  {
    title: 'Fachliche Zuständigkeit im Schadenfall klar zuordnen',
    description: 'Wie Prüfziel, Fachgebiet, Region und Verantwortung zu einer belastbaren Expertenzuordnung zusammengeführt werden.',
    href: '/fachwissen/fachliche-zustaendigkeit-im-schadenfall/',
    category: 'Prozessqualität',
    tags: ['Experten', 'Zuständigkeit', 'Schadenregulierung'],
    date: '2026-07-27',
    type: 'article',
    featured: true,
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
    href: '/fachwissen/brandschaden-notmassnahmen-uebergang-zur-wiederherstellung/',
    category: 'Brandschaden',
    tags: ['Elektrotechnik', 'Brandursache', 'Lichtbogen'],
    date: '2026-07-09',
    type: 'article',
    featured: true,
  },
  {
    title: 'Sachverständiger für Versicherungsschäden in Aalen: Warum die erste Schadenaufnahme entscheidend ist',
    description: 'Warum die erste Schadenaufnahme bei Gebäude- und Sachschäden in Aalen und der Region Ostwürttemberg über die Qualität der gesamten Regulierung entscheidet.',
    href: '/fachwissen/sachverstaendiger-versicherungsschaeden-aalen-schadenaufnahme/',
    category: 'Schadenregulierung',
    tags: ['Aalen', 'Versicherungsschäden', 'Schadenaufnahme'],
    date: '2026-07-27',
    type: 'article',
  },
  {
    title: 'Regiekosten im Schadenfall prüffähig bewerten',
    description: 'Wie Stundenlohnarbeiten, Materialnachweise und Fremdleistungen im Schadenfall nachvollziehbar geprüft und freigegeben werden.',
    href: '/fachwissen/regiekosten-im-schadenfall-pruefen/',
    category: 'Schadenregulierung',
    tags: ['Regiekosten', 'Rechnungsprüfung', 'Leistungsnachweis'],
    date: '2026-07-27',
    type: 'article',
  },
  {
    title: 'Leitungswasserschaden in Aalen: Aufgaben eines Sachverständigen für Versicherungsschäden',
    description: 'Was ein Sachverständiger für Versicherungsschäden bei Leitungswasserschäden in Aalen prüft: Schadenursache, Rückbauumfang, Kostenabgrenzung und Regulierungsbegleitung.',
    href: '/fachwissen/leitungswasserschaden-aalen-sachverstaendiger-aufgaben/',
    category: 'Leitungswasser',
    tags: ['Leitungswasser', 'Aalen', 'Sachverständiger'],
    date: '2026-07-27',
    type: 'article',
  },
  {
    title: 'Schadenakte nachvollziehbar strukturieren und führbar halten',
    description: 'Wie eine einheitliche Aktenstruktur Entscheidungen beschleunigt, Rückfragen reduziert und Übergaben im Schadenfall absichert.',
    href: '/fachwissen/schadenakte-nachvollziehbar-strukturieren/',
    category: 'Prozessqualität',
    tags: ['Schadenakte', 'Dokumentation', 'Prozessqualität'],
    date: '2026-07-27',
    type: 'article',
  },
  {
    title: 'Leitungswasserschaden: Die ersten Schritte richtig setzen',
    description: 'Welche Sofortmaßnahmen nach einem Leitungswasserschaden entscheidend sind und welche Dokumentation von Beginn an fehlen darf.',
    href: '/fachwissen/leitungswasserschaden-erstmassnahmen/',
    category: 'Leitungswasser',
    tags: ['Leitungswasser', 'Sofortmaßnahmen', 'Schadenminderung'],
    date: '2026-07-27',
    type: 'article',
  },
  {
    title: 'Fenster, Türen und Fassaden technisch beurteilen',
    description: 'Bewertung typischer Schadenbilder an Gebäudehülle, Anschlüssen, Beschlägen und Verglasungen.',
    href: '/fachwissen/wasserschaden-rueckbau-technische-abgrenzung/',
    category: 'Gebäudehülle',
    tags: ['Fenster', 'Fassade', 'Bautechnik'],
    date: '2026-07-03',
    type: 'article',
  },
  {
    title: 'Schadenregulierer für Versicherungen: Technische Prüfung und strukturierte Schadensteuerung',
    description: 'Welche Aufgaben ein Schadenregulierer für Versicherungen übernimmt: von der Erstbesichtigung über Kostenfreigaben bis zur Schlussrechnungsprüfung.',
    href: '/fachwissen/schadenregulierer-versicherungen-technische-pruefung-schadensteuerung/',
    category: 'Schadenregulierung',
    tags: ['Schadenregulierung', 'Schadenregulierer', 'Kostenfreigabe'],
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

export const categories = [...new Set(library.map((item) => item.category))]
  .sort((a, b) => a.localeCompare(b, 'de'));

export const tags = [...new Set(library.flatMap((item) => item.tags))]
  .sort((a, b) => a.localeCompare(b, 'de'));

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

export const sortedLibrary = [...library].sort((a, b) => b.date.localeCompare(a.date));
