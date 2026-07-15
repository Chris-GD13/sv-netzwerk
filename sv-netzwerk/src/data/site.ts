export const site = {
  name: 'SV-Netzwerk',
  legalName: 'SV-Netzwerk Bau & Schaden',
  description: 'Sachverständige, Großschadenregulierung und vernetzte Fachkompetenz für komplexe Bau- und Versicherungsschäden.',
  url: 'https://www.sv-netzwerk.eu',
  email: 'cw@sv-schuett.eu',
  phone: '07367 / 393 97 83',
  address: ['Nordstraße 17', '73432 Aalen'],
  author: {
    name: 'Christian Wächter',
    role: 'Sachverständiger & Großschadenregulierer',
    qualification: 'DIN EN ISO/IEC 17024 zertifiziert'
  },
  version: '5.1.12',
  navigation: [
    { label: 'Leistungen', href: '/leistungen/' },
    { label: 'Schadenarten', href: '/schadenarten/' },
    { label: 'Wissen', href: '/fachwissen/' },
    { label: 'Experten', href: '/experten/' },
    { label: 'Netzwerk', href: '/netzwerk/' },
    { label: 'Kontakt', href: '/kontakt/' }
  ]
} as const;
