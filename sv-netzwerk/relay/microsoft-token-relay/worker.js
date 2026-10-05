// Cloudflare Worker: leitet ausschließlich Microsoft-Token-Anfragen weiter.
// Erreichbar unter /<RELAY_KEY>/<tenant-guid>/oauth2/v2.0/token
const TENANT = /^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$/i;

const sameKey = (a, b) => {
  if (!a || !b || a.length !== b.length) return false;
  let diff = 0;
  for (let i = 0; i < a.length; i++) diff |= a.charCodeAt(i) ^ b.charCodeAt(i);
  return diff === 0;
};

export default {
  async fetch(request, env) {
    const notFound = () => new Response('Not found', { status: 404 });
    if (request.method !== 'POST' || !env.RELAY_KEY) return notFound();
    const [, key, tenant, ...rest] = new URL(request.url).pathname.split('/');
    if (!sameKey(key, env.RELAY_KEY) || !TENANT.test(tenant || '') || rest.join('/') !== 'oauth2/v2.0/token') return notFound();
    const upstream = await fetch(`https://login.microsoftonline.com/${tenant}/oauth2/v2.0/token`, {
      method: 'POST',
      headers: { 'Content-Type': request.headers.get('Content-Type') || 'application/x-www-form-urlencoded' },
      body: request.body,
    });
    return new Response(upstream.body, { status: upstream.status, headers: { 'Content-Type': upstream.headers.get('Content-Type') || 'application/json', 'Cache-Control': 'no-store' } });
  },
};
