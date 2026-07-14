import type { CollectionEntry } from 'astro:content';

export type DownloadEntry = CollectionEntry<'downloads'>;

export function publishedDownloads(entries: DownloadEntry[]) {
  return entries
    .filter((entry) => entry.data.publication.status === 'published')
    .sort((a, b) => b.data.publication.publishedAt.getTime() - a.data.publication.publishedAt.getTime());
}

export function downloadCategories(entries: DownloadEntry[]) {
  return [...new Set(entries.map((entry) => entry.data.category))].sort((a, b) => a.localeCompare(b, 'de'));
}

export function downloadFormats(entries: DownloadEntry[]) {
  return [...new Set(entries.map((entry) => entry.data.format ?? entry.data.fileType))].sort((a, b) => a.localeCompare(b, 'de'));
}

export function downloadTags(entries: DownloadEntry[]) {
  return [...new Set(entries.flatMap((entry) => entry.data.tags))].sort((a, b) => a.localeCompare(b, 'de'));
}

export function downloadFileName(path: string) {
  return path.split('/').filter(Boolean).at(-1) ?? path;
}
