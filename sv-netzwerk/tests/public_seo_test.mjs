import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const dist = path.join(root, 'dist');
const origin = 'https://www.sv-netzwerk.eu';
const sitemap = fs.readFileSync(path.join(dist, 'sitemap-0.xml'), 'utf8');
const urls = [...sitemap.matchAll(/<loc>([^<]+)<\/loc>/g)].map(([, url]) => url);
assert(urls.length > 100, 'Öffentliche Sitemap unerwartet klein');
const titles = new Map();
const descriptions = new Map();
let checkedLinks = 0;
for (const url of urls) {
  assert(url.startsWith(`${origin}/`) && !url.includes('/intern/'), `Unzulässige Sitemap-URL: ${url}`);
  const filename = path.join(dist, new URL(url).pathname, 'index.html');
  assert(fs.existsSync(filename), `Sitemap-Ziel fehlt: ${url}`);
  const html = fs.readFileSync(filename, 'utf8');
  assert.equal(html.match(/<link rel="canonical" href="([^"]+)"/)?.[1], url, `Canonical: ${url}`);
  assert(!/<meta name="robots"[^>]*noindex/.test(html), `noindex in öffentlicher Sitemap: ${url}`);
  assert.equal([...html.matchAll(/<h1\b/g)].length, 1, `H1-Anzahl: ${url}`);
  const title = html.match(/<title>([^<]+)<\/title>/)?.[1];
  const description = html.match(/<meta name="description" content="([^"]+)"/)?.[1];
  assert(title && description, `Suchmetadaten fehlen: ${url}`);
  assert(!titles.has(title), `Doppelter SEO-Titel: ${url} / ${titles.get(title)}`);
  assert(!descriptions.has(description), `Doppelte Beschreibung: ${url} / ${descriptions.get(description)}`);
  titles.set(title, url); descriptions.set(description, url);
  const visibleMarkup = html.replace(/<script\b[\s\S]*?<\/script>/gi, '');
  for (const [, href] of visibleMarkup.matchAll(/<a\b[^>]*href="([^"]+)"/g)) {
    const target = new URL(href.replace(/&amp;/g, '&'), url);
    if (target.origin !== origin || target.pathname.startsWith('/intern/') || target.pathname.endsWith('.php')) continue;
    const local = path.join(dist, decodeURIComponent(target.pathname));
    assert(fs.existsSync(local) || fs.existsSync(path.join(local, 'index.html')), `Defekter interner Link: ${url} → ${href}`);
    checkedLinks += 1;
  }
  if (url.includes('/fachwissen/') && html.includes('class="knowledge-meta"')) {
    assert(/href="\/experten\/christian-waechter\/" rel="author"/.test(html), `Autorenprofil fehlt: ${url}`);
    assert(html.includes('https://www.sv-netzwerk.eu/experten/christian-waechter/#person'), `Article-Autorenidentität fehlt: ${url}`);
  }
  for (const [, image] of html.matchAll(/<meta (?:property="og:image"|name="twitter:image") content="([^"]+)"/g)) {
    assert(image.startsWith('https://'), `Vorschaubild muss absolut sein: ${url}`);
  }
}
console.log(`Public SEO: ${urls.length} Sitemap-Seiten, eindeutige Suchmetadaten und ${checkedLinks} interne Links geprüft.`);
