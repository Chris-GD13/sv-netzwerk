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
    label: 'Schaden melden',
    href: '/schaden-melden/',
    description: 'Direkteinstiege für allgemeine Schadenfälle, Großschaden und Kumulschaden.',
    children: [
      {
        label: 'Schaden melden',
        href: '/schaden-melden/',
        description: 'Allgemeinen Schadenfall mit Unterlagen direkt einreichen.',
      },
      {
        label: 'Großschaden melden',
        href: '/grossschadenregulierung/#grossschaden-formular',
        description: 'Großschaden direkt erfassen und ohne Zwischenschritt beauftragen.',
      },
      {
        label: 'Kumulschaden melden',
        href: '/leistungen/kumulschadenmanagement/#kumulschaden-formular',
        description: 'Kumulschaden- oder Unwetterlage strukturiert melden.',
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
