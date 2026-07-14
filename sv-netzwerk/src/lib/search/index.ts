import { library } from '../../data/library';
import { slugify } from '../../data/library';
import { normalizeSearchText, tokenizeSearchText } from './normalize';
import type { RankedSearchResult, SearchFilters, SearchIndexItem } from './types';

export const searchIndex: SearchIndexItem[] = library.map((item, index) => {
  const searchable = normalizeSearchText([
    item.title,
    item.description,
    item.category,
    item.type,
    ...item.tags,
  ].join(' '));

  return {
    ...item,
    id: `knowledge-${index + 1}`,
    featured: Boolean(item.featured),
    searchable,
    tokens: tokenizeSearchText(searchable),
    categorySlug: slugify(item.category),
    tagSlugs: item.tags.map(slugify),
  };
});

const scoreDocument = (item: SearchIndexItem, query: string) => {
  const normalizedQuery = normalizeSearchText(query);
  const queryTokens = tokenizeSearchText(query);
  if (!normalizedQuery) return { score: 0, matchedTokens: [] };

  const title = normalizeSearchText(item.title);
  const description = normalizeSearchText(item.description);
  const category = normalizeSearchText(item.category);
  const tags = normalizeSearchText(item.tags.join(' '));
  let score = 0;
  const matchedTokens: string[] = [];

  if (title === normalizedQuery) score += 150;
  if (title.startsWith(normalizedQuery)) score += 100;
  if (title.includes(normalizedQuery)) score += 70;
  if (category === normalizedQuery) score += 45;
  if (category.includes(normalizedQuery)) score += 30;
  if (tags.includes(normalizedQuery)) score += 35;
  if (description.includes(normalizedQuery)) score += 20;

  for (const token of queryTokens) {
    if (title.includes(token)) score += 25;
    if (tags.includes(token)) score += 16;
    if (category.includes(token)) score += 12;
    if (description.includes(token)) score += 7;
    if (item.tokens.includes(token)) matchedTokens.push(token);
  }

  if (item.featured) score += 3;
  return { score, matchedTokens: [...new Set(matchedTokens)] };
};

export const searchKnowledge = (
  filters: SearchFilters,
  limit = 100,
): RankedSearchResult[] => searchIndex
  .filter((item) => filters.category === 'all' || item.category === filters.category)
  .filter((item) => filters.type === 'all' || item.type === filters.type)
  .filter((item) => !filters.tag || item.tags.includes(filters.tag))
  .map((item) => ({ ...item, ...scoreDocument(item, filters.query) }))
  .filter((item) => !normalizeSearchText(filters.query) || item.score > 0)
  .sort((a, b) => b.score - a.score || b.date.localeCompare(a.date))
  .slice(0, limit);

export * from './normalize';
export type * from './types';
