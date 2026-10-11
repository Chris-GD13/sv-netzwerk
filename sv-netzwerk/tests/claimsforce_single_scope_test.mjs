import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
import { sameImportScope, assertSingleClaim, mergeClaimFiles } from '../browser-extension/claimsforce-bridge/import-utils.js';
const request = { jobId: 44, profile: 'christian', mode: 'single', claimNumber: '26-085905-9' };
assert(sameImportScope(request, {...request}));
for (const change of [{mode:'quick'}, {claimNumber:'26-076821-6'}, {profile:'marc'}, {jobId:45}]) assert(!sameImportScope(request,{...request,...change}));
assertSingleClaim(request.claimNumber,[{id:'target',label:'26-085905-9'}]);
assert.throws(()=>assertSingleClaim(request.claimNumber,[{id:'wrong',label:'26-076821-6'}]));
assert.throws(()=>assertSingleClaim(request.claimNumber,[{id:'target',label:request.claimNumber},{id:'other',label:'other'}]));
assert.throws(()=>assertSingleClaim(request.claimNumber,[]));
for (const requested of ['25-150913-0', '25-150913-0 GF']) {
  for (const found of ['25-150913-0', '25-150913-0 GF']) {
    assertSingleClaim(requested, [{id:'target', schaden_nr:found}]);
  }
  assert.throws(()=>assertSingleClaim(requested,[{id:'wrong',schaden_nr:'25-150914-0 GF'}]));
  assert.throws(()=>assertSingleClaim(requested,[{id:'one',schaden_nr:requested},{id:'two',schaden_nr:requested}]));
}
assert.throws(()=>assertSingleClaim('AB123-456', [{id:'wrong',schaden_nr:'AB123-456 GF'}]));
const merged=mergeClaimFiles([{id:'shared',fileName:'A.pdf'}],[{id:'client',fileName:'Auftrag.pdf'}],[{attachments:[{id:'shared',type:'CLAIM_FILE'},{id:'mail-only',type:'CLAIM_FILE'}]}]);
assert.deepEqual(merged.map(f=>f.id),['shared','client','mail-only']);
assert.equal(merged[2].attachmentReference,true);
console.log('Einzelfallumfang, Wiederaufnahme und Dokument-/Mailanhangzuordnung geprüft.');

// Exercise the real content-script search and subsequent worker scope guard.
const source = fs.readFileSync(new URL('../browser-extension/claimsforce-bridge/claims-bridge.js', import.meta.url), 'utf8');
for (const requested of ['25-150913-0', '25-150913-0 GF']) {
  let listener, searchValue;
  const input = { getAttribute: () => 'Schäden durchsuchen', dispatchEvent() {} };
  class Input {}
  Object.defineProperty(Input.prototype, 'value', {set(value) {searchValue = value;}});
  const anchor = {innerText:'25-150913-0 GF Gebäude Uhlandstr. 2', getAttribute:name=>name==='href'?'/claims/3ef98fb9-5494-5845-9301-c2f38edb2f79/redirect':'', closest:()=>null};
  vm.runInNewContext(source, {
    window:{addEventListener(){}}, location:{pathname:'/planning',origin:'https://web.claimsforce.com'},
    document:{querySelectorAll:selector=>selector==='input'?[input]:searchValue==='25-150913-0'?[anchor]:[]},
    HTMLInputElement:Input, Event:class {}, setTimeout:callback=>{queueMicrotask(callback);},
    chrome:{runtime:{onMessage:{addListener(fn){listener=fn;}}}}
  });
  const result = await new Promise(resolve=>listener({type:'SCRAPE_CLAIM_BY_DAMAGE_NUMBER',damageNumber:requested},{},resolve));
  assert.equal(result.ok,true);
  assert.equal(searchValue,'25-150913-0');
  assertSingleClaim(requested,result.claims);
  assertSingleClaim(requested,[{id:result.claims[0].id,schaden_nr:'25-150913-0 GF'}]);
}
console.log('GF-Suche und nachfolgender Falldatenabgleich mit und ohne Zusatz geprüft.');
