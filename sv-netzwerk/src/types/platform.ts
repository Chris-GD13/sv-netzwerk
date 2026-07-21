export type ExpertRole = 'Sachverständiger' | 'Großschadenregulierer' | 'Regulierer' | 'Fachberater' | 'Dienstleister' | 'Restaurator' | 'Spezialunternehmen' | 'Backoffice';

export interface ExpertProfile {
  id: string;
  slug: string;
  name: string;
  role: ExpertRole;
  roleLabel?: string;
  group: 'expert' | 'backoffice';
  function: string;
  expertise: string[];
  regions: string[];
  qualifications: string[];
  certifications: string[];
  shortProfile: string;
  contact: { email: string; phone?: string };
  linkedin?: string;
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
