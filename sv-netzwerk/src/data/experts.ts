import type { ExpertProfile } from '../types/platform';

export const experts: ExpertProfile[] = [
  {
    id: 'expert-christian-waechter',
    slug: 'christian-waechter',
    name: 'Christian Wächter',
    role: 'Sachverständiger',
    function: 'Sachverständiger und Großschadenregulierer',
    expertise: ['Bau- und Versicherungsschäden', 'Großschadenregulierung', 'Leitungswasser', 'Fenster und Fassade'],
    regions: ['Aalen', 'Baden-Württemberg', 'bundesweit nach Aufgabenstellung'],
    qualifications: ['Sachverständigenleistungen für Bau- und Versicherungsschäden'],
    certifications: ['Personenzertifizierung nach DIN EN ISO/IEC 17024'],
    shortProfile: 'Christian Wächter verantwortet technische Schadenbewertung, strukturierte Großschadenregulierung und die fachliche Entwicklung des SV Operating System.',
    contact: { email: 'cw@sv-schuett.eu', phone: '07367 / 393 97 83' },
    status: 'active',
    tags: ['Bautechnik', 'Schadenregulierung', 'SVOS'],
    publications: ['/fachwissen/'],
    practiceCases: ['/praxisfaelle/leitungswasser-technische-abgrenzung/'],
    articles: ['/fachwissen/technische-dokumentation-komplexe-gebaeudeschaeden/'],
  },
];

export const expertDisciplines = [
  { title: 'Sachverständige', text: 'Technische Feststellung, Ursachenbewertung und nachvollziehbare Abgrenzung.' },
  { title: 'Großschadenregulierer', text: 'Koordination, Reserveentwicklung und Entscheidungsvorbereitung bei komplexen Vorgängen.' },
  { title: 'Regulierer', text: 'Strukturierte Bearbeitung zwischen Deckung, Technik, Beteiligten und Kosten.' },
  { title: 'Fachberater', text: 'Spezialwissen für klar abgegrenzte technische oder organisatorische Fragestellungen.' },
  { title: 'Dienstleister', text: 'Dokumentierte Ausführung mit definierten Schnittstellen zum verantwortlichen Prüfer.' },
  { title: 'Restauratoren', text: 'Erhaltungs- und Wiederherstellungskonzepte für sensible oder hochwertige Substanz.' },
  { title: 'Spezialunternehmen', text: 'Spezialisierte Verfahren, Messungen und Sanierungsleistungen für besondere Schadenbilder.' },
] as const;

export const getExpert = (slug: string) => experts.find((expert) => expert.slug === slug);
