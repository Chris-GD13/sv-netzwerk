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
  { label: 'Experten', href: '/experten/' },
  { label: 'Fachartikel', href: '/fachwissen/' },
  { label: 'Podcast', href: '/podcast/' },
  { label: 'Kontakt', href: '/kontakt/' },
];
