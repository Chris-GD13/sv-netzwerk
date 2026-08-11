import { readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const runtimeFile = path.join(root, '.automation', 'latest-publication.json');
const publicationLogFile = path.join(root, 'docs', 'fachbeitrag-veroeffentlichungsprotokoll.csv');

const args = process.argv.slice(2);
const argValue = (name) => {
  const token = args.find((item) => item.startsWith(`--${name}=`));
  return token ? token.slice(name.length + 3) : '';
};

const commit = argValue('commit');
const deployStatus = argValue('deploy');
const liveStatus = argValue('live');
const linkedinStatus = argValue('linkedin');

const source = await readFile(publicationLogFile, 'utf8');
const lines = source.trim().split(/\r?\n/);
if (lines.length <= 1) {
  throw new Error('Veröffentlichungsprotokoll enthält keine Datenzeilen.');
}

const headers = lines[0].split(',').map((item) => item.replace(/^"|"$/g, ''));
const rows = lines.slice(1).map((line) => {
  const cols = [];
  let cur = '';
  let quoted = false;
  for (let i = 0; i < line.length; i += 1) {
    const c = line[i];
    if (c === '"' && line[i + 1] === '"') {
      cur += '"';
      i += 1;
      continue;
    }
    if (c === '"') {
      quoted = !quoted;
      continue;
    }
    if (c === ',' && !quoted) {
      cols.push(cur);
      cur = '';
      continue;
    }
    cur += c;
  }
  cols.push(cur);
  const entry = {};
  headers.forEach((header, idx) => {
    entry[header] = cols[idx] ?? '';
  });
  return entry;
});

let publicationId = '';
try {
  const runtime = JSON.parse(await readFile(runtimeFile, 'utf8'));
  publicationId = String(runtime.publicationId || '').trim();
} catch {
  publicationId = '';
}

if (!publicationId) {
  publicationId = [...rows].reverse().find((row) => {
    const deploy = String(row.deploy_status || '').trim();
    const live = String(row.live_pruefung || '').trim();
    const linkedin = String(row.linkedin_status || '').trim();
    return deploy === 'pending' || live === 'pending' || linkedin === 'pending';
  })?.publication_id || '';
}

if (!publicationId) {
  throw new Error('publicationId konnte weder aus latest-publication.json noch aus dem Veröffentlichungsprotokoll ermittelt werden.');
}

const target = rows.find((row) => row.publication_id === publicationId);
if (!target) {
  throw new Error(`publication_id ${publicationId} nicht im Protokoll gefunden.`);
}

if (commit) target.commit = commit;
if (deployStatus) target.deploy_status = deployStatus;
if (liveStatus) target.live_pruefung = liveStatus;
if (linkedinStatus) target.linkedin_status = linkedinStatus;

const csvEscape = (value) => `"${String(value ?? '').replaceAll('"', '""')}"`;
const output = [
  headers.map(csvEscape).join(','),
  ...rows.map((row) => headers.map((header) => csvEscape(row[header] ?? '')).join(',')),
].join('\n');

await writeFile(publicationLogFile, `${output}\n`);
console.log(`Protokoll aktualisiert: ${publicationId}`);
