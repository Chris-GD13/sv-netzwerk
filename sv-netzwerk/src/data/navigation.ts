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
  { label: 'Start', href: '/' },
  {
    label: 'Gutachter-Plattform',
    href: '/gutachter-plattform/',
    description: 'Fachliche Prüfleistungen und Gutachter-Plattform.',
    children: [
      {
        label: 'Gutachter-Plattform',
        href: '/gutachter-plattform/',
        description: 'Prüfleistungen und Zusammenarbeit kennenlernen.',
      },
      {
        label: 'Prüfauftrag anfragen',
        href: '/gutachter-plattform/anfrage/',
        description: 'Fachlichen Prüfbedarf strukturiert übermitteln.',
      },
    ],
  },
  {
    label: 'Für Versicherer',
    href: '/versicherer-schadensteuerung/',
    description: 'Schadensteuerung für Versicherer, Schadenabteilungen und Maklergesellschaften.',
    children: [
      {
        label: 'Schadensteuerung für Versicherer',
        href: '/versicherer-schadensteuerung/',
        description: 'Zentrale Erstaufnahme, Triage, Koordination und prüffähige Dokumentation außerhalb Kfz.',
      },
      {
        label: 'Beauftragung und Anfrage',
        href: '/versicherer/',
        description: 'Direkte Beauftragung oder Anfrage für Versicherer und Regulierer.',
      },
      {
        label: 'Großschadenregulierung',
        href: '/grossschadenregulierung/',
        description: 'Priorisierte Steuerung umfangreicher Schadenfälle.',
      },
    ],
  },
  { label: 'Experten', href: '/experten/' },
  { label: 'Fachartikel', href: '/fachwissen/' },
  { label: 'Podcast', href: '/podcast/' },
  { label: 'Kontakt', href: '/kontakt/' },
];
