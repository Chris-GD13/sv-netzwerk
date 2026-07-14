import type { APIRoute } from 'astro';
import { buildSearchIndex } from '../lib/search/build';

export const prerender = true;

export const GET: APIRoute = async () => {
  const searchIndex = await buildSearchIndex();
  return new Response(
  JSON.stringify({
    version: '5.1.4',
    generatedAt: new Date().toISOString(),
    count: searchIndex.length,
    facets: {
      categories: [...new Set(searchIndex.map((item) => item.category))].sort((a, b) => a.localeCompare(b, 'de')),
      tags: [...new Set(searchIndex.flatMap((item) => item.tags))].sort((a, b) => a.localeCompare(b, 'de')),
      types: [...new Set(searchIndex.map((item) => item.type))],
    },
    items: searchIndex,
  }),
  {
    headers: {
      'Content-Type': 'application/json; charset=utf-8',
      'Cache-Control': 'public, max-age=3600, stale-while-revalidate=86400',
      'X-Content-Type-Options': 'nosniff',
    },
  },
  );
};
