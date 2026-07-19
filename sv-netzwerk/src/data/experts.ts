import type { ExpertProfile } from '../types/platform';

const centralContact = { email: 'cw@sv-schuett.eu', phone: '07367 / 393 97 83' };

export const experts: ExpertProfile[] = [
  {
    id: 'expert-christian-waechter', slug: 'christian-waechter', name: 'Christian Wächter', role: 'Sachverständiger', group: 'expert',
    function: 'Sachverständiger und Großschadenregulierer mit DIN EN ISO/IEC 17024-Zertifizierung',
    expertise: ['Komplexschäden', 'Großschadenregulierung', 'Bauforensik', 'Thermografie', 'Drohnen', 'Fenster · Türen · Fassaden'],
    regions: ['Aalen', 'Baden-Württemberg', 'bundesweit nach Aufgabenstellung'],
    qualifications: ['EU-Bausachverständiger', 'Sachverständiger für Bau- und Versicherungsschäden', 'Großschadenregulierer', 'Industriemeister Holzverarbeitung', 'Fenster- und Systemtechniker', 'Montageleiter Fenster und Fassade', 'Fachkraft für Thermografie', 'Drohnenführerschein A1/A2/A3'],
    certifications: ['Personenzertifizierung nach DIN EN ISO/IEC 17024 – Fenster, Türen und Fassaden', 'Sachverständiger Fenster · Türen · Fassaden (DGU/SV)', 'Bauforensik und optische Bauforensik', 'Fachgerechte Planung und Montage von Fenstern und Außentüren (ift Rosenheim)', 'Mechanische Sicherungstechnik an Fenstern und Türen nach DIN 18104'],
    shortProfile: 'Sachverständiger und Großschadenregulierer mit DIN EN ISO/IEC 17024-Zertifizierung. Schwerpunkt: komplexe Gebäude-, Hausrat- und Großschäden, gerichtsfeste Gutachten sowie regulierungssichere Schadenbearbeitung.',
    contact: centralContact, linkedin: 'https://www.linkedin.com/in/christian-w-156408204/', image: '/assets/images/team/christian-waechter-bw.webp', status: 'active',
    tags: ['Komplexschäden', 'Großschadenregulierung', 'Bauforensik'], publications: ['/fachwissen/'],
    practiceCases: ['/praxisfaelle/leitungswasser-technische-abgrenzung/'], articles: ['/fachwissen/schadenabgrenzung/'],
  },
  {
    id: 'expert-marc-schuett', slug: 'marc-schuett', name: 'Marc Schütt', role: 'Sachverständiger', group: 'expert',
    function: 'Öffentlich bestellter und vereidigter Sachverständiger im Tischlerhandwerk',
    expertise: ['Tischlerhandwerk', 'Fenster', 'Türen', 'Fassaden', 'Wertermittlung nach Sachwertverfahren', 'Schlagregenprüfung', 'Luftdichtheitsprüfung', 'Thermografie'],
    regions: ['bundesweit nach Aufgabenstellung'], qualifications: ['Sachverständiger im Tischlerhandwerk'],
    certifications: ['Öffentlich bestellt und vereidigt', 'Vorstandsmitglied im BVS'],
    shortProfile: 'Öffentlich bestellter und vereidigter Sachverständiger im Tischlerhandwerk. Schwerpunkte sind Fenster, Türen, Fassaden, Wertermittlung, Schlagregen- und Luftdichtheitsprüfung sowie Gebäudeanalytik mittels Wärmebildtechnik.',
    contact: centralContact, linkedin: 'https://www.linkedin.com/in/marc-schuett-tischlersv/', image: '/assets/images/team/marc-schuett.jpg', status: 'active', tags: ['Tischlerhandwerk', 'Fenster', 'Fassade'], publications: [], practiceCases: [], articles: [],
  },
  {
    id: 'expert-carmen-gohl', slug: 'carmen-gohl', name: 'Carmen Gohl', role: 'Sachverständiger', group: 'expert',
    function: 'Sachverständige für Sachschadenbewertungen von Immobilien',
    expertise: ['Sachschadenbewertung', 'Schimmelpilze', 'Innenraumschadstoffe', 'Ölschäden', 'Zeitwertermittlung', 'Kumulschäden'],
    regions: ['bundesweit nach Aufgabenstellung'], qualifications: ['Sachschadenbewertung von Immobilien'],
    certifications: ['Personenzertifizierung nach DIN EN ISO/IEC 17024', 'Vorstandsmitglied im BSS'],
    shortProfile: 'Sachverständige für Sachschadenbewertungen von Immobilien. Ihre Schwerpunkte sind Schadenregulierung, Sachschadenermittlung, Schimmelpilze, Feuchteprobleme, Innenraumschadstoffe und Ölschäden.',
    contact: centralContact, linkedin: 'https://www.linkedin.com/in/carmen-gohl-34364324a/', image: '/assets/images/team/carmen-gohl.jpg', status: 'active', tags: ['Sachschadenbewertung', 'Schimmelpilze', 'Innenraumschadstoffe'], publications: [], practiceCases: ['/praxisfaelle/schimmel-dachbereich-ursachenabgrenzung/'], articles: [],
  },
  {
    id: 'expert-claudius-freiberg', slug: 'claudius-freiberg', name: 'Claudius Freiberg', role: 'Sachverständiger', group: 'expert',
    function: 'Öffentlich bestellter und vereidigter Sachverständiger im Tischlerhandwerk',
    expertise: ['Oberflächenschäden', 'Materialbewertung', 'Beschichtungen', 'Holz', 'Aluminium', 'Kunststoff'],
    regions: ['bundesweit nach Aufgabenstellung'], qualifications: ['Sachverständiger im Tischlerhandwerk'], certifications: ['Öffentlich bestellt und vereidigt'],
    shortProfile: 'Öffentlich bestellter und vereidigter Sachverständiger im Tischlerhandwerk mit Schwerpunkt Oberflächenschäden sowie Material- und Beschichtungsbewertung an Holz, Aluminium, Kunststoff und weiteren Werkstoffen.',
    contact: centralContact, linkedin: 'https://www.linkedin.com/in/claudius-freiberg/', image: '/assets/images/team/claudius-freiberg.jpg', status: 'active', tags: ['Oberflächenschäden', 'Materialbewertung', 'Beschichtungen'], publications: [], practiceCases: [], articles: [],
  },
  {
    id: 'expert-lenna-maria-walczok', slug: 'lenna-maria-walczok', name: 'Lenna Maria Walczok, M.Eng.', role: 'Sachverständiger', group: 'expert',
    function: 'Bauingenieurin und DEKRA-zertifizierte Sachverständige für Bauschadenbewertung',
    expertise: ['Baucontrolling', 'Gebäudeschäden', 'Nachtragsprüfung', 'Terminmanagement', 'SiGeKo', 'Sanierungsberatung'],
    regions: ['bundesweit nach Aufgabenstellung'], qualifications: ['Master of Engineering', 'Bauingenieurin', 'Bauschadenbewertung'], certifications: ['DEKRA-zertifizierte Sachverständige'],
    shortProfile: 'Spezialistin für Baucontrolling, Gebäudeschäden, Sanierungsberatung, Nachtragsprüfung, Terminmanagement, SiGeKo und zerstörungsfreie Messtechnik.',
    contact: centralContact, linkedin: 'https://www.linkedin.com/in/lenna-maria-walczok-6bb468291/', image: '/assets/images/team/lenna-maria-walczok.jpg', status: 'active', tags: ['Baucontrolling', 'Gebäudeschäden', 'Nachtragsprüfung'], publications: [], practiceCases: [], articles: [],
  },
  {
    id: 'expert-holger-roth', slug: 'holger-roth', name: 'Holger Roth', role: 'Regulierer', group: 'expert',
    function: 'Schadenregulierer für Sach- und Haftpflichtschäden',
    expertise: ['Sachschäden', 'Haftpflichtschäden', 'Schadenregulierung'], regions: ['Schwäbisch Gmünd', 'Baden-Württemberg', 'Bayern'],
    qualifications: ['Praxisorientierte Schadenregulierung'], certifications: [],
    shortProfile: 'Holger Roth ist im SV-Netzwerk als Schadenregulierer für Sach- und Haftpflichtschäden tätig. Von Schwäbisch Gmünd aus betreut er Teile von Baden-Württemberg und Bayern und unterstützt eine strukturierte, praxisnahe Schadenbearbeitung.',
    contact: centralContact, linkedin: 'https://www.linkedin.com/in/holger-roth-5082306a/', image: '/assets/images/team/holger-roth.jpeg', status: 'active', tags: ['Sachschäden', 'Haftpflichtschäden', 'Schadenregulierung'], publications: [], practiceCases: [], articles: [],
  },
  {
    id: 'backoffice-susanne-waechter', slug: 'susanne-waechter', name: 'Susanne Wächter', role: 'Backoffice', group: 'backoffice',
    function: 'Leitung Backoffice Aalen',
    expertise: ['Projektkoordination', 'Unternehmensmanagement', 'Mandantenbetreuung', 'Dokumentenmanagement', 'Qualitätssicherung', 'Prozesssteuerung'],
    regions: ['Aalen'], qualifications: [], certifications: [],
    shortProfile: 'Susanne Wächter leitet das Backoffice Aalen und verantwortet dort die organisatorischen und kaufmännischen Abläufe, die Mandantenbetreuung sowie verlässliche interne Prozesse.',
    contact: centralContact, image: '/assets/images/team/susanne-waechter.jpeg', status: 'active', tags: ['Organisation', 'Unternehmensmanagement', 'Qualitätssicherung'], publications: [], practiceCases: [], articles: [],
  },
  {
    id: 'backoffice-katja-schaefer', slug: 'katja-schaefer', name: 'Katja Schäfer', role: 'Backoffice', group: 'backoffice',
    function: 'Leitung Backoffice Werdohl',
    expertise: ['Buchhaltung', 'Terminkoordination', 'Nachkalkulationen', 'Administrative Projektbegleitung', 'Strukturierte Abläufe', 'Interne Unterstützung'],
    regions: ['Werdohl'], qualifications: [], certifications: [],
    shortProfile: 'Katja Schäfer leitet das Backoffice Werdohl und verantwortet dort Buchhaltung, Terminkoordination, Nachkalkulationen und die administrative Begleitung laufender Projekte.',
    contact: centralContact, image: '/assets/images/team/katja-schaefer.jpeg', status: 'active', tags: ['Buchhaltung', 'Terminkoordination', 'Projektassistenz'], publications: [], practiceCases: [], articles: [],
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
