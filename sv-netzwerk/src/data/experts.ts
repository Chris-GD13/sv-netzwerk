import type { ExpertProfile } from '../types/platform';

const centralContact = { email: 'cw@sv-schuett.eu', phone: '07367 / 393 97 83' };

export const experts: ExpertProfile[] = [
  {
    id: 'expert-christian-waechter', slug: 'christian-waechter', name: 'Christian Wächter', role: 'Sachverständiger', group: 'expert',
    function: 'Sachverständiger sowie Regulierung im Komplex- und Großschaden mit DIN EN ISO/IEC 17024-Zertifizierung',
    expertise: ['Komplexschäden', 'Großschadenregulierung', 'Bauforensik', 'Thermografie', 'Drohnen', 'Fenster · Türen · Fassaden'],
    regions: ['Aalen', 'Baden-Württemberg', 'Bundesweit nach Aufgabenstellung'],
    qualifications: ['EU-Bausachverständiger', 'Sachverständiger für Bau- und Versicherungsschäden', 'Regulierung im Komplex- und Großschaden', 'Industriemeister Holzverarbeitung', 'Fenster- und Systemtechniker', 'Montageleiter Fenster und Fassade', 'Fachkraft für Thermografie', 'Drohnenführerschein A1/A2/A3'],
    certifications: ['Personenzertifizierung nach DIN EN ISO/IEC 17024 – Fenster, Türen und Fassaden', 'Sachverständiger Fenster · Türen · Fassaden (DGU/SV)', 'Bauforensik und optische Bauforensik', 'Fachgerechte Planung und Montage von Fenstern und Außentüren (ift Rosenheim)', 'Mechanische Sicherungstechnik an Fenstern und Türen nach DIN 18104'],
    shortProfile: 'Sachverständiger mit DIN EN ISO/IEC 17024-Zertifizierung. Schwerpunkte: komplexe Gebäude-, Hausrat- und Großschäden, gerichtsfeste Gutachten sowie Regulierung im Komplex- und Großschaden.',
    contact: centralContact, linkedin: 'https://www.linkedin.com/in/christian-w-156408204/', image: '/assets/images/team/christian-waechter-weit-bw.webp', status: 'active',
    tags: ['Komplexschäden', 'Großschadenregulierung', 'Bauforensik'], publications: ['/fachwissen/'],
    articles: ['/fachwissen/schadenabgrenzung/'],
  },
  {
    id: 'expert-marc-schuett', slug: 'marc-schuett', name: 'Marc Schütt', role: 'Sachverständiger', group: 'expert',
    function: 'Öffentlich bestellter und vereidigter Sachverständiger im Tischlerhandwerk',
    expertise: ['Tischlerhandwerk', 'Fenster', 'Türen', 'Fassaden', 'Wertermittlung nach Sachwertverfahren', 'Schlagregenprüfung', 'Luftdichtheitsprüfung', 'Thermografie'],
    regions: ['Bundesweit nach Aufgabenstellung'], qualifications: ['Sachverständiger im Tischlerhandwerk'],
    certifications: ['Öffentlich bestellt und vereidigt', 'Vorstandsmitglied im BVS'],
    shortProfile: 'Öffentlich bestellter und vereidigter Sachverständiger im Tischlerhandwerk. Schwerpunkte sind Fenster, Türen, Fassaden, Wertermittlung, Schlagregen- und Luftdichtheitsprüfung sowie Gebäudeanalytik mittels Wärmebildtechnik.',
    contact: { ...centralContact, phone: '02392‑6592751' }, linkedin: 'https://www.linkedin.com/in/marc-schuett-tischlersv/', image: '/assets/images/team/marc-schuett-aktuell-bw.webp', status: 'active', tags: ['Tischlerhandwerk', 'Fenster', 'Fassade'], publications: [], articles: [],
    company: {
      name: 'Sachverständigenbüro Marc Schütt e.K.',
      description: 'Marc Schütt ist Inhaber des Sachverständigenbüros Marc Schütt e.K. mit Sitz in Werdohl. Das Büro ist insbesondere auf Fenster, Türen, Fassaden, Wintergärten, Tischlerhandwerk sowie private und gerichtliche Gutachten spezialisiert.',
      linkLabel: 'Zur Website des Sachverständigenbüros',
      url: 'https://sv-schuett.de',
    },
  },
  {
    id: 'expert-carmen-gohl', slug: 'carmen-gohl', name: 'Carmen Gohl', role: 'Sachverständiger', roleLabel: 'Sachverständige', group: 'expert',
    function: 'Sachverständige für Sachschadenbewertungen von Immobilien',
    expertise: ['Sachschadenbewertung', 'Schimmelpilze', 'Innenraumschadstoffe', 'Ölschäden', 'Zeitwertermittlung', 'Kumulschäden'],
    regions: ['Bundesweit nach Aufgabenstellung'], qualifications: ['Sachschadenbewertung von Immobilien'],
    certifications: ['Personenzertifizierung nach DIN EN ISO/IEC 17024', 'Vorstandsmitglied im BSS'],
    shortProfile: 'Sachverständige für Sachschadenbewertungen von Immobilien. Ihre Schwerpunkte sind Schadenregulierung, Sachschadenermittlung, Schimmelpilze, Feuchteprobleme, Innenraumschadstoffe und Ölschäden.',
    contact: centralContact, linkedin: 'https://www.linkedin.com/in/carmen-gohl-34364324a/', image: '/assets/images/team/carmen-gohl-aktuell-bw.webp', status: 'active', tags: ['Sachschadenbewertung', 'Schimmelpilze', 'Innenraumschadstoffe'], publications: [], articles: [],
    company: {
      name: 'Sachverständigenbüro Carmen Gohl',
      description: 'Carmen Gohl betreibt ein eigenständiges Sachverständigenbüro mit den Schwerpunkten Sachschadenbewertungen von Immobilien, Versicherungswertermittlungen, Schimmelpilze, Innenraumschadstoffe sowie Ölschäden.',
      linkLabel: 'Zur Website des Sachverständigenbüros',
      url: 'https://sv-gohl.de',
    },
  },
  {
    id: 'expert-holger-roth', slug: 'holger-roth', name: 'Holger Roth', role: 'Regulierer', group: 'expert',
    function: 'Schadenregulierer für Sach- und Haftpflichtschäden',
    expertise: ['Sachschäden', 'Haftpflichtschäden', 'Schadenregulierung'], regions: ['Schwäbisch Gmünd', 'Baden-Württemberg', 'Bayern'],
    qualifications: ['Praxisorientierte Schadenregulierung'], certifications: [],
    shortProfile: 'Holger Roth ist im SV-Netzwerk als Schadenregulierer für Sach- und Haftpflichtschäden tätig. Von Schwäbisch Gmünd aus betreut er Teile von Baden-Württemberg und Bayern und unterstützt eine strukturierte, praxisnahe Schadenbearbeitung.',
    contact: centralContact, linkedin: 'https://www.linkedin.com/in/holger-roth-5082306a/', image: '/assets/images/team/holger-roth-weit-bw.webp', status: 'active', tags: ['Sachschäden', 'Haftpflichtschäden', 'Schadenregulierung'], publications: [], articles: [],
  },
  {
    id: 'expert-claudius-freiberg', slug: 'claudius-freiberg', name: 'Claudius Freiberg', role: 'Sachverständiger', group: 'expert',
    function: 'Öffentlich bestellter und vereidigter Sachverständiger im Tischlerhandwerk',
    expertise: ['Oberflächenschäden', 'Materialbewertung', 'Beschichtungen', 'Holz', 'Aluminium', 'Kunststoff'],
    regions: ['Bundesweit nach Aufgabenstellung'], qualifications: ['Sachverständiger im Tischlerhandwerk'], certifications: ['Öffentlich bestellt und vereidigt'],
    shortProfile: 'Öffentlich bestellter und vereidigter Sachverständiger im Tischlerhandwerk mit Schwerpunkt Oberflächenschäden sowie Material- und Beschichtungsbewertung an Holz, Aluminium, Kunststoff und weiteren Werkstoffen.',
    contact: centralContact, linkedin: 'https://www.linkedin.com/in/claudius-freiberg/', image: '/assets/images/team/claudius-freiberg-aktuell-bw.webp', status: 'active', tags: ['Oberflächenschäden', 'Materialbewertung', 'Beschichtungen'], publications: [], articles: [],
    company: {
      name: 'Sachverständigenbüro Claudius Freiberg',
      description: 'Claudius Freiberg führt ein eigenständiges Sachverständigenbüro mit Spezialisierung auf Oberflächen, Material- und Beschichtungsschäden an Holz, Aluminium und Kunststoff sowie auf das Tischler- und Schreinerhandwerk.',
      linkLabel: 'Zur Website des Sachverständigenbüros',
      url: 'https://claudius-freiberg.de/',
    },
  },
  {
    id: 'expert-lenna-maria-walczok', slug: 'lenna-maria-walczok', name: 'Lenna Maria Walczok', role: 'Sachverständiger', roleLabel: 'Sachverständige', group: 'expert',
    function: 'Bauingenieurin und DEKRA-zertifizierte Sachverständige für Bauschadenbewertung',
    expertise: ['Baucontrolling', 'Gebäudeschäden', 'Nachtragsprüfung', 'Terminmanagement', 'SiGeKo', 'Sanierungsberatung'],
    regions: ['Bundesweit nach Aufgabenstellung'], qualifications: ['Master of Engineering', 'Bauingenieurin', 'Bauschadenbewertung'], certifications: ['DEKRA-zertifizierte Sachverständige'],
    shortProfile: 'Spezialistin für Baucontrolling, Gebäudeschäden, Sanierungsberatung, Nachtragsprüfung, Terminmanagement, SiGeKo und zerstörungsfreie Messtechnik.',
    contact: centralContact, linkedin: 'https://www.linkedin.com/in/lenna-maria-walczok-6bb468291/', image: '/assets/images/team/lenna-maria-walczok-aktuell-bw.webp', status: 'active', tags: ['Baucontrolling', 'Gebäudeschäden', 'Nachtragsprüfung'], publications: [], articles: [],
    company: {
      name: 'Netzwerkprofil Lenna Maria Walczok',
      description: 'Weiterführendes Profil von Lenna Maria Walczok im BNI Chapter Weisser Turm Bad Homburg.',
      linkLabel: 'Zum Profil im BNI-Netzwerk',
      url: 'https://bni-wiesbaden.de/chapter-weisser-turm-bad-homburg/de/memberdetails?encryptedMemberId=XHIUKtc11FtzjBHVs%2BmVlA%3D%3D&name=Lenna+Walczok',
    },
  },
  {
    id: 'backoffice-susanne-waechter', slug: 'susanne-waechter', name: 'Susanne Wächter', role: 'Backoffice', group: 'backoffice',
    function: 'Leitung Backoffice Aalen',
    expertise: ['Projektkoordination', 'Unternehmensmanagement', 'Mandantenbetreuung', 'Dokumentenmanagement', 'Qualitätssicherung', 'Prozesssteuerung'],
    regions: ['Aalen'], qualifications: [], certifications: [],
    shortProfile: 'Susanne Wächter leitet das Backoffice Aalen und ist zentrale Ansprechpartnerin für Versicherer, Geschädigte, Verwaltungen und Partnerfirmen bei organisatorischen Fragen sowie zur Koordination und Weiterleitung an den zuständigen Sachverständigen oder Regulierer, bei Terminvereinbarungen, Beauftragungen und der Abstimmung mit den Sachverständigen und Regulierern.',
    contact: centralContact, image: '/assets/images/team/susanne-waechter-buero.webp', status: 'active', tags: ['Organisation', 'Unternehmensmanagement', 'Qualitätssicherung'], publications: [], articles: [],
  },
  {
    id: 'backoffice-katja-schaefer', slug: 'katja-schaefer', name: 'Katja Schäfer', role: 'Backoffice', group: 'backoffice',
    function: 'Leitung Backoffice Werdohl',
    expertise: ['Buchhaltung', 'Terminkoordination', 'Nachkalkulationen', 'Administrative Projektbegleitung', 'Strukturierte Abläufe', 'Interne Unterstützung'],
    regions: ['Werdohl'], qualifications: [], certifications: [],
    shortProfile: 'Katja Schäfer leitet das Backoffice Werdohl und verantwortet dort Buchhaltung, Terminkoordination, Nachkalkulationen und die administrative Begleitung laufender Projekte.',
    contact: centralContact, image: '/assets/images/team/katja-schaefer.jpeg', imagePosition: 'center 14%', status: 'active', tags: ['Buchhaltung', 'Terminkoordination', 'Projektassistenz'], publications: [], articles: [],
  },
];

export const expertDisciplines = [
  { title: 'Sachverständige', text: 'Technische Feststellung, Ursachenbewertung und nachvollziehbare Abgrenzung.' },
  { title: 'Regulierung im Komplex- und Großschaden', text: 'Koordination, Reserveentwicklung und Entscheidungsvorbereitung bei komplexen Vorgängen.' },
  { title: 'Regulierer', text: 'Strukturierte Bearbeitung zwischen Deckung, Technik, Beteiligten und Kosten.' },
  { title: 'Fachberater', text: 'Spezialwissen für klar abgegrenzte technische oder organisatorische Fragestellungen.' },
  { title: 'Dienstleister', text: 'Dokumentierte Ausführung mit definierten Schnittstellen zum verantwortlichen Prüfer.' },
  { title: 'Restauratoren', text: 'Erhaltungs- und Wiederherstellungskonzepte für sensible oder hochwertige Substanz.' },
  { title: 'Spezialunternehmen', text: 'Spezialisierte Verfahren, Messungen und Sanierungsleistungen für besondere Schadenbilder.' },
] as const;

export const getExpert = (slug: string) => experts.find((expert) => expert.slug === slug);
