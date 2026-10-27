import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const page = fs.readFileSync(path.join(root, 'src/pages/intern/zeiterfassung/index.astro'), 'utf8');
const api = fs.readFileSync(path.join(root, 'public/intern/api/time-tracking.php'), 'utf8');
const layout = fs.readFileSync(path.join(root, 'src/layouts/InternalLayout.astro'), 'utf8');

assert.match(layout, /\/intern\/zeiterfassung\//);
for (const label of ['Einbuchen', 'Ausbuchen', 'Überstunden \/ Saldo', 'Zeitausgleich', 'Auszahlung', 'Urlaubsübersicht', 'Resturlaub', 'Stundenlohn', 'Vereinbarte Wochenstunden']) assert.match(page, new RegExp(label.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')));
for (const action of ['clock_in', 'clock_out', 'leave_submit', 'leave_decide', 'save_leave_entitlement', 'save_entry', 'import_timemoto']) assert.match(api, new RegExp(`action === '${action}'`));
assert.match(api, /ttIdentity/);
assert.match(api, /can_approve_leave/);
assert.match(api, /timemoto_import/);
assert.match(api, /zeitausgleich/);
assert.match(api, /INSERT IGNORE/);
assert.match(api, /annual_leave_days/);
assert.match(api, /hourly_wage/);
assert.match(page, /Mitarbeiter anlegen, bearbeiten oder deaktivieren/);
assert.match(page, /Keine automatische Auszahlung/);
console.log('time_tracking_test: passed');
