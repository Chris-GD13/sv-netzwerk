import { access, readdir, readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const knowledgeDir = path.join(root, 'src', 'content', 'knowledge');
const libraryFile = path.join(root, 'src', 'data', 'library.ts');
const [berlinDateArg] = process.argv.slice(2).filter((arg) => /^\d{4}-\d{2}-\d{2}$/.test(arg));
const berlinDate = berlinDateArg ?? new Intl.DateTimeFormat('en-CA', {
  timeZone: 'Europe/Berlin',
  year: 'numeric',
  month: '2-digit',
  day: '2-digit',
}).format(new Date());

const normalize = (value) => value
  .toLocaleLowerCase('de-DE')
  .normalize('NFD')
  .replace(/[\u0300-\u036f]/g, '')
  .replace(/ß/g, 'ss')
  .replace(/[^a-z0-9]+/g, '-')
  .replace(/(^-|-$)/g, '');

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

const files = (await readdir(knowledgeDir)).filter((file) => /\.mdx?$/.test(file));
const entries = [];
const errors = [];
const titleMap = new Map();

for (const file of files) {
  const source = await readFile(path.join(knowledgeDir, file), 'utf8');
  const parsed = parse(source);
  if (!parsed) {
    errors.push(`${file}: Frontmatter fehlt`);
    continue;
  }
  const slug = file.replace(/\.mdx?$/, '');
  const publishedAt = parsed.value('publishedAt');
  const status = parsed.value('status') ?? '';
  const title = parsed.value('title') ?? '';
  const daily = /^dailyStandard:\s*true$/m.test(parsed.front);
  const titleKey = normalize(title);
  if (titleKey) {
    const list = titleMap.get(titleKey) ?? [];
    list.push(file);
    titleMap.set(titleKey, list);
  }
  entries.push({ file, slug, publishedAt, title, daily, status });
}

for (const [key, names] of titleMap) {
  if (key && names.length > 1) errors.push(`Themen-Dublette (Titel): ${names.join(', ')}`);
}

const publishedDaily = entries.filter((entry) => entry.daily && entry.status === 'published' && entry.publishedAt);
const todayDaily = publishedDaily.filter((entry) => entry.publishedAt === berlinDate);
if (todayDaily.length > 2) {
  errors.push(`Zu viele veröffentlichte Pflichtbeiträge am ${berlinDate}: ${todayDaily.map((item) => item.file).join(', ')}`);
}

if (publishedDaily.length > 0) {
  publishedDaily.sort((a, b) => {
    if (a.publishedAt === b.publishedAt) return a.file.localeCompare(b.file);
    return b.publishedAt.localeCompare(a.publishedAt);
  });
  const latest = publishedDaily[0];
  const expectedHref = `/fachwissen/${latest.slug}/`;
  const librarySource = await readFile(libraryFile, 'utf8');
  const firstDate = librarySource.match(/date:\s*'([^']+)'/)?.[1] ?? '';
  const firstHref = librarySource.match(/href:\s*'([^']+)'/)?.[1] ?? '';
  if (firstDate !== latest.publishedAt || firstHref !== expectedHref) {
    errors.push(`Fachwissensübersicht nicht auf aktuellstem Beitrag: erwartet ${latest.publishedAt} ${expectedHref}, gefunden ${firstDate} ${firstHref}.`);
  }

  const linkedinPath = path.join(root, 'src', 'content', 'linkedin', `${latest.publishedAt}_${latest.slug}.txt`);
  const videoPath = path.join(root, 'src', 'content', 'videos', `${latest.publishedAt}_wissen-in-180-sekunden_${latest.slug}.txt`);
  try {
    await access(linkedinPath);
  } catch {
    errors.push(`LinkedIn-Begleittext fehlt: src/content/linkedin/${latest.publishedAt}_${latest.slug}.txt`);
  }
  try {
    await access(videoPath);
  } catch {
    errors.push(`Wissen-in-180-Sekunden-Skript fehlt: src/content/videos/${latest.publishedAt}_wissen-in-180-sekunden_${latest.slug}.txt`);
  }
}

if (errors.length) {
  console.error('\nFachbeitrags-Preflight fehlgeschlagen:');
  errors.forEach((error) => console.error(`- ${error}`));
  process.exit(1);
}

console.log(`Fachbeitrags-Preflight für ${berlinDate} erfolgreich.`);
