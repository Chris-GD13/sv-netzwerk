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
      { label: 'Gutachter-Plattform', href: '/gutachter-plattform/', description: 'Digitale Zusammenarbeit im Sachverständigennetzwerk.' },
    ],
  },
  {
    label: 'Schadenarten',
    href: '/schadenarten/',
    description: 'Technische Einordnung nach Schadenbild, Ursache und Bauteil.',
    children: [
      { label: 'Brand', href: '/schadenarten/#brand', description: 'Brandursache, Beaufschlagung und Sanierungsumfang.' },
      { label: 'Leitungswasser', href: '/schadenarten/#leitungswasser', description: 'Austrittsursache, Durchfeuchtung und Folgeschäden.' },
      { label: 'Sturm', href: '/schadenarten/#sturm', description: 'Windwirkung, Bauteilversagen und Vorschäden.' },
      { label: 'Elementar', href: '/schadenarten/#elementar', description: 'Überflutung, Rückstau und Naturgefahren.' },
      { label: 'Schimmel', href: '/schadenarten/#schimmel', description: 'Feuchtequelle, mikrobieller Befall und Sanierung.' },
      { label: 'Haftpflicht', href: '/schadenarten/#haftpflicht', description: 'Verursachungsbeitrag und schadenbedingter Umfang.' },
      { label: 'Fenster und Fassade', href: '/schadenarten/#fenster-fassade', description: 'Anschlüsse, Dichtheit und Oberflächenschäden.' },
      { label: 'Technische Gebäudeausrüstung', href: '/schadenarten/#tga', description: 'Anlagen, Leitungen und technische Schnittstellen.' },
    ],
  },
  {
    label: 'Wissen',
    href: '/fachwissen/',
    description: 'Fachliche Grundlagen für Prüfung, Dokumentation und Entscheidung.',
    children: [
      { label: 'Fachartikel', href: '/fachwissen/', description: 'Technische Beiträge aus Schadenpraxis und Regulierung.' },
      { label: 'Normen und Rechtsprechung', href: '/fachwissen/#normen-rechtsprechung', description: 'Regelwerke und Entscheidungen praxisnah eingeordnet.' },
    ],
  },
  { label: 'Experten', href: '/experten/' },
  {
    label: 'Netzwerk',
    href: '/netzwerk/',
    description: 'Menschen, Organisation und digitale Zusammenarbeit im SV-Netzwerk.',
    children: [
      { label: 'Netzwerk & Organisation', href: '/netzwerk/', description: 'Aufbau, Zusammenarbeit und fachliche Zuständigkeiten im Netzwerk.' },
      { label: 'Über uns', href: '/ueber-uns/', description: 'Arbeitsweise, Qualitätsverständnis und Verantwortung.' },
      { label: 'Fachpartner', href: '/partner/', description: 'Spezialisierte Partner und ergänzende Fachkompetenz.' },
      { label: 'SVOS Core Platform', href: '/svos/', description: 'Die gemeinsame Daten-, Wissens- und Prozessstruktur.' },
      { label: 'Gutachter-Plattform', href: '/gutachter-plattform/', description: 'Strukturierte Zusammenarbeit für moderne Gutachtenprozesse.' },
    ],
  },
  { label: 'Kontakt', href: '/kontakt/' },
];
