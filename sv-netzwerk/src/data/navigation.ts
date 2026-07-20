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
  { label: 'Experten', href: '/experten/' },
  { label: 'Fachartikel', href: '/fachwissen/' },
  { label: 'Kontakt', href: '/kontakt/' },
];
