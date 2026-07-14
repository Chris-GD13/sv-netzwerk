export const routes = {
  home: '/',
  services: '/leistungen/',
  damageTypes: '/schadenarten/',
  knowledge: '/fachwissen/',
  downloads: '/downloads/',
  experts: '/experten/',
  practiceCases: '/praxisfaelle/',
  team: '/netzwerk/',
  search: '/suche/',
  svos: '/svos/',
  about: '/ueber-uns/',
  contact: '/kontakt/',
} as const;

export const knowledgeRoute = (slug: string) => `${routes.knowledge}${slug}/`;
export const categoryRoute = (slug: string) => `${routes.knowledge}kategorie/${slug}/`;
export const tagRoute = (slug: string) => `${routes.knowledge}tag/${slug}/`;
