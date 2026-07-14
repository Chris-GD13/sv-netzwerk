import type { CollectionEntry, CollectionKey } from 'astro:content';
import type { TaxonomyTerm } from '../types/svos';

export const isPublished = <C extends CollectionKey>(entry: CollectionEntry<C>) => {
  const publication = 'publication' in entry.data ? entry.data.publication : undefined;
  return publication?.status === 'published';
};

export const sortByPublicationDate = <C extends CollectionKey>(entries: CollectionEntry<C>[]) =>
  [...entries].sort((a, b) => {
    const aDate = 'publication' in a.data ? a.data.publication.publishedAt.getTime() : 0;
    const bDate = 'publication' in b.data ? b.data.publication.publishedAt.getTime() : 0;
    return bDate - aDate;
  });

export const buildTaxonomy = (values: string[]): TaxonomyTerm[] => {
  const counts = new Map<string, number>();
  values.forEach((value) => counts.set(value, (counts.get(value) ?? 0) + 1));

  return [...counts.entries()]
    .map(([label, count]) => ({ label, count, slug: slugify(label) }))
    .sort((a, b) => a.label.localeCompare(b.label, 'de'));
};

export const slugify = (value: string) => value
  .toLocaleLowerCase('de-DE')
  .normalize('NFD')
  .replace(/[\u0300-\u036f]/g, '')
  .replace(/ß/g, 'ss')
  .replace(/[^a-z0-9]+/g, '-')
  .replace(/(^-|-$)/g, '');
