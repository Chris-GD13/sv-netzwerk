export type LibraryType = 'article' | 'video';

export type LibraryItem = {
  title: string;
  description: string;
  href: string;
  category: string;
  tags: string[];
  date: string;
  type: LibraryType;
  featured?: boolean;
};

export const library: LibraryItem[] = [];

export const categories = [...new Set(library.map((item) => item.category))]
  .sort((a, b) => a.localeCompare(b, 'de'));

export const tags = [...new Set(library.flatMap((item) => item.tags))]
  .sort((a, b) => a.localeCompare(b, 'de'));

export const PAGE_SIZE = 6;

export const slugify = (value: string) => value
  .toLocaleLowerCase('de-DE')
  .normalize('NFD')
  .replace(/[\u0300-\u036f]/g, '')
  .replace(/ß/g, 'ss')
  .replace(/[^a-z0-9]+/g, '-')
  .replace(/(^-|-$)/g, '');

export const formatDate = (value: string) => new Intl.DateTimeFormat('de-DE', {
  day: '2-digit',
  month: '2-digit',
  year: 'numeric',
}).format(new Date(`${value}T12:00:00`));

export const sortedLibrary = [...library].sort((a, b) => b.date.localeCompare(a.date));
