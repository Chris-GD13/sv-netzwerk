import { access, readFile, readdir } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const scheduledRoot = path.join(root, 'drafts', 'fachbeitraege', 'scheduled');
const requiredDays = ['2026-08-09', '2026-08-10'];
const errors = [];

for (const day of requiredDays) {
  const dayDir = path.join(scheduledRoot, day);
  try {
    await access(dayDir);
  } catch {
    errors.push(`${day}: Planungsordner fehlt`);
    continue;
  }
  const files = await readdir(dayDir);
  const manifestName = 'manifest.json';
  if (!files.includes(manifestName)) {
    errors.push(`${day}: manifest.json fehlt`);
    continue;
  }
  const manifest = JSON.parse(await readFile(path.join(dayDir, manifestName), 'utf8'));
  if (manifest.publicationDate !== day) errors.push(`${day}: publicationDate weicht ab`);
  if (!manifest.slug || !manifest.title || !manifest.category) errors.push(`${day}: Pflichtfelder im Manifest fehlen`);
  const contentPath = path.join(dayDir, manifest.contentFile);
  const linkedinPath = path.join(dayDir, manifest.linkedinFile);
  try { await access(contentPath); } catch { errors.push(`${day}: Content-Datei fehlt`); }
  try { await access(linkedinPath); } catch { errors.push(`${day}: LinkedIn-Datei fehlt`); }
}

if (errors.length) {
  console.error(errors.join('\n'));
  process.exit(1);
}

console.log(`Scheduled content valid for ${requiredDays.join(', ')}`);
