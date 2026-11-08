import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const source = fs.readFileSync(path.join(root, 'public/intern/claimsforce-startmask.js'), 'utf8');

assert.match(source, /v==='erstbericht'&&isGf\?'erstbericht_sv_gf':v/, 'GF-Aufträge müssen den SV-GF-Erstbericht auswählen');
assert.match(source, /case_type:job\.order_type/, 'Der Auftragstyp muss an den Generator weitergereicht werden');
assert.match(source, /grossschaden|großschaden|taskforce/i, 'Die GF-Erkennung muss Großschaden/TaskForce abdecken');

console.log('claimsforce_startmask_gf_routing_test: ok');
