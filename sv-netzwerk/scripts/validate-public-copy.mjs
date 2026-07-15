import { readdir, readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const projectRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const distDirectory = path.join(projectRoot, 'dist');

const forbidden = [
  ['redaktioneller Wiederherstellungshinweis', /wieder vollständig/iu],
  ['Zukunftsformulierung', /\bkünftig(?:e|er|en|em|es)?\b/iu],
  ['spätere Inhaltsverknüpfung', /spätere(?:n|r|s|m)?\s+(?:Fall-|Wissens-|Experten-)/iu],
  ['stufenweiser Projektstatus', /\bschrittweise\b/iu],
  ['Entwicklungsroadmap', /Entwicklungsroadmap/iu],
  ['technischer Statushinweis', /Technischer Status/iu],
  ['API-Ausblick', /API-Anbindung/iu],
  ['Versionshinweis im Seitentext', /In Version\s+\d/iu],
  ['unfertiger Dateiupload', /noch keine Dateien/iu],
  ['Karten-Ausblick', /Kartenintegration/iu],
  ['interne Verknüpfung', /interne(?:n|r|s|m)?\s+Verknüpfungen/iu],
  ['Produktivhinweis', /produktiv erreichbar/iu],
  ['interne Komponentenbibliothek', /Komponentenbibliothek/iu],
  ['interner Entwicklungshinweis', /(?:Weiterentwicklung orientiert sich|nächste Entwicklungsschritt)/iu],
  ['Robots-Ausblick', /später über Robots/iu],
  ['Platzhalterhinweis', /\bPlatzhalter\b/iu],
  ['offene Arbeitsmarkierung', /\b(?:TODO|FIXME|HACK|XXX)\b/u],
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

const violations = [];
for (const file of await collectHtml(distDirectory)) {
  const html = await readFile(file, 'utf8');
  const visibleMarkup = html
    .replace(/<script\b[^>]*>[\s\S]*?<\/script>/giu, ' ')
    .replace(/<style\b[^>]*>[\s\S]*?<\/style>/giu, ' ');
  const relative = path.relative(distDirectory, file).replaceAll('\\', '/');

  if (/<!--[\s\S]*?-->/u.test(visibleMarkup)) {
    violations.push(`${relative}: veröffentlichter HTML-Kommentar`);
  }
  for (const [label, pattern] of forbidden) {
    if (pattern.test(visibleMarkup)) violations.push(`${relative}: ${label}`);
  }
}

if (violations.length) {
  console.error('\nPrüfung der veröffentlichten Seitentexte fehlgeschlagen:');
  for (const violation of violations) console.error(`- ${violation}`);
  process.exit(1);
}

console.log('Veröffentlichte Seitentexte: keine internen Hinweise oder Arbeitsmarkierungen gefunden.');
