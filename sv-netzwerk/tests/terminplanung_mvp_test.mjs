import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '..');
const page = fs.readFileSync(path.join(root, 'src/pages/intern/terminplanung/index.astro'), 'utf8');
const api = fs.readFileSync(path.join(root, 'public/intern/api/google-drive-sync.php'), 'utf8');
const layout = fs.readFileSync(path.join(root, 'src/layouts/InternalLayout.astro'), 'utf8');

assert.match(page, /Terminplanung/);
assert.match(page, /schedule_cases/);
assert.match(page, /action=list/);
assert.match(page, /action=create/);
assert.match(page, /source_labels/);
assert.match(api, /case'schedule_cases':/);
assert.match(api, /claimsforce_claim_id/);
assert.match(api, /rekon_task_id/);
assert.match(layout, /\/intern\/terminplanung\//);
console.log('Terminierungs-MVP: gemeinsame Fallliste, Quellenkennzeichnung und Kalenderweg verdrahtet.');
