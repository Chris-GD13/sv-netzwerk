import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

const window = {};
vm.runInNewContext(fs.readFileSync(new URL('../public/intern/task-case-address.js', import.meta.url), 'utf8'), { window });
const helper = window.SVNetTaskAddress;
assert.deepEqual(Array.from(helper.addresses('Fwd: Unterlagen - Hagenbacher Ring 246 - Termin')), ['Hagenbacher Ring 246']);
assert.deepEqual(Array.from(helper.addresses('Königsberger Str. 21, 86690 Mertingen')), ['Königsberger Str. 21']);
assert.equal(helper.addresses('Telefon 07367 3939783').length, 0);
const calls = [];
const rows = [
  { id: 'correct', meta: { vn_objekt: 'Hagenbacher Ring 246' } },
  { id: 'wrong-number', meta: { strasse: 'Hagenbacher Ring 2460' } },
  { id: 'wrong-street', meta: { strasse: 'Anderer Ring 246' } },
];
const found = await helper.search({ subject: 'Neue Unterlagen', body: 'Hagenbacher Ring 246' }, async url => { calls.push(url); return { results: rows }; });
assert.equal(found.length, 1);
assert.equal(found[0].id, 'correct');
const ambiguous = await helper.search({ subject: 'Hagenbacher Ring 246' }, async () => ({ results: [rows[0], { id: 'other-case', meta: { schaden_strasse: 'Hagenbacher Ring 246' } }] }));
assert.equal(ambiguous.length, 2, 'Multiple cases at one address remain separate suggestions');
assert.equal(new URL(calls[0], 'http://localhost').searchParams.get('q'), 'Hagenbacher Ring 246');
const street = await helper.search({ subject: 'Termin Königsberger Straße 21' }, async url => {
  assert.equal(new URL(url, 'http://localhost').searchParams.get('q').endsWith('str 21'), true);
  return { results: [{ id: 'street', meta: { strasse: 'Koenigsberger Str. 21' } }] };
});
assert.equal(street.length, 1, 'Umlaut spelling and street abbreviation match the same address');
await assert.rejects(helper.search({ subject: 'Hagenbacher Ring 246' }, async () => { throw Error('IONOS nicht erreichbar'); }), /IONOS nicht erreichbar/);
console.log('Task address matching: passed');
