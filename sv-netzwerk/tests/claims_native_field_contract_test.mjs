import assert from 'node:assert/strict';
import {NATIVE_PROFILES, assertFieldLabels, normalizeFieldLabel, sameFieldLabel} from '../browser-extension/claimsforce-bridge/native-field-contract.mjs';

assert.deepEqual([...NATIVE_PROFILES], ['christian','holger','marc','jens']);
assert.equal(normalizeFieldLabel('  Gesprächspartner:  '), 'gesprächspartner:');
assert.equal(sameFieldLabel('Gesprächspartner',' gesprächspartner '), true);
assert.equal(sameFieldLabel('Gesprächspartner','Regulierer'), false);
assert.doesNotThrow(()=>assertFieldLabels([
  {id:'a',label:'Versicherungsnummer'},
  {id:'b',label:'Gesprächspartner'},
], ['Versicherungsnummer','Gesprächspartner']));
assert.throws(()=>assertFieldLabels([{id:'a',label:''}]), /LABEL_MISSING/);
assert.throws(()=>assertFieldLabels([
  {id:'a',label:'E-Mail'},
  {id:'b',label:' E-Mail '},
]), /LABEL_DUPLICATE/);
assert.throws(()=>assertFieldLabels([{id:'a',label:'Versicherungsnummer'}], ['Regulierer']), /REQUIRED_FIELD_LABEL_MISSING/);
console.log('Native field contract tests passed; anonymized data only.');
