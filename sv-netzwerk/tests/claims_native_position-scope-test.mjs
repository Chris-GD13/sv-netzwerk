import assert from 'node:assert/strict';
import {checkPositionScopes} from '../browser-extension/claimsforce-bridge/position-scope-qs.mjs';
const line={caseId:'A',sourceId:'quote',sourceHash:'hash',locator:'page1 position1',netCents:10000,vatCents:1900,grossCents:11900,taxBasis:'quoted-standard',included:true,scopeVerified:true,scopeUnits:['floor-room1-area-unit1','floor-room1-area-unit2']};
const tests=[];function test(name,lines,ok){const r=checkPositionScopes('A',lines);assert.equal(r.ok,ok,name);tests.push({name,ok:true,errors:r.errors});}
test('whole-quote', [line],true);
test('invoice-replaces-only-one-portion',[{...line,scopeUnits:['floor-room1-area-unit1']},{...line,sourceId:'invoice',scopeUnits:['floor-room1-area-unit2']}],true);
test('partial-overlap-blocked',[line,{...line,sourceId:'invoice',scopeUnits:['floor-room1-area-unit2']}],false);
test('old-estimate-excluded-new-invoice-included',[{...line,included:false},{...line,sourceId:'invoice'}],true);
test('same-room-different-work',[line,{...line,scopeUnits:['paint-room1-unit1']}],true);
test('other-damage-number-blocked',[{...line,caseId:'B'}],false);
test('unknown-quantity-mapping-blocked',[{...line,scopeVerified:false}],false);
test('unproved-source-blocked',[{...line,sourceHash:null}],false);
test('documented-zero-VAT-preserved',[{...line,vatCents:0,grossCents:10000,taxBasis:'quoted-zero'}],true);
test('zero-VAT-not-guessed',[{...line,vatCents:0,grossCents:10000,taxBasis:null}],false);
test('VAT-not-added-twice',[{...line,grossCents:13800}],false);
console.log(JSON.stringify({passed:tests.length,total:tests.length}));
