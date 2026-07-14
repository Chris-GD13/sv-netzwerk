import type { APIRoute } from 'astro'; import { library } from '../data/library';
export const GET: APIRoute=()=>new Response(JSON.stringify(library),{headers:{'Content-Type':'application/json; charset=utf-8','Cache-Control':'public, max-age=3600'}});
