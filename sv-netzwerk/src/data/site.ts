export const site = {
  name: 'SV-Netzwerk',
  title: 'SV-Netzwerk – Sachverständige und Großschadenregulierung',
  description:
    'Fachwissen, Sachverständigenleistungen und strukturierte Großschadenregulierung.',
  url: 'https://www.sv-netzwerk.eu',
  language: 'de-DE',
  author: {
    name: 'Christian Wächter',
    role: 'Sachverständiger & Großschadenregulierer',
    qualification: 'DIN EN ISO/IEC 17024 zertifiziert',
  },
  navigation: [
    { label: 'Start', href: '/' },
    { label: 'Leistungen', href: '/leistungen/' },
    { label: 'Fachwissen', href: '/fachwissen/' },
    { label: 'Großschadenregulierung', href: '/grossschadenregulierung/' },
    { label: 'Gutachter-Plattform', href: '/gutachter-plattform/' },
    { label: 'Kontakt', href: '/kontakt/' },
  ],
} as const;
