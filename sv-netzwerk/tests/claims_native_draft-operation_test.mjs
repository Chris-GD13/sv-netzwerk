import assert from 'node:assert/strict';
import {createHash} from 'node:crypto';
import {executeNativeDraft} from '../browser-extension/claimsforce-bridge/native-draft-operation.mjs';
const hash=x=>createHash('sha256').update(JSON.stringify(x)).digest('hex');
function setup(){
 const request={operationId:'11111111-1111-4111-8111-111111111111',caseId:'22222222-2222-4222-8222-222222222222',formId:'33333333-3333-4333-8333-333333333333',profile:'christian',claimNumber:'SYNTHETIC-001',expectedHash:'a'.repeat(64),changes:[{field:'Hinweis1',before:'Old',after:'New'}]};
 let snapshot={...request,hash:request.expectedHash,status:'draft',dirty:false,fields:[{id:'Hinweis1',value:'Old',writable:true},{id:'TeilzahlungRow1',value:'100,00 €',writable:true}]};
 const journal=new Map(),calls=[];let tail=Promise.resolve();
 const io={hash,withLock:(_p,fn)=>{const run=tail.then(fn);tail=run.catch(()=>{});return run},journal:{get:async k=>journal.get(k),set:async(k,v)=>journal.set(k,v)},assertAccount:async()=>{},openAndRead:async()=>structuredClone(snapshot),apply:async r=>{calls.push('apply');for(const c of r.changes)snapshot.fields.find(f=>f.id===c.field).value=c.after},save:async()=>calls.push('save'),reopenAndRead:async()=>{calls.push('reopen');return structuredClone({...snapshot,hash:'b'.repeat(64)})}};
 return{request,io,calls,journal,snapshot};
}
let n=0;async function test(name,fn){await fn();n++;console.log('ok',name)}
await test('verified only after save and independent reopen',async()=>{const s=setup();assert.equal((await executeNativeDraft(s.request,s.io)).verified,true);assert.deepEqual(s.calls,['apply','save','reopen'])});
await test('concurrent duplicate operation applies once',async()=>{const s=setup();const results=await Promise.all([executeNativeDraft(s.request,s.io),executeNativeDraft(s.request,s.io)]);assert.ok(results.every(r=>r.verified));assert.equal(s.calls.filter(c=>c==='apply').length,1)});
await test('same operation key with changed payload rejected',async()=>{const s=setup();await executeNativeDraft(s.request,s.io);s.request.changes[0].after='Other';await assert.rejects(executeNativeDraft(s.request,s.io),/IDEMPOTENCY_CONFLICT/)});
for(const [name,mutate,code] of [
 ['wrong case',s=>s.snapshot.caseId='foreign','IDENTITY_MISMATCH'],
 ['wrong account',s=>s.snapshot.profile='jens','IDENTITY_MISMATCH'],
 ['sent original',s=>s.snapshot.status='sent','NOT_CLEAN_DRAFT'],
 ['unsaved user edits',s=>s.snapshot.dirty=true,'NOT_CLEAN_DRAFT'],
 ['stale snapshot',s=>s.snapshot.hash='c'.repeat(64),'STALE_SNAPSHOT'],
 ['changed source value',s=>s.snapshot.fields[0].value='Other','FIELD_CONFLICT'],
 ['duplicate widget',s=>s.snapshot.fields.push({...s.snapshot.fields[0]}),'FIELD_NOT_WRITABLE'],
 ['calculated total',s=>{s.request.changes[0].field='Endergebnis';s.snapshot.fields[0].id='Endergebnis'},'FIELD_NOT_WRITABLE'],
 ['invalid dropdown',s=>s.snapshot.fields[0].options=['Old','No'],'OPTION_INVALID']
])await test(name,async()=>{const s=setup();mutate(s);await assert.rejects(executeNativeDraft(s.request,s.io),new RegExp(code));assert.deepEqual(s.calls,[])});
await test('save acknowledgement without persistence fails',async()=>{const s=setup();s.io.reopenAndRead=async()=>({...s.snapshot,fields:[{id:'Hinweis1',value:'Old'}]});await assert.rejects(executeNativeDraft(s.request,s.io),/READBACK_MISMATCH/);assert.equal(s.journal.get(s.request.operationId).state,'uncertain');await assert.rejects(executeNativeDraft(s.request,s.io),/PRIOR_ATTEMPT_REQUIRES_READBACK/);assert.equal(s.calls.filter(c=>c==='apply').length,1)});
await test('unexpected collateral change fails',async()=>{const s=setup();const save=s.io.save;s.io.save=async()=>{await save();s.snapshot.fields[1].value='200,00 €'};await assert.rejects(executeNativeDraft(s.request,s.io),/UNREQUESTED_CHANGE/)});
await test('session changes before save leave uncertain journal',async()=>{const s=setup();let checks=0;s.io.assertAccount=async()=>{if(++checks===3)throw Error('ACCOUNT_CHANGED')};await assert.rejects(executeNativeDraft(s.request,s.io),/ACCOUNT_CHANGED/);assert.deepEqual(s.calls,['apply'])});
console.log(`${n} tests passed. Orchestration transport simulated; not a production write integration.`);
