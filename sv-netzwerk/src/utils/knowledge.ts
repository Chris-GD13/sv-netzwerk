import { getCollection, type CollectionEntry } from 'astro:content';
import { buildTaxonomy, slugify } from './content';

export type KnowledgeEntry = CollectionEntry<'knowledge'>;

export const knowledgePath = (entry: KnowledgeEntry) => `/svos/fachwissen/${entry.id}/`;

export const getPublishedKnowledge = async (): Promise<KnowledgeEntry[]> => {
  const entries = await getCollection('knowledge', ({ data }) => data.publication.status === 'published');
  return entries.sort((a, b) => b.data.publication.publishedAt.getTime() - a.data.publication.publishedAt.getTime());
};

export const getKnowledgeCategories = (entries: KnowledgeEntry[]) =>
  buildTaxonomy(entries.map((entry) => entry.data.category));

export const getKnowledgeTags = (entries: KnowledgeEntry[]) =>
  buildTaxonomy(entries.flatMap((entry) => entry.data.tags));

export const filterByCategory = (entries: KnowledgeEntry[], categorySlug: string) =>
  entries.filter((entry) => slugify(entry.data.category) === categorySlug);

export const filterByTag = (entries: KnowledgeEntry[], tagSlug: string) =>
  entries.filter((entry) => entry.data.tags.some((tag) => slugify(tag) === tagSlug));

export const estimateReadingMinutes = (body: string, configured?: number) => {
  if (configured) return configured;
  const words = body.trim().split(/\s+/).filter(Boolean).length;
  return Math.max(1, Math.ceil(words / 210));
};

export const findRelatedKnowledge = (
  current: KnowledgeEntry,
  entries: KnowledgeEntry[],
  limit = 3,
) => entries
  .filter((entry) => entry.id !== current.id)
  .map((entry) => {
    const sharedTags = entry.data.tags.filter((tag) => current.data.tags.includes(tag)).length;
    const sameCategory = entry.data.category === current.data.category ? 3 : 0;
    return { entry, score: sharedTags + sameCategory };
  })
  .filter(({ score }) => score > 0)
  .sort((a, b) => b.score - a.score || b.entry.data.publication.publishedAt.getTime() - a.entry.data.publication.publishedAt.getTime())
  .slice(0, limit)
  .map(({ entry }) => entry);

export const formatKnowledgeDate = (date: Date) => new Intl.DateTimeFormat('de-DE', {
  day: '2-digit',
  month: 'long',
  year: 'numeric',
}).format(date);
