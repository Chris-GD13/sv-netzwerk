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
  { label: 'Schaden melden', href: '/schaden-melden/' },
  { label: 'Großschaden', href: '/grossschadenregulierung/#grossschaden-formular' },
  { label: 'Kumulschaden', href: '/leistungen/kumulschadenmanagement/#kumulschaden-formular' },
  { label: 'Leistungen', href: '/leistungen/' },
  { label: 'Gutachter-Plattform', href: '/gutachter-plattform/anfrage/' },
  { label: 'Experten', href: '/experten/' },
  { label: 'Fachartikel', href: '/fachwissen/' },
  { label: 'Kontakt', href: '/kontakt/' },
];
