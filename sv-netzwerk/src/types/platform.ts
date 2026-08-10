export type ExpertRole = 'Sachverständiger' | 'Regulierer' | 'Fachberater' | 'Dienstleister' | 'Restaurator' | 'Spezialunternehmen' | 'Backoffice';

export interface ExpertCompany {
  /** Überschrift des Unternehmensabschnitts, z.B. "Sachverständigenbüro Marc Schütt e.K." */
  name: string;
  /** Beschreibungstext, 1–3 Sätze */
  description: string;
  /** Verlinkungstext, z.B. "Zur Website des Sachverständigenbüros" */
  linkLabel: string;
  /** Vollständige URL der Unternehmenswebsite */
  url: string;
}

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
  /** Eigenständiges Unternehmen des Experten – wird unterhalb des Profiltexts angezeigt */
  company?: ExpertCompany;
  linkedin?: string;
  image?: string;
  imagePosition?: string;
  status: 'active' | 'onboarding';
  tags: string[];
  publications: string[];
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
