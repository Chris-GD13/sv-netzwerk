import assert from 'node:assert/strict';
import {performance} from 'node:perf_hooks';
import {viewerState,waitForViewer,qualityGate,DraftJournal} from '../browser-extension/claimsforce-bridge/draft-agent-core.mjs';
const results=[];
async function test(name, run) {
  const start=performance.now();
  try { const detail=await run(); results.push({name,ok:true,ms:performance.now()-start,detail}); }
  catch(e) {results.push({name,ok:false,ms:performance.now()-start,error:e.stack});}
}
// Synthetic fixture follows the existing worker-test structure (Testobjekt, offer, photo, metadata).
// These are test amounts, not findings about a real claim. Ledger is gross-valued, reserve-inclusive.
import {plan} from './claims_native_fixture.mjs';
const ready={outerLoaded:true,viewerInitialized:true,fieldsReadable:true,fieldsEditable:true,identityVerified:true};

await test('viewer-delayed-iframe',async()=>{
 let time=0;
 const r=await waitForViewer(async()=>time<500?{outerLoaded:true}:time<1500?{outerLoaded:true,viewerInitialized:true}:ready,
 {clock:()=>time,pause:async ms=>{time+=ms;},timeoutMs:2000});
 assert.equal(r.ok,true);assert.equal(r.elapsedMs,1500);assert.deepEqual(r.transitions.map(x=>x.state),['viewer-loading','form-unverified','ready']);return r;
});
await test('viewer-empty-timeout',async()=>{
 let t=0;const r=await waitForViewer(async()=>({outerLoaded:true}),{clock:()=>t,pause:async ms=>{t+=ms;},timeoutMs:900});
 assert.equal(r.ok,false);assert.equal(r.state,'timeout');assert.equal(r.elapsedMs,900);return r;
});
for(const [name,o,expected] of [
 ['login',{loginRequired:true},'authentication-required'],['asset',{failedAsset:true},'asset-failed'],
 ['tool-observation-blocked',{observationBlocked:true},'observation-blocked'],
 ['canvas-without-fields',{outerLoaded:true,viewerInitialized:true,canvasRendered:true},'form-unverified'],
 ['wrong-identity',{...ready,identityVerified:false},'identity-unverified']]) {
 await test(`viewer-${name}`,()=>assert.equal(viewerState(o),expected));
}
for(const amount of [2499999,2500000,2500001]) await test(`gf-boundary-${amount}`,()=>{
 const p=plan(amount);p.documents=p.documents.filter(d=>d.kind!=='report');const r=qualityGate(p);
 assert.equal(r.gfReportRequired,amount>=2500000);assert.equal(r.errors.includes('GF_WORD_REPORT'),amount>=2500000);return r;
});
const mutations={
 'wrong-profile':p=>{p.observedIdentity.profile='marc';},
 'foreign-source':p=>{p.sources[0].profile='holger';},
 'missing-source':p=>{p.sources=[];},
 'unreadable-source':p=>{p.sources[0].readable=false;},
 'invented-fact':p=>{p.facts[0].sourceIds=['not-found'];},
 'net-vat-mismatch':p=>{p.positions[0].vatCents=1;},
 'double-kva-invoice':p=>{p.positions.push({...p.positions[0],id:'invoice',replaces:'estimate'});},
 'approved-is-not-paid':p=>{p.payments[0].status='approved';},
 'offer-is-not-payment-proof':p=>{p.payments[0].sourceId='offer';},
 'unreviewed-overlap':p=>{p.overlapReviewed=false;},
 'invoice-position-twice':p=>{p.positions.push({...p.positions[0],id:'second-id'});},
 'duplicate-payment':p=>{p.payments.push({...p.payments[0]});},
 'double-deductible':p=>{p.deductibleApplications=2;},
 'wrong-balance':p=>{p.remainingCents+=1;},
 'duplicate-attachment':p=>{p.documents.push({...p.documents[0]});},
 'wrong-report-folder':p=>{p.documents[0].folder='Formulare';},
 'pdf-instead-of-word':p=>{p.documents[0].format='pdf';},
 'pdf-instead-of-native-se':p=>{p.documents[1].native=false;},
 'obsolete-zahlungsbefuerwortung':p=>{p.documents.push({...p.documents[1],kind:'Zahlungsbefürwortung',hash:'obsolete'});},
 'internal-problem-in-report':p=>{p.containsInternalNotes=true;},
 'unreviewed-external-text':p=>{p.externalTextReviewed=false;},
 'dispatch-request':p=>{p.operation='send';}
};
for(const [name,mutate] of Object.entries(mutations)) await test(`qs-block-${name}`,()=>{
 const p=plan();mutate(p);const r=qualityGate(p);assert.equal(r.ok,false);assert.ok(r.errors.length);return r.errors;
});
await test('qs-valid-plan',()=>assert.equal(qualityGate(plan()).ok,true));
function linkedPlan() {
 const p=plan(1700000);p.reportingGroup={id:'offline-event',relationVerified:true,profile:p.profile,claims:[
 {caseId:p.caseId,profile:p.profile,exposureCents:1700000,evidenceVerified:true,evidenceHash:'own-source'},
 {caseId:'offline-household',profile:p.profile,exposureCents:800000,evidenceVerified:true,evidenceHash:'related-source'}]};return p;
}
await test('linked-claims-17000-plus-8000-report-required-se-separate',()=>{
 const p=linkedPlan(),r=qualityGate(p);assert.equal(r.ok,true);assert.equal(r.gfReportRequired,true);
 assert.equal(r.reportingExposureCents,2500000);assert.equal(r.exposureCents,1700000);
 assert.equal(p.remainingCents,1640000);return r;
});
for(const [name,mutate] of Object.entries({
 'no-link-evidence':p=>{p.reportingGroup.relationVerified=false;},
 'foreign-profile':p=>{p.reportingGroup.claims[1].profile='marc';},
 'mixed-se':p=>{p.documents[1].positionCaseIds.push('offline-household');},
 'group-sum-in-own-se':p=>{p.remainingCents=2440000;},
 'linked-case-position-in-own-ledger':p=>{p.positions[0].caseId='offline-household';},
 'no-word-at-combined-threshold':p=>{p.documents=p.documents.filter(d=>d.kind!=='report');}
})) await test(`linked-block-${name}`,()=>{const p=linkedPlan();mutate(p);const r=qualityGate(p);assert.equal(r.ok,false);return r.errors;});
function memoryStore() {
 const rows=new Map();return {get:async k=>structuredClone(rows.get(k)),set:async(k,v)=>rows.set(k,structuredClone(v)),
 putIfAbsent:async(k,v)=>{if(!rows.has(k))rows.set(k,structuredClone(v));}};
}
await test('journal-crash-after-save-restart-readback',async()=>{
 const store=memoryStore();let j=new DraftJournal(store), writes=0,record;
 const adapter={offlineOnly:true,find:async()=>record,save:async row=>{writes++;record={...row,nativeId:'offline-native-id'};throw new Error('simulated disconnect after successful save');}};
 const row=await j.prepare(plan());await assert.rejects(()=>j.simulateSave(row,adapter),/disconnect/);
 j=new DraftJournal(store);const resumed=await j.prepare(plan());assert.equal(resumed.state,'save-uncertain');
 const verified=await j.simulateSave(resumed,adapter);assert.equal(verified.state,'verified');assert.equal(writes,1);return {writes,state:verified.state};
});
await test('journal-uncertain-no-record-blocks-recreate',async()=>{
 const store=memoryStore(),j=new DraftJournal(store);let writes=0;
 const adapter={offlineOnly:true,find:async()=>null,save:async()=>{writes++;throw new Error('timeout');}};
 const row=await j.prepare(plan());await assert.rejects(()=>j.simulateSave(row,adapter),/timeout/);
 await assert.rejects(()=>j.simulateSave(row,adapter),/RECONCILIATION_REQUIRED/);assert.equal(writes,1);
});
await test('journal-wrong-readback-blocked',async()=>{
 const j=new DraftJournal(memoryStore()),r=await j.prepare(plan());
 await assert.rejects(()=>j.simulateSave(r,{offlineOnly:true,find:async()=>({...r,nativeId:'x',profile:'marc'})}),/READBACK_MISMATCH/);
});
await test('journal-production-adapter-disabled',async()=>{
 const j=new DraftJournal(memoryStore()),r=await j.prepare(plan());let calls=0;
 await assert.rejects(()=>j.simulateSave(r,{find:async()=>{calls++;}}),/PRODUCTION_WRITES_DISABLED/);assert.equal(calls,0);
});
await test('journal-changed-content-needs-review',async()=>{
 const j=new DraftJournal(memoryStore());await j.prepare(plan());const p=plan();p.contentHash='changed';
 await assert.rejects(()=>j.prepare(p),/CONTENT_CHANGED_REQUIRES_NEW_REVIEW/);
});
await test('reference-offline-complete-draft-replay',async()=>{
 const stages=[];async function phase(name,fn){const t=performance.now();const r=await fn();stages.push({name,ms:performance.now()-t});return r;}
 const p=await phase('load-synthetic-source-inventory',async()=>plan());
 await phase('source-and-financial-qs',async()=>assert.equal(qualityGate(p).ok,true));
 const journal=new DraftJournal(memoryStore());const row=await phase('prepare-idempotent-draft',()=>journal.prepare(p));
 let persisted;
 const adapter={offlineOnly:true,find:async()=>persisted,save:async r=>{persisted={...r,nativeId:'offline-draft-id'};}};
 await phase('simulated-save-and-readback',async()=>assert.equal((await journal.simulateSave(row,adapter)).state,'verified'));
 return {stages,limitations:'Prepared synthetic facts and documents; no OCR, expert assessment, document rendering, live latency, durable storage or actual Claims write. Not a real end-to-end production time.'};
});
await test('viewer-unresponsive-probe-is-bounded',async()=>{const r=await waitForViewer(()=>new Promise(()=>{}),{clock:()=>Date.now(),pause:async()=>{},timeoutMs:10});assert.equal(r.ok,false);assert.equal(r.state,'observation-blocked')});
const report={at:new Date().toISOString(),mode:'offline-only; no network/model/Claims writes',
 passed:results.filter(r=>r.ok).length,total:results.length,results};

console.log(JSON.stringify({...report,results:report.results.filter(r=>!r.ok)}));
if(report.passed!==report.total)process.exitCode=1;
