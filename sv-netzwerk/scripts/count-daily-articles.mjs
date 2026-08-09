/**
 * Zählt veröffentlichte dailyStandard-Pflichtbeiträge für ein gegebenes Berliner Datum.
 * Ausgabe: Anzahl (ganzzahlig) auf stdout; mindestens 0.
 *
 * Verwendung: node scripts/count-daily-articles.mjs [YYYY-MM-DD]
 */
import { readdir, readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const knowledgeDir = path.join(root, 'src', 'content', 'knowledge');

const [dateArg] = process.argv.slice(2).filter((arg) => /^\d{4}-\d{2}-\d{2}$/.test(arg));
const berlinDate = dateArg ?? new Intl.DateTimeFormat('en-CA', {
  timeZone: 'Europe/Berlin',
  year: 'numeric',
  month: '2-digit',
  day: '2-digit',
}).format(new Date());

const files = (await readdir(knowledgeDir)).filter((file) => /\.mdx?$/.test(file));
let count = 0;

for (const file of files) {
  const source = await readFile(path.join(knowledgeDir, file), 'utf8');
  const frontmatterMatch = source.match(/^---\r?\n([\s\S]*?)\r?\n---/m);
  if (!frontmatterMatch) continue;
  const front = frontmatterMatch[1];
  const daily = /^dailyStandard:\s*true$/m.test(front);
  if (!daily) continue;
  const dateMatch = front.match(/^\s*publishedAt:\s*(\d{4}-\d{2}-\d{2})/m);
  if (dateMatch && dateMatch[1] === berlinDate) count += 1;
}

process.stdout.write(String(count));
