import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const site = 'https://www.sv-netzwerk.eu';
const distDir = path.join(root, 'dist');

const librarySource = fs.readFileSync(path.join(root, 'src/data/library.ts'), 'utf8');
const entryPattern = /title: '((?:[^'\\]|\\.)*)',[\s\S]*?href: '([^']*)'/g;
const libraryEntries = [...librarySource.matchAll(entryPattern)].map(([, title, href]) => ({ title, href }));

assert(libraryEntries.length >= 60, `Bibliothek unerwartet klein (${libraryEntries.length} Einträge)`);

// 1. Jeder Fachbeitrag besitzt eine eindeutige URL.
const byHref = new Map();
for (const entry of libraryEntries) {
  byHref.set(entry.href, [...(byHref.get(entry.href) ?? []), entry.title]);
}
const duplicates = [...byHref.entries()].filter(([, titles]) => titles.length > 1);
assert.deepEqual(
  duplicates,
  [],
  `Doppelt belegte Fachartikel-URLs: ${duplicates.map(([href, titles]) => `${href} -> ${titles.join(' | ')}`).join('; ')}`
);
assert.equal(byHref.size, libraryEntries.length, 'Anzahl eindeutiger URLs muss der Anzahl der Einträge entsprechen');

// 2. Titel bleiben eindeutig, damit Listing und Suchindex nicht kollidieren.
const titles = libraryEntries.map((entry) => entry.title);
assert.equal(new Set(titles).size, titles.length, 'Fachartikel-Titel müssen eindeutig sein');

// 3. Jede Fachartikel-URL besitzt eine Quelle (Content-Datei oder eigene Seite).
const knowledgeSlugs = new Set(
  fs.readdirSync(path.join(root, 'src/content/knowledge'))
    .filter((file) => /\.mdx?$/.test(file))
    .map((file) => file.replace(/\.mdx?$/, ''))
);
const pageSlugs = new Set(
  fs.readdirSync(path.join(root, 'src/pages/fachwissen'), { withFileTypes: true })
    .filter((item) => item.isDirectory() && fs.existsSync(path.join(root, 'src/pages/fachwissen', item.name, 'index.astro')))
    .map((item) => item.name)
);
// Bekannte Altlast ohne Quelldatei (Klasse C laut docs/MASTERBEFEHL_FACHBEITRAG_MIGRATION_VOR_2026-08-01.md).
// Der Eintrag bleibt erhalten; die Liste darf nicht wachsen.
const knownMissingSources = ['unwetter-ludwigsburg-starkregen-hagel-sturm-schadensteuerung'];
const allArticleHrefs = libraryEntries.map((entry) => entry.href).filter((href) => href.startsWith('/fachwissen/'));
const slugOf = (href) => href.replace('/fachwissen/', '').replace(/\/$/, '');
const missingSources = allArticleHrefs
  .map(slugOf)
  .filter((slug) => !knowledgeSlugs.has(slug) && !pageSlugs.has(slug));
assert.deepEqual(
  [...missingSources].sort(),
  [...knownMissingSources].sort(),
  `Fachartikel-URLs ohne Quelle: ${missingSources.join(', ')}`
);
const articleHrefs = allArticleHrefs.filter((href) => !knownMissingSources.includes(slugOf(href)));

// 4. Die zuvor doppelt belegten Themen besitzen jeweils ihre eigene, bereits indexierte URL.
const expectedHrefs = {
  'Brandschaden nach Erstmaßnahmen: Übergang zur Wiederherstellung sauber steuern': '/fachwissen/brandschaden-notmassnahmen-uebergang-zur-wiederherstellung/',
  'Lichtbogen an einer LED-Lichtleiste': '/fachwissen/lichtbogen-led-lichtleiste-brandschaden/',
  'Wasserschaden: Rückbau technisch abgrenzen': '/fachwissen/wasserschaden-rueckbau-technische-abgrenzung/',
  'Fenster, Türen und Fassaden technisch beurteilen': '/fachwissen/fenster-tueren-fassaden/',
};
for (const [title, href] of Object.entries(expectedHrefs)) {
  const entry = libraryEntries.find((item) => item.title === title);
  assert(entry, `Fachbeitrag fehlt im Listing: ${title}`);
  assert.equal(entry.href, href, `Falsche URL für "${title}"`);
}

// 5. Pagination bleibt indexierbar.
const paginationSource = fs.readFileSync(path.join(root, 'src/pages/fachwissen/seite/[page].astro'), 'utf8');
assert(!/\bnoindex\b/.test(paginationSource), 'Fachartikel-Pagination darf nicht auf noindex stehen');
assert(/<h1>/.test(paginationSource), 'Paginierte Seiten benötigen eine H1');

// 6. robots.txt erlaubt die Indexierung und verweist auf die Sitemap.
const robots = fs.readFileSync(path.join(root, 'public/robots.txt'), 'utf8');
assert(/^Sitemap:\s*https:\/\/www\.sv-netzwerk\.eu\/sitemap-index\.xml$/m.test(robots), 'robots.txt benötigt einen Sitemap-Verweis');
assert(/^Disallow:\s*\/intern\/$/m.test(robots), 'robots.txt muss /intern/ ausschließen');

if (!fs.existsSync(distDir)) {
  console.log('Quellprüfungen bestanden. Build-Prüfungen übersprungen (dist fehlt).');
  process.exit(0);
}

// 7. Build: Jede Fachartikel-URL existiert und trägt ein self-referencing Canonical.
const readHtml = (href) => fs.readFileSync(path.join(distDir, href.replace(/^\//, ''), 'index.html'), 'utf8');
for (const href of articleHrefs) {
  const target = path.join(distDir, href.replace(/^\//, ''), 'index.html');
  assert(fs.existsSync(target), `Fachartikel fehlt im Build: ${href}`);
  const html = readHtml(href);
  const canonical = html.match(/<link rel="canonical" href="([^"]+)"/)?.[1];
  assert.equal(canonical, `${site}${href}`, `Canonical von ${href} zeigt nicht auf die eigene URL`);
  assert(!/<meta name="robots"[^>]*noindex/.test(html), `${href} darf nicht auf noindex stehen`);
  assert(/<meta property="og:type" content="article"/.test(html), `${href} benötigt og:type article`);
  assert(/"@type":"Article"/.test(html), `${href} benötigt Article-JSON-LD`);
  assert(/"@type":"BreadcrumbList"/.test(html), `${href} benötigt Breadcrumb-JSON-LD`);
  assert(/"datePublished":"\d{4}-\d{2}-\d{2}/.test(html), `${href} benötigt ein gültiges datePublished`);
}

// 8. Sitemap: vollständig, ohne /intern/ und ohne Dubletten.
const sitemapFiles = fs.readdirSync(distDir).filter((file) => /^sitemap.*\.xml$/.test(file));
assert(sitemapFiles.includes('sitemap-index.xml'), 'sitemap-index.xml fehlt im Build');
const sitemap = sitemapFiles.map((file) => fs.readFileSync(path.join(distDir, file), 'utf8')).join('\n');
const locations = [...sitemap.matchAll(/<loc>([^<]+)<\/loc>/g)].map(([, loc]) => loc);
assert.equal(new Set(locations).size, locations.length, 'Sitemap enthält doppelte URLs');
assert(!locations.some((loc) => loc.includes('/intern/')), 'Sitemap darf keine internen URLs enthalten');
for (const href of articleHrefs) {
  assert(locations.includes(`${site}${href}`), `Sitemap-Eintrag fehlt: ${href}`);
}
assert(locations.includes(`${site}/fachwissen/`), 'Sitemap-Eintrag für /fachwissen/ fehlt');
assert(locations.includes(`${site}/fachwissen/seite/2/`), 'Paginierte Fachartikel-Seiten gehören in die Sitemap');

console.log(`Fachwissen-SEO geprüft: ${articleHrefs.length} eindeutige Fachartikel-URLs, Canonicals und Sitemap in Ordnung.`);
