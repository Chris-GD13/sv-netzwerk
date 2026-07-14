import type { CollectionEntry } from 'astro:content';
import type { PracticeCaseFilter } from '../../types/practice-cases';

export type PracticeCaseEntry = CollectionEntry<'practiceCases'>;

export function publishedPracticeCases(entries: PracticeCaseEntry[]) {
  return entries
    .filter((entry) => entry.data.publication.status === 'published')
    .sort((a, b) => b.data.publication.publishedAt.getTime() - a.data.publication.publishedAt.getTime());
}

export function filterPracticeCases(entries: PracticeCaseEntry[], filter: PracticeCaseFilter) {
  return entries.filter((entry) => {
    if (filter.lossType && entry.data.lossType !== filter.lossType) return false;
    if (filter.objectType && entry.data.objectType !== filter.objectType) return false;
    if (filter.lossRange && entry.data.lossRange !== filter.lossRange) return false;
    if (filter.tag && !entry.data.tags.includes(filter.tag)) return false;
    return true;
  });
}

export function practiceCaseTaxonomy(entries: PracticeCaseEntry[]) {
  const unique = (items: string[]) => [...new Set(items)].sort((a, b) => a.localeCompare(b, 'de'));
  return {
    lossTypes: unique(entries.map((entry) => entry.data.lossType)),
    objectTypes: unique(entries.map((entry) => entry.data.objectType)),
    tags: unique(entries.flatMap((entry) => entry.data.tags)),
  };
}
