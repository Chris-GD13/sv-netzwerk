import { readdir, readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const distDirectory = path.join(projectRoot, 'dist');

const suspiciousPatterns = [
  ['fuer', /\bfuer\b/iu],
  ['ueber', /\bueber/iu],
  ['pruef', /\bpruef/iu],
  ['oeffn', /\boeffn/iu],
  ['zurueck', /\bzurueck\b/iu],
  ['groess', /\bgroess/iu],
  ['schaed', /\bschaed/iu],
  ['waehr', /\bwaehr/iu],
  ['moegl', /\bmoegl/iu],
  ['loes', /\bloes/iu],
  ['ergaenz', /\bergaenz/iu],
  ['veraend', /\bveraend/iu],
  ['bestaet', /\bbestaet/iu],
  ['zustaend', /\bzustaend/iu],
  ['ueberpruef', /\bueberpruef/iu],
  ['qualitaet', /\bqualitaet/iu],
  ['sachverstaend', /\bsachverstaend/iu],
  ['verfueg', /\bverfueg/iu],
  ['gebaeud', /\bgebaeud/iu],
  ['komplexschaed', /\bkomplexschaed/iu],
  ['auftraeg', /\bauftraeg/iu],
  ['rueckfrag', /\brueckfrag/iu],
  ['bearbeitungsstaend', /\bbearbeitungsstaend/iu],
  ['vorpruef', /\bvorpruef/iu],
  ['gewuensch', /\bgewuensch/iu],
  ['ueberfuehr', /\bueberfuehr/iu],
];

async function collectHtml(directory) {
  const entries = await readdir(directory, { withFileTypes: true });
  const files = [];
  for (const entry of entries) {
    const absolute = path.join(directory, entry.name);
    if (entry.isDirectory()) files.push(...await collectHtml(absolute));
    if (entry.isFile() && entry.name.endsWith('.html')) files.push(absolute);
  }
  return files;
}

function decodeEntities(value) {
  return value
    .replaceAll('&nbsp;', ' ')
    .replaceAll('&amp;', '&')
    .replaceAll('&quot;', '"')
    .replaceAll('&#39;', "'")
    .replaceAll('&lt;', '<')
    .replaceAll('&gt;', '>')
    .replace(/&#(\d+);/gu, (_, codePoint) => String.fromCodePoint(Number(codePoint)));
}

function extractChunks(html) {
  const withoutScripts = html
    .replace(/<script\b[^>]*>[\s\S]*?<\/script>/giu, ' ')
    .replace(/<style\b[^>]*>[\s\S]*?<\/style>/giu, ' ')
    .replace(/<noscript\b[^>]*>[\s\S]*?<\/noscript>/giu, ' ')
    .replace(/<code\b[^>]*>[\s\S]*?<\/code>/giu, ' ')
    .replace(/<pre\b[^>]*>[\s\S]*?<\/pre>/giu, ' ')
    .replace(/<!--[\s\S]*?-->/gu, ' ');

  const chunks = [];
  for (const match of withoutScripts.matchAll(/<title>([^<]+)<\/title>/giu)) {
    chunks.push(match[1]);
  }
  for (const match of withoutScripts.matchAll(/<meta\b[^>]*?\b(?:name|property)="(?:description|og:title|og:description|twitter:title|twitter:description)"[^>]*?\bcontent="([^"]+)"/giu)) {
    chunks.push(match[1]);
  }
  for (const match of withoutScripts.matchAll(/<meta\b[^>]*?\b(?:name|property)='(?:description|og:title|og:description|twitter:title|twitter:description)'[^>]*?\bcontent='([^']+)'/giu)) {
    chunks.push(match[1]);
  }
  for (const match of withoutScripts.matchAll(/<(?:img|input|textarea|button)\b[^>]*?\b(?:alt|aria-label|placeholder|title|value)="([^"]+)"/giu)) {
    chunks.push(match[1]);
  }
  for (const match of withoutScripts.matchAll(/<(?:img|input|textarea|button)\b[^>]*?\b(?:alt|aria-label|placeholder|title|value)='([^']+)'/giu)) {
    chunks.push(match[1]);
  }
  for (const match of withoutScripts.matchAll(/>([^<]+)</gu)) {
    chunks.push(match[1]);
  }
  return chunks
    .map((chunk) => decodeEntities(chunk).replace(/\s+/gu, ' ').trim())
    .filter((chunk) => chunk && !/^(?:https?:\/\/|\/)[^\s]+$/iu.test(chunk));
}

const violations = [];
for (const file of await collectHtml(distDirectory)) {
  const html = await readFile(file, 'utf8');
  const relative = path.relative(distDirectory, file).replaceAll('\\', '/');
  const seen = new Set();
  for (const chunk of extractChunks(html)) {
    for (const [label, pattern] of suspiciousPatterns) {
      const hit = chunk.match(pattern);
      if (!hit) continue;
      const key = `${relative}\u0000${label}\u0000${chunk}`;
      if (seen.has(key)) break;
      seen.add(key);
      violations.push(`${relative}: verdächtige Ersatzschreibweise "${hit[0]}" (${label}) in "${chunk}"`);
      break;
    }
  }
}

if (violations.length) {
  console.error('\nPrüfung auf sichtbare Ersatzschreibweisen fehlgeschlagen:');
  for (const violation of violations) console.error(`- ${violation}`);
  process.exit(1);
}

console.log('Sichtbare Ersatzschreibweisen: keine auffälligen Umlaut-Ersatzschreibungen gefunden.');
