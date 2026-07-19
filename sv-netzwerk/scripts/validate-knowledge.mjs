import { readdir, readFile, access } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const knowledgeDir = path.join(root, 'src', 'content', 'knowledge');
const useDist = process.argv.includes('--dist');
const dateArg = process.argv.find((arg) => /^\d{4}-\d{2}-\d{2}$/.test(arg));
const expectedCountArg = process.argv.find((arg) => /^--expected-daily-count=\d+$/.test(arg));
const expectedDailyCount = expectedCountArg ? Number(expectedCountArg.split('=')[1]) : null;
const maxDailyPerDate = Number(process.env.MAX_DAILY_STANDARD_PER_DAY ?? '2');
const berlinDate = dateArg ?? new Intl.DateTimeFormat('en-CA', {
  timeZone: 'Europe/Berlin',
  year: 'numeric',
  month: '2-digit',
  day: '2-digit',
}).format(new Date());

const limits = { B: [700, 1700] };

const parse = (source) => {
  const match = source.match(/^---\r?\n([\s\S]*?)\r?\n---\r?\n([\s\S]*)$/);
  if (!match) return null;
  const front = match[1];
  const value = (key) => {
    const item = front.match(new RegExp(`^\\s*${key}:\\s*["']?([^"'\\r\\n]+)["']?\\s*$`, 'm'));
    return item?.[1]?.trim();
  };
  return { front, body: match[2], value };
};

const countWords = (body) => body
  .replace(/```[\s\S]*?```/g, ' ')
  .replace(/<[^>]+>/g, ' ')
  .replace(/\[[^\]]+\]\([^)]+\)/g, ' ')
  .replace(/[#*_>`|~-]/g, ' ')
  .match(/[\p{L}\p{N}]+(?:[-’'][\p{L}\p{N}]+)*/gu)?.length ?? 0;

const files = (await readdir(knowledgeDir)).filter((file) => /\.mdx?$/.test(file));
const entries = [];
const errors = [];
const slugs = new Set();

for (const file of files) {
  const source = await readFile(path.join(knowledgeDir, file), 'utf8');
  const parsed = parse(source);
  if (!parsed) {
    errors.push(`${file}: Frontmatter fehlt`);
    continue;
  }
  const slug = file.replace(/\.mdx?$/, '');
  if (slugs.has(slug)) errors.push(`${file}: Slug ist nicht eindeutig`);
  slugs.add(slug);
  const date = parsed.value('publishedAt');
  const daily = /^dailyStandard:\s*true$/m.test(parsed.front);
  entries.push({ file, slug, date, daily, level: parsed.value('contentLevel'), words: countWords(parsed.body), parsed });
}

const today = entries.filter((entry) => entry.date === berlinDate && entry.daily);
if (expectedDailyCount !== null && today.length !== expectedDailyCount) {
  errors.push(`${berlinDate}: erwartet ${expectedDailyCount} Pflichtbeiträge, gefunden: ${today.length}`);
}

for (const entry of today) {
  if (entry.level !== 'B') errors.push(`${entry.file}: erwartet B-Beitrag, gefunden ${entry.level ?? 'keine Kategorie'}`);
  const [min, max] = limits.B;
  if (entry.words < min || entry.words > max) errors.push(`${entry.file}: ${entry.words} Wörter, erwartet ${min}–${max}`);
  for (const key of ['title', 'description', 'category', 'author', 'teaser', 'linkedinSummary', 'videoScript']) {
    if (!entry.parsed.value(key)) errors.push(`${entry.file}: Pflichtfeld ${key} fehlt`);
  }
  if (!/^tags:\s*\[/m.test(entry.parsed.front)) errors.push(`${entry.file}: Tags fehlen`);
  if (!/^\s+title:/m.test(entry.parsed.front) || !/^\s+description:/m.test(entry.parsed.front)) {
    errors.push(`${entry.file}: SEO-Titel oder Meta-Description fehlt`);
  }
  if (!/^cta:\s*$/m.test(entry.parsed.front)) errors.push(`${entry.file}: CTA fehlt`);
  if (!/^relatedLinks:\s*\[/m.test(entry.parsed.front)) errors.push(`${entry.file}: interne Verlinkungen fehlen`);
}

const duplicateDates = entries
  .filter((entry) => entry.daily)
  .reduce((map, entry) => map.set(entry.date, [...(map.get(entry.date) ?? []), entry.file]), new Map());
for (const [date, names] of duplicateDates) {
  if (date && names.length > maxDailyPerDate) errors.push(`${date}: zu viele Pflichtbeiträge (${names.length}): ${names.join(', ')}`);
}

if (useDist) {
  const search = await readFile(path.join(root, 'dist', 'search-index.json'), 'utf8');
  const sitemapFiles = (await readdir(path.join(root, 'dist'))).filter((file) => /^sitemap.*\.xml$/.test(file));
  let sitemap = '';
  for (const file of sitemapFiles) sitemap += await readFile(path.join(root, 'dist', file), 'utf8');

  for (const entry of entries.filter((item) => item.daily)) {
    const route = `/fachwissen/${entry.slug}/`;
    try {
      await access(path.join(root, 'dist', 'fachwissen', entry.slug, 'index.html'));
    } catch {
      errors.push(`${entry.file}: Route fehlt im Build`);
    }
    if (!search.includes(route)) errors.push(`${entry.file}: nicht im Suchindex`);
    if (!sitemap.includes(route)) errors.push(`${entry.file}: nicht in der Sitemap`);
    const links = [...entry.parsed.body.matchAll(/\]\((\/[^)#?]+\/?)(?:[?#][^)]*)?\)/g)].map((match) => match[1]);
    for (const href of links) {
      const target = href === '/'
        ? path.join(root, 'dist', 'index.html')
        : path.join(root, 'dist', href.replace(/^\//, ''), 'index.html');
      try {
        await access(target);
      } catch {
        errors.push(`${entry.file}: interner Link ohne Build-Ziel ${href}`);
      }
    }
  }
}

for (const entry of entries.filter((item) => item.daily).sort((a, b) => a.date.localeCompare(b.date))) {
  console.log(`${entry.date} | ${entry.level} | ${entry.words} Wörter | ${entry.slug}`);
}

if (errors.length) {
  console.error('\nFachwissensvalidierung fehlgeschlagen:');
  errors.forEach((error) => console.error(`- ${error}`));
  process.exit(1);
}

console.log(`\nFachwissensstandard für ${berlinDate} erfüllt${useDist ? ' (inklusive Build, Suche, Sitemap und Links)' : ''}.`);
