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
    title: 'Fachliche Zuständigkeit im Schadenfall klar zuordnen',
    description: 'Wie Prüfziel, Fachgebiet, Region und Verantwortung zu einer belastbaren Expertenzuordnung zusammengeführt werden.',
    href: '/fachwissen/fachliche-zustaendigkeit-im-schadenfall/',
    category: 'Prozessqualität',
    tags: ['Experten', 'Zuständigkeit', 'Schadenregulierung'],
    date: '2026-07-15',
    type: 'article',
    featured: true,
  },
  {
    title: 'Technische Schadenabgrenzung als Grundlage der Regulierung',
    description: 'Wie Ursache, Vorschaden, Folgeschaden, erforderliche Wiederherstellung und Kosten methodisch getrennt werden.',
    href: '/fachwissen/schadenabgrenzung/',
    category: 'Schadenregulierung',
    tags: ['Schadenabgrenzung', 'Kausalität', 'Vorschaden'],
    date: '2026-07-14',
    type: 'article',
    featured: true,
  },
  {
    title: 'Technische Dokumentation bei komplexen Gebäudeschäden',
    description: 'Wie Feststellungen, Bewertungen, Maßnahmen und Kosten bei mehrgewerklichen Schäden prüffähig zusammengeführt werden.',
    href: '/fachwissen/prueffaehige-dokumentation/',
    category: 'Prozessqualität',
    tags: ['Dokumentation', 'Prüffähigkeit', 'Gebäudeschaden'],
    date: '2026-07-13',
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
    date: '2026-07-12',
    type: 'article',
  },
  {
    title: 'Lichtbogen an einer LED-Lichtleiste',
    description: 'Technische Einordnung eines elektrischen Entstehungsbrandes mit Fokus auf Brandursache, Spurenbild und Schadenumfang.',
    href: '/fachwissen/lichtbogen-led-lichtleiste-brandschaden/',
    category: 'Brandschaden',
    tags: ['Elektrotechnik', 'Brandursache', 'Lichtbogen'],
    date: '2026-07-09',
    type: 'article',
    featured: true,
  },
  {
    title: 'Komplexschäden strukturiert regulieren',
    description: 'Prüf- und Dokumentationsstruktur für Groß- und Komplexschäden mit klaren Zuständigkeiten, Reserven und Entscheidungspunkten.',
    href: '/fachwissen/komplexschaeden-regulierung/',
    category: 'Schadenregulierung',
    tags: ['Großschaden', 'SVOS', 'Dokumentation'],
    date: '2026-07-08',
    type: 'article',
  },
  {
    title: 'Schadenreserve im Großschaden richtig herleiten',
    description: 'Wie belastbare Reserven aus Schadenbild, Mengen, Leistungen, Preisständen und verbleibenden Unsicherheiten entwickelt werden.',
    href: '/fachwissen/schadenreserve-grossschaden-regulierung/',
    category: 'Schadenregulierung',
    tags: ['Reserve', 'Großschaden', 'Kalkulation'],
    date: '2026-07-07',
    type: 'article',
  },
  {
    title: 'Prüffähige Unterlagen in der Schadenregulierung',
    description: 'Welche Angaben, Nachweise und Belege für eine nachvollziehbare technische und kaufmännische Prüfung erforderlich sind.',
    href: '/fachwissen/prueffaehige-unterlagen-schadenregulierung/',
    category: 'Prozessqualität',
    tags: ['Prüffähigkeit', 'Rechnungsprüfung', 'Nachweise'],
    date: '2026-07-06',
    type: 'article',
  },
  {
    title: 'Baucontrolling und Nachtragsmanagement',
    description: 'Methodik zur Prüfung von Leistungsänderungen, Mengen, Nachträgen und Kostenentwicklungen in laufenden Sanierungsmaßnahmen.',
    href: '/fachwissen/baucontrolling-nachtragsmanagement/',
    category: 'Baucontrolling',
    tags: ['Nachträge', 'Kostenkontrolle', 'Leistungsprüfung'],
    date: '2026-07-05',
    type: 'article',
  },
  {
    title: 'Schimmelpilzschäden fachlich bewerten',
    description: 'Ursachenklärung, Feuchtepfade, Nutzungseinflüsse und erforderliche Sanierungsschritte nachvollziehbar voneinander abgrenzen.',
    href: '/fachwissen/schimmelpilzschaeden-feuchte/',
    category: 'Feuchte und Schimmel',
    tags: ['Schimmel', 'Feuchte', 'Ursachenanalyse'],
    date: '2026-07-04',
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
    title: 'Oberflächenschäden richtig zuordnen',
    description: 'Abgrenzung von Beschädigung, Verschleiß, Vorschaden, optischer Beeinträchtigung und technisch notwendiger Wiederherstellung.',
    href: '/fachwissen/oberflaechenschaeden/',
    category: 'Bautechnik',
    tags: ['Oberflächen', 'Vorschaden', 'Wiederherstellung'],
    date: '2026-07-02',
    type: 'article',
  },
  {
    title: 'Gutachter-Plattform: SEO und Schadensteuerung',
    description: 'Wie strukturierte digitale Prozesse Sichtbarkeit, Auftragsklarheit und Qualität in der Schadensteuerung verbessern.',
    href: '/fachwissen/gutachter-plattform-seo-indexierung-schadensteuerung/',
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
