import type { APIRoute } from 'astro';
import { searchIndex } from '../lib/search';

export const prerender = true;

export const GET: APIRoute = () => new Response(
  JSON.stringify({
    version: '1.6.2',
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
