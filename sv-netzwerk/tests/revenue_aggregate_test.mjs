import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '..');
const api = fs.readFileSync(path.join(root, 'public/intern/api/revenue-summary.php'), 'utf8');
const page = fs.readFileSync(path.join(root, 'src/pages/intern/abrechnungen/index.astro'), 'utf8');

for (const profile of ['rekon_christian', 'annual_claims', 'annual_rekon']) {
  assert.match(api, new RegExp(`['\"]${profile}['\"]`), `Backend-Profil fehlt: ${profile}`);
  assert.match(page, new RegExp(`profile-${profile}`), `Oberflächenprofil fehlt: ${profile}`);
}
for (const person of ['Christian Wächter', 'Marc Schütt', 'Holger Roth']) {
  assert.match(api, new RegExp(person), `Sachverständiger fehlt in Aggregation: ${person}`);
}
for (const field of ['total_net', 'total_gross', 'annualized_net', 'annualized_gross', 'missing_count']) {
  assert.match(api, new RegExp(field), `Aggregatfeld fehlt: ${field}`);
}
assert.match(page, /Gesamtabrechnung \(Claims\)/);
assert.match(page, /Gesamtabrechnung \(Rekon\)/);
assert.match(page, /Hochrechnung bis 31\.12\./);
console.log('Gesamtabrechnung-Profile, Sachverständige und Hochrechnung geprüft.');
