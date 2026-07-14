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
      { label: 'Großschadenregulierung', href: '/leistungen/#grossschadenregulierung', description: 'Steuerung komplexer Vorgänge mit klaren Entscheidungsständen.' },
      { label: 'Technische Schadenbewertung', href: '/leistungen/#technische-schadenbewertung', description: 'Ursache, Umfang und Wiederherstellung fachlich einordnen.' },
      { label: 'Beweissicherung', href: '/leistungen/#beweissicherung', description: 'Zustände, Spuren und technische Zusammenhänge dokumentieren.' },
      { label: 'Versicherer', href: '/versicherer/', description: 'Prüffähige Zuarbeit für Schadenabteilungen und Regulierer.' },
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
      { label: 'Photovoltaik', href: '/schadenarten/#photovoltaik', description: 'Module, Unterkonstruktion und elektrische Komponenten.' },
      { label: 'Fenster und Fassade', href: '/schadenarten/#fenster-fassade', description: 'Anschlüsse, Dichtheit und Oberflächenschäden.' },
      { label: 'Technische Gebäudeausrüstung', href: '/schadenarten/#tga', description: 'Anlagen, Leitungen und technische Schnittstellen.' },
    ],
  },
  {
    label: 'Fachwissen',
    href: '/fachwissen/',
    description: 'Fachliche Grundlagen für Prüfung, Dokumentation und Entscheidung.',
    children: [
      { label: 'Fachartikel', href: '/fachwissen/', description: 'Technische Beiträge aus Schadenpraxis und Regulierung.' },
      { label: 'Normen und Rechtsprechung', href: '/fachwissen/#normen-rechtsprechung', description: 'Regelwerke und Entscheidungen praxisnah eingeordnet.' },
      { label: 'Praxisfälle', href: '/praxisfaelle/', description: 'Anonymisierte Fälle mit Feststellung und Bewertung.' },
      { label: 'Downloads', href: '/downloads/', description: 'Prüfschemata, Checklisten und Arbeitshilfen.' },
      { label: 'Wissen in 180 Sekunden', href: '/fachwissen/#wissen-180', description: 'Kernfragen kompakt und fachlich präzise erklärt.' },
    ],
  },
  { label: 'Praxisfälle', href: '/praxisfaelle/' },
  { label: 'Downloads', href: '/downloads/' },
  { label: 'Netzwerk', href: '/netzwerk/' },
  { label: 'SVOS', href: '/svos/' },
  { label: 'Über uns', href: '/ueber-uns/' },
  { label: 'Kontakt', href: '/kontakt/' },
];
