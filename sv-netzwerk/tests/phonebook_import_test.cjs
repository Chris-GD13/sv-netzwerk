const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require.resolve('../public/intern/phonebook-import.js'), 'utf8');
const sandbox = {};
vm.runInNewContext(source, sandbox);
const parser = sandbox.SVNetPhonebookImport;

const csv = parser.parse('Name;Telefon;Notiz\nChristian Handy;01604092134;intern', 'kontakte.csv');
assert.deepEqual(JSON.parse(JSON.stringify(csv)), [{ name: 'Christian Handy', phone: '01604092134', note: 'intern' }]);

const vcf = parser.parse('BEGIN:VCARD\nVERSION:3.0\nFN:Cedric Kalabis\nTEL;TYPE=CELL:+49 170 1234567\nORG:SV-Netzwerk\nEND:VCARD', 'kontakte.vcf');
assert.deepEqual(JSON.parse(JSON.stringify(vcf)), [{ name: 'Cedric Kalabis', phone: '+49 170 1234567', note: 'SV-Netzwerk' }]);

const csvNoHeader = parser.parse('Marc Handy,01701234567,SV', 'liste.csv');
assert.deepEqual(JSON.parse(JSON.stringify(csvNoHeader)), [{ name: 'Marc Handy', phone: '01701234567', note: 'SV' }]);
console.log('phonebook_import_test: ok');
