export type NavChild = {
  label: string;
  href: string;
  description: string;
};

export type NavItem = {
  label: string;
  href: string;
  description?: string;
  children?: NavChild[];
};

export const navigation: NavItem[] = [
  {
    label: 'Start',
    href: '/',
  },
  {
    label: 'Leistungen',
    href: '/leistungen/',
    description: 'Prüfaufträge, Bewertungen und Regulierung strukturiert aus einer Hand.',
    children: [
      { label: 'Sachverständigenleistungen', href: '/leistungen/#sachverstaendigenleistungen', description: 'Feststellungen, Bewertungen und belastbare Empfehlungen.' },
      { label: 'Kumulschadenmanagement', href: '/leistungen/kumulschadenmanagement/', description: 'Skalierbare Bearbeitung gleichartiger Schäden bei Unwetterlagen.' },
      { label: 'Leistungen für Versicherer', href: '/versicherer/', description: 'Prüffähige Zuarbeit für Schadenabteilungen und Regulierer.' },
      { label: 'Großschaden- und Komplexschadenregulierung', href: '/grossschadenregulierung/', description: 'Steuerung komplexer Vorgänge mit klaren Entscheidungsständen.' },
      { label: 'Technische Schadenbewertung', href: '/leistungen/#technische-schadenbewertung', description: 'Ursache, Umfang und Wiederherstellung fachlich einordnen.' },
      { label: 'Beweissicherung', href: '/leistungen/#beweissicherung', description: 'Zustände, Spuren und technische Zusammenhänge dokumentieren.' },
    ],
  },
  {
    label: 'Experten',
    href: '/experten/',
    description: 'Expertennetzwerk mit Regionen, Fachgebieten und abgestimmter Zusammenarbeit.',
    children: [
      { label: 'Netzwerk', href: '/netzwerk/', description: 'Organisation, Verantwortlichkeiten und Zusammenarbeit im Netzwerk.' },
      { label: 'Fachpartner', href: '/partner/', description: 'Ergaenzende Fachpartner und spezialisierte Gewerke.' },
      { label: 'Regionen', href: '/experten/#regionen', description: 'Bundesweite Verfuegbarkeit mit klaren regionalen Schwerpunkten.' },
      { label: 'Fachgebiete', href: '/experten/#fachgebiete', description: 'Disziplinen und Rollen mit klaren Aufgabenprofilen.' },
      { label: 'Zusammenarbeit', href: '/experten/#zusammenarbeit', description: 'Ablauf, Abstimmung und digitale Zusammenarbeit im Netzwerk.' },
      { label: 'Ueber uns', href: '/ueber-uns/', description: 'Selbstverstaendnis, Qualitaetsanspruch und Verantwortung.' },
      { label: 'SVOS', href: '/svos/', description: 'Die gemeinsame Daten-, Fachartikel- und Prozessstruktur.' },
    ],
  },
  {
    label: 'Fachartikel',
    href: '/fachwissen/',
    description: 'Fachliche Grundlagen fuer Pruefung, Dokumentation und Entscheidung.',
    children: [
      { label: 'Fachartikel', href: '/fachwissen/', description: 'Technische Beiträge aus Schadenpraxis und Regulierung.' },
      { label: 'Normen und Rechtsprechung', href: '/fachwissen/#normen-rechtsprechung', description: 'Regelwerke und Entscheidungen praxisnah eingeordnet.' },
      { label: 'A-Z Navigation', href: '/fachwissen/az', description: 'Alphabetischer Zugriff auf alle Fachartikel.' },
    ],
  },
  {
    label: 'Schadenarten',
    href: '/schadenarten/',
    description: 'Technische Einordnung nach Schadenbild, Ursache und Bauteil.',
    children: [
      { label: 'Brand', href: '/schadenarten/#brand', description: 'Brandursache, Beaufschlagung und Sanierungsumfang.' },
      { label: 'Leitungswasser', href: '/schadenarten/#leitungswasser', description: 'Austrittsursache, Durchfeuchtung und Folgeschaeden.' },
      { label: 'Sturm', href: '/schadenarten/#sturm', description: 'Windwirkung, Bauteilversagen und Vorschäden.' },
      { label: 'Elementar', href: '/schadenarten/#elementar', description: 'Ueberflutung, Rueckstau und Naturgefahren.' },
      { label: 'Schimmel', href: '/schadenarten/#schimmel', description: 'Feuchtequelle, mikrobieller Befall und Sanierung.' },
      { label: 'Haftpflicht', href: '/schadenarten/#haftpflicht', description: 'Verursachungsbeitrag und schadenbedingter Umfang.' },
      { label: 'Fenster und Fassade', href: '/schadenarten/#fenster-fassade', description: 'Anschluesse, Dichtheit und Oberflaechenschaeden.' },
      { label: 'Technische Gebaeudeausruestung', href: '/schadenarten/#tga', description: 'Anlagen, Leitungen und technische Schnittstellen.' },
    ],
  },
  {
    label: 'Versicherer',
    href: '/versicherer/',
    description: 'Direkteinstiege fuer Versicherer, Regulierer und Schadenabteilungen.',
    children: [
      { label: 'Anfrage / Beauftragung', href: '/versicherer/#anfrage', description: 'Vorgangsdaten und Leistungsumfang serverseitig uebermitteln.' },
      { label: 'Grossschadenregulierung', href: '/grossschadenregulierung/', description: 'Komplexe Grossschaeden strukturiert steuern.' },
      { label: 'Kumulschadenmanagement', href: '/leistungen/kumulschadenmanagement/', description: 'Skalierbare Bearbeitung von Serien- und Ereignislagen.' },
      { label: 'Schaden melden', href: '/schaden-melden/', description: 'Schadenfall inklusive Unterlagen serverseitig einreichen.' },
    ],
  },
  { label: 'Kontakt', href: '/kontakt/' },
];
