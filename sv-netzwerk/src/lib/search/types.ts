import type { LibraryType } from '../../data/library';

export type SearchIndexItem = {
  id: string;
  title: string;
  description: string;
  href: string;
  category: string;
  categorySlug: string;
  tags: string[];
  tagSlugs: string[];
  date: string;
  type: LibraryType;
  featured: boolean;
  searchable: string;
  tokens: string[];
};

export type SearchFilters = {
  query: string;
  category: string;
  type: LibraryType | 'all';
  tag: string;
};

export type RankedSearchResult = SearchIndexItem & {
  score: number;
  matchedTokens: string[];
};
