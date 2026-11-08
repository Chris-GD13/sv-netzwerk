import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const source = fs.readFileSync(path.join(root, 'public/intern/claimsforce-startmask.js'), 'utf8');
const bridge = fs.readFileSync(path.join(root, 'browser-extension/claimsforce-bridge/claims-bridge.js'), 'utf8');
const worker = fs.readFileSync(path.join(root, 'browser-extension/claimsforce-bridge/service-worker.js'), 'utf8');

assert.match(source, /v==='erstbericht'&&isGf\?'erstbericht_sv_gf':v/, 'GF-Aufträge müssen den SV-GF-Erstbericht auswählen');
assert.match(source, /case_type:job\.order_type/, 'Der Auftragstyp muss an den Generator weitergereicht werden');
assert.match(source, /grossschaden|großschaden|taskforce/i, 'Die GF-Erkennung muss Großschaden/TaskForce abdecken');
assert.match(source, /SVNET_CLAIMS_REPORT_UPLOAD/, 'Der Abschluss muss den ClaimsForce-Berichtupload anstoßen');
assert.match(source, /phase:'ablage'/, 'Der Workflow muss vor dem Ergebnis die Ablagephase führen');
assert.match(bridge, /CLAIMS_UPLOAD_REPORT/, 'Die ClaimsForce-Brücke muss Reportdateien annehmen');
assert.match(worker, /AUTO_UPLOAD_CLAIMS_REPORTS/, 'Der Service Worker muss den automatischen ClaimsForce-Upload routen');

console.log('claimsforce_startmask_gf_routing_test: ok');
