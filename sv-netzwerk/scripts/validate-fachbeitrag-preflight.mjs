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
const explicitBackfillRun = process.env.GITHUB_EVENT_NAME === 'workflow_dispatch' && process.env.PUBLICATION_RUNTIME_STATUS === 'generated';

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
const warnings = [];
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
    list.push({ file, publishedAt, status, daily });
    titleMap.set(titleKey, list);
  }
  entries.push({ file, slug, publishedAt, title, daily, status, front: parsed.front, body: parsed.body });
}

for (const [key, items] of titleMap) {
  if (!key || items.length <= 1) continue;
  const names = items.map((item) => item.file);
  const affectsToday = items.some((item) => item.daily && item.status === 'published' && item.publishedAt === berlinDate);
  if (affectsToday) {
    errors.push(`Themen-Dublette des heutigen Beitrags (Titel): ${names.join(', ')}`);
  } else {
    warnings.push(`Historische Themen-Dublette (blockiert Tageslauf nicht): ${names.join(', ')}`);
  }
}

const publishedDaily = entries.filter((entry) => entry.daily && entry.status === 'published' && entry.publishedAt);
const todayDaily = publishedDaily.filter((entry) => entry.publishedAt === berlinDate);
if (todayDaily.length > 2) {
  errors.push(`Zu viele veröffentlichte Pflichtbeiträge am ${berlinDate}: ${todayDaily.map((item) => item.file).join(', ')}`);
}

// Redaktionelle Mindestqualität: Ein technisch valider Build darf keinen inhaltlich dünnen
// oder als Dubletten-Kopie erkennbaren Tagesbeitrag veröffentlichen.
for (const entry of todayDaily) {
  if (/\(\d+\)\s*$/.test(entry.title)) {
    errors.push(`${entry.file}: sichtbarer Titel endet mit technischem Dubletten-Suffix (${entry.title}).`);
  }
  if (/-\d+$/.test(entry.slug) && /\(\d+\)\s*$/.test(entry.title)) {
    errors.push(`${entry.file}: Slug und Titel zeigen eine automatische Dublettenauflösung statt eines eigenständigen Themas.`);
  }

  const plainBody = entry.body
    .replace(/```[\s\S]*?```/g, ' ')
    .replace(/\[[^\]]+\]\([^\)]+\)/g, ' ')
    .replace(/[#>*_|`~-]/g, ' ');
  const wordCount = plainBody.split(/\s+/).filter(Boolean).length;
  if (wordCount < 1200) {
    errors.push(`${entry.file}: Fachbeitrag zu kurz (${wordCount} Wörter; Mindestumfang 1200 Wörter).`);
  }

  const headingCount = (entry.body.match(/^#{2,3}\s+.+$/gm) || []).length;
  if (headingCount < 10) {
    errors.push(`${entry.file}: zu geringe fachliche Gliederung (${headingCount} Überschriften; mindestens 10 erforderlich).`);
  }

  const sourceHeading = /^(?:##|###)\s+(?:Quellen|Quellen und|Quellenverzeichnis|Quellen und weiterführende)/mi.test(entry.body);
  if (!sourceHeading) {
    errors.push(`${entry.file}: Quellenabschnitt fehlt.`);
  }
  const sourceUrls = [...entry.body.matchAll(/https:\/\/[\w.-]+[^\s)\]]*/g)].map((match) => match[0]);
  const distinctHosts = new Set(sourceUrls.map((url) => {
    try { return new URL(url).hostname.replace(/^www\./, ''); } catch { return ''; }
  }).filter(Boolean));
  if (sourceUrls.length < 4 || distinctHosts.size < 3) {
    errors.push(`${entry.file}: Quellenbasis zu schmal (${sourceUrls.length} URLs / ${distinctHosts.size} unterschiedliche Hosts; mindestens 4 belastbare Links aus 3 Quellenbereichen erforderlich).`);
  }

  const requiredConcepts = [
    /fachliche\s+einordnung/i,
    /(schadenbild|befund)/i,
    /(abgrenzung|alternative ursache|vorschaden)/i,
    /(prüffrag|prueffrag)/i,
    /(sofortmaßnahme|sofortmassnahme|gefahrenabwehr)/i,
    /(beweissicherung|dokumentation)/i,
    /(fachgrenze|spezialist|fachplaner)/i,
    /(versicherungstechn|deckungszusage|einzelfallprüfung|einzelfallpruefung)/i,
    /(kostenprüfung|kostenpruefung|prüffähig|prueffaehig)/i,
    /(fiktiv.*praxisbeispiel|praxisbeispiel.*fiktiv)/i,
    /fazit/i,
  ];
  const conceptHits = requiredConcepts.filter((rx) => rx.test(entry.body)).length;
  if (conceptHits < 10) {
    errors.push(`${entry.file}: Fachbeitragsstandard nicht vollständig abgebildet (${conceptHits}/11 Pflichtkonzepte erkannt).`);
  }

  if (/^\s*(?:image|imageAlt):.*\.svg/i.test(entry.front) || /assets\/images\/linkedin\/.+\.svg/i.test(entry.front)) {
    errors.push(`${entry.file}: typografisches/automatisch erzeugtes SVG-Beitragsbild ist nach Fachbeitragsstandard unzulässig.`);
  }
}

if (publishedDaily.length > 0) {
  const publishedAll = entries.filter((entry) => (
    entry.status === 'published'
    && entry.publishedAt
    && entry.publishedAt <= berlinDate
  ));
  publishedAll.sort((a, b) => {
    if (a.publishedAt === b.publishedAt) return a.file.localeCompare(b.file);
    return b.publishedAt.localeCompare(a.publishedAt);
  });
  const latestOverall = publishedAll[0];

  const expectedHref = `/fachwissen/${latestOverall.slug}/`;
  const librarySource = await readFile(libraryFile, 'utf8');
  const firstDate = librarySource.match(/date:\s*'([^']+)'/)?.[1] ?? '';
  const firstHref = librarySource.match(/href:\s*'([^']+)'/)?.[1] ?? '';
  if (!explicitBackfillRun && (firstDate !== latestOverall.publishedAt || firstHref !== expectedHref)) {
    errors.push(`Fachwissensübersicht nicht auf aktuellstem Beitrag: erwartet ${latestOverall.publishedAt} ${expectedHref}, gefunden ${firstDate} ${firstHref}.`);
  }
}

for (const entry of todayDaily) {
  const linkedinPath = path.join(root, 'src', 'content', 'linkedin', `${entry.publishedAt}_${entry.slug}.txt`);
  try {
    await access(linkedinPath);
  } catch {
    errors.push(`LinkedIn-Begleittext fehlt: src/content/linkedin/${entry.publishedAt}_${entry.slug}.txt`);
  }
}

if (warnings.length) {
  console.warn('\nFachbeitrags-Preflight Hinweise:');
  warnings.forEach((warning) => console.warn(`- ${warning}`));
}

if (errors.length) {
  console.error('\nFachbeitrags-Preflight fehlgeschlagen:');
  errors.forEach((error) => console.error(`- ${error}`));
  process.exit(1);
}

console.log(`Fachbeitrags-Preflight für ${berlinDate} erfolgreich.`);
