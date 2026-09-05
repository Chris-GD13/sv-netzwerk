import assert from 'node:assert/strict';
import { cleanVCards } from '../scripts/clean-phonebook-vcards.mjs';

const card = (name, phone) => [
  'BEGIN:VCARD',
  'VERSION:3.0',
  `FN:${name}`,
  `TEL:${phone}`,
  'END:VCARD',
].join('\r\n');

const result = cleanVCards([
  [
    card('Andrea Heiligers - AB per Mail +mhg.', '02526/29-3055'),
    card('Andersson Niclas', '46 10 4707386'),
    card('Abbes Walter', '49-471-31814'),
  ].join('\r\n'),
]);

assert.equal(result.report.excludedUnwantedContacts, 2);
assert.match(result.vcf, /FN:Abbes Walter/);
assert.doesNotMatch(result.vcf, /Andersson|\bAB\b|per\s*mail/i);
console.log('phonebook_cleanup_test: ok');
