import type { CollectionEntry, CollectionKey } from 'astro:content';
import type { TaxonomyTerm } from '../types/svos';

export const isPublished = <C extends CollectionKey>(entry: CollectionEntry<C>) => {
  const publication = 'publication' in entry.data ? entry.data.publication : undefined;
  return publication?.status === 'published';
};

export const sortByPublicationDate = <C extends CollectionKey>(entries: CollectionEntry<C>[]) =>
  [...entries].sort((a, b) => {
    const aDate = 'publication' in a.data ? a.data.publication.publishedAt?.getTime() ?? 0 : 0;
    const bDate = 'publication' in b.data ? b.data.publication.publishedAt?.getTime() ?? 0 : 0;
    return bDate - aDate;
  });

export const buildTaxonomy = (values: string[]): TaxonomyTerm[] => {
  const terms = new Map<string, TaxonomyTerm>();
  values.forEach((label) => {
    const slug = slugify(label);
    const existing = terms.get(slug);
    if (existing) {
      existing.count += 1;
    } else {
      terms.set(slug, { label, count: 1, slug });
    }
  });

  return [...terms.values()]
    .sort((a, b) => a.label.localeCompare(b.label, 'de'));
};

export const slugify = (value: string) => value
  .toLocaleLowerCase('de-DE')
  .normalize('NFD')
  .replace(/[\u0300-\u036f]/g, '')
  .replace(/ß/g, 'ss')
  .replace(/[^a-z0-9]+/g, '-')
  .replace(/(^-|-$)/g, '');
