import type { APIRoute } from 'astro';
import { searchDocuments } from '../data/search';

export const prerender = true;

export const GET: APIRoute = () => new Response(
  JSON.stringify({
    version: '1.5.2',
    generatedAt: new Date().toISOString(),
    count: searchDocuments.length,
    items: searchDocuments,
  }),
  {
    headers: {
      'Content-Type': 'application/json; charset=utf-8',
      'Cache-Control': 'public, max-age=3600, stale-while-revalidate=86400',
    },
  },
);
