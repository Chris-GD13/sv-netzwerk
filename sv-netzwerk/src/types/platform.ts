export type ExpertRole = 'Sachverständiger' | 'Großschadenregulierer' | 'Regulierer' | 'Fachberater' | 'Dienstleister' | 'Restaurator' | 'Spezialunternehmen';

export interface ExpertProfile {
  id: string;
  slug: string;
  name: string;
  role: ExpertRole;
  function: string;
  expertise: string[];
  regions: string[];
  qualifications: string[];
  certifications: string[];
  shortProfile: string;
  contact: { email: string; phone?: string };
  image?: string;
  status: 'active' | 'onboarding';
  tags: string[];
  publications: string[];
  practiceCases: string[];
  articles: string[];
}

export interface DamageType {
  id: string;
  slug: string;
  title: string;
  shortTitle: string;
  description: string;
  icon: string;
  color: string;
  typicalLosses: string[];
  inspectionTopics: string[];
  relatedKnowledge: string[];
  relatedCases: string[];
  relatedDownloads: string[];
  relatedExperts: string[];
  seoTitle: string;
  seoDescription: string;
}

export interface PlatformModule {
  title: string;
  description: string;
  href: string;
  status: 'verfügbar' | 'im Ausbau';
  audience: string[];
}
