export type PublicationStatus = 'draft' | 'review' | 'published' | 'archived';

export interface SeoMetadata {
  title?: string;
  description?: string;
  canonical?: string;
  noindex?: boolean;
  image?: string;
  imageAlt?: string;
}

export interface PublicationMetadata {
  publishedAt: Date;
  updatedAt?: Date;
  status: PublicationStatus;
}

export interface TaxonomyTerm {
  label: string;
  slug: string;
  count: number;
}

export interface SearchDocument {
  id: string;
  collection: 'knowledge' | 'downloads' | 'practiceCases' | 'authors';
  title: string;
  description: string;
  href: string;
  category?: string;
  tags: string[];
  publishedAt?: string;
}
