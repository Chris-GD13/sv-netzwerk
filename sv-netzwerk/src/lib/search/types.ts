export type SearchContentType = 'article' | 'download' | 'video' | 'page' | 'damage' | 'case' | 'expert';

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
  type: SearchContentType;
  featured: boolean;
  searchable: string;
  tokens: string[];
};

export type SearchFilters = {
  query: string;
  category: string;
  type: SearchContentType | 'all';
  tag: string;
};

export type RankedSearchResult = SearchIndexItem & {
  score: number;
  matchedTokens: string[];
};
