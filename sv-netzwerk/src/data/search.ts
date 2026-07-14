import { library, type LibraryItem } from './library';

export type SearchDocument = LibraryItem & {
  id: string;
  searchable: string;
  categorySlug: string;
  tagSlugs: string[];
};

const normalize = (value: string) => value
  .toLocaleLowerCase('de-DE')
  .normalize('NFD')
  .replace(/[\u0300-\u036f]/g, '')
  .replace(/ß/g, 'ss')
  .replace(/[^a-z0-9]+/g, ' ')
  .trim();

export const searchDocuments: SearchDocument[] = library.map((item, index) => ({
  ...item,
  id: `knowledge-${index + 1}`,
  searchable: normalize([
    item.title,
    item.description,
    item.category,
    ...item.tags,
    item.type,
  ].join(' ')),
  categorySlug: normalize(item.category).replace(/\s+/g, '-'),
  tagSlugs: item.tags.map((tag) => normalize(tag).replace(/\s+/g, '-')),
}));

export const normalizeSearchTerm = normalize;
