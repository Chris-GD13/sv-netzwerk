import assert from 'node:assert/strict';
import { cleanVCards } from '../scripts/clean-phonebook-vcards.mjs';

const source = `BEGIN:VCARD
VERSION:3.0
FN:Marc Schütt
N:Schütt;Marc;;;
item1.TEL;TYPE=CELL:+49 170 1234567
item2.URL:ms-outlook://people/AAAA
item2.X-ABLabel:Outlook
EMAIL:marc@example.test
ADR;TYPE=WORK:;;Musterstraße 1;Aalen;;;Deutschland
END:VCARD
BEGIN:VCARD
VERSION:3.0
FN:Herr Marc Schütt
TEL:+49 (170) 1234567
EMAIL:MARC@example.test
NOTE:Chef ms-outlook://people/BBBB
END:VCARD
BEGIN:VCARD
VERSION:3.0
FN:Marc Schütt
TEL:+49 170 9999999
END:VCARD`;

const result = cleanVCards([source]);
assert.equal(result.report.sourceCards, 3);
assert.equal(result.report.cleanedCards, 2);
assert.equal(result.report.duplicatesRemoved, 1);
assert.equal(result.report.contactsWithPhone, 2);
assert.equal(result.report.removedOutlookLines, 3);
assert.match(result.vcf, /FN:Herr Marc Schütt/);
assert.match(result.vcf, /ADR;TYPE=WORK/);
assert.doesNotMatch(result.vcf, /ms-outlook:\/\//i);
assert.doesNotMatch(result.vcf, /X-APPLE-OL-/i);
assert.equal((result.vcf.match(/EMAIL:/g) || []).length, 1);
assert.equal((result.vcf.match(/TEL[^:]*:\+49 170 1234567/g) || []).length, 1);
assert.match(result.portalCsv, /^\uFEFFName;Telefon;Notiz/);
assert.equal((result.portalCsv.match(/Herr Marc Schütt/g) || []).length, 1);

const malformed = cleanVCards([`BEGIN:VCARD
VERSION:3.0
FN:Altlast
TEL:+49 123 456X-APPLE-OL-CUSTOMER-ID:10056
TEL:4,99722E+12
END:VCARD`]);
assert.match(malformed.vcf, /TEL:\+49 123 456/);
assert.doesNotMatch(malformed.vcf, /X-APPLE-OL-|4,99722E\+12/i);

const germanFormats = cleanVCards([`BEGIN:VCARD
VERSION:3.0
FN:Format eins
TEL:0170 1234567
END:VCARD
BEGIN:VCARD
VERSION:3.0
FN:Format zwei
TEL:+49 170 1234567
END:VCARD`]);
assert.equal(germanFormats.report.cleanedCards, 1);

console.log('phonebook_cleaner_test: ok');
