export const site = {
  name: 'SV-Netzwerk',
  legalName: 'SV-Netzwerk Bau & Schaden',
  description: 'Sachverständige, Großschaden- und Komplexschadenregulierung und vernetzte Fachkompetenz für komplexe Bau- und Versicherungsschäden.',
  url: 'https://www.sv-netzwerk.eu',
  email: 'info@sv-netzwerk.eu',
  phone: '07367 / 393 97 83',
  address: ['Nordstraße 17', '73432 Aalen'],
  sameAs: [
    'https://github.com/Chris-GD13',
    'https://e7azg7.podcaster.de/',
  ],
  author: {
    name: 'Christian Wächter',
    role: 'Sachverständiger | Regulierung im Komplex- und Großschaden',
    qualification: 'DIN EN ISO/IEC 17024 zertifiziert'
  },
  version: '5.1.12',
  navigation: [
    { label: 'Start', href: '/' },
    { label: 'Leistungen', href: '/leistungen/' },
    { label: 'Experten', href: '/experten/' },
    { label: 'Fachartikel', href: '/fachwissen/' },
    { label: 'Schadenarten', href: '/schadenarten/' },
    { label: 'Versicherer', href: '/versicherer/' },
    { label: 'Kontakt', href: '/kontakt/' }
  ]
} as const;
