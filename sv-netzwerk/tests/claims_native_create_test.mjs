import assert from 'node:assert/strict';
import {createHash} from 'node:crypto';
import {createNativeDraft} from '../browser-extension/claimsforce-bridge/native-create-operation.mjs';
const hash = async value => createHash('sha256').update(JSON.stringify(value)).digest('hex');
function setup() {
 const command={operationId:'33333333-3333-4333-8333-333333333333',caseId:'11111111-1111-4111-8111-111111111111',profile:'christian',claimNumber:'SYNTHETIC',templateName:'SV Schlusserklärung',expectedHash:'a'.repeat(64)};
 const formId='22222222-2222-4222-8222-222222222222',journal=new Map();let creates=0;
 const snapshot={...command,formId,status:'draft',dirty:false};
 const inventory={ready:true,hash:command.expectedHash,forms:[],templates:[command.templateName]};
 const io={hash,withCaseLock:async(_,fn)=>fn(),assertIdentity:async()=>{},inventory:async()=>inventory,
 journal:{get:async k=>journal.get(k),set:async(k,v)=>journal.set(k,v)},
 createFromTemplate:async()=>{creates++;return{formId}},readDraft:async()=>snapshot};
 return{command,io,snapshot,inventory,journal,creates:()=>creates};
}
let n=0;async function test(name,fn){await fn();n++;console.log('ok',name)}
await test('create and independent readback; replay never creates again',async()=>{const s=setup();assert.equal((await createNativeDraft(s.command,s.io)).verified,true);assert.equal((await createNativeDraft(s.command,s.io)).reconciled,true);assert.equal(s.creates(),1)});
await test('existing matching draft blocks duplicate',async()=>{const s=setup();s.inventory.forms=[{templateName:s.command.templateName,status:'draft'}];await assert.rejects(createNativeDraft(s.command,s.io),/DRAFT_EXISTS/);assert.equal(s.creates(),0)});
await test('sent originals remain untouched',async()=>{const s=setup();s.inventory.forms=[{formId:'44444444-4444-4444-8444-444444444444',templateName:s.command.templateName,status:'sent'}];assert.equal((await createNativeDraft(s.command,s.io)).verified,true);assert.equal(s.inventory.forms[0].status,'sent')});
await test('lost click response remains uncertain and is never retried',async()=>{const s=setup();let clicks=0;s.io.createFromTemplate=async()=>{clicks++;throw Error('timeout')};await assert.rejects(createNativeDraft(s.command,s.io),/timeout/);await assert.rejects(createNativeDraft(s.command,s.io),/UNCERTAIN/);assert.equal(clicks,1)});
await test('readback failure can reconcile same ID without another create',async()=>{const s=setup();const read=s.io.readDraft;s.io.readDraft=async()=>{throw Error('read timeout')};await assert.rejects(createNativeDraft(s.command,s.io),/read timeout/);s.io.readDraft=read;assert.equal((await createNativeDraft(s.command,s.io)).reconciled,true);assert.equal(s.creates(),1)});
for(const field of ['caseId','profile','claimNumber','formId','templateName','status','dirty']) await test('reject wrong readback '+field,async()=>{const s=setup();s.snapshot[field]=field==='dirty'?true:'wrong';await assert.rejects(createNativeDraft(s.command,s.io),/READBACK_MISMATCH/);assert.equal(s.journal.get(s.command.operationId).state,'uncertain')});
await test('stale and loading inventories cannot create',async()=>{for(const change of [{ready:false},{hash:'b'.repeat(64)}]){const s=setup();Object.assign(s.inventory,change);await assert.rejects(createNativeDraft(s.command,s.io),/STALE/);assert.equal(s.creates(),0)}});
await test('unknown status cannot create',async()=>{const s=setup();s.inventory.forms=[{status:'unknown'}];await assert.rejects(createNativeDraft(s.command,s.io),/STATUS_UNKNOWN/)});
await test('missing or duplicate template cannot create',async()=>{for(const templates of [[],['SV Schlusserklärung','SV Schlusserklärung']]){const s=setup();s.inventory.templates=templates;await assert.rejects(createNativeDraft(s.command,s.io),/TEMPLATE_UNAVAILABLE/);assert.equal(s.creates(),0)}});
await test('changed preflight cannot create',async()=>{const s=setup();let i=0;s.io.inventory=async()=>({...s.inventory,hash:++i===1?s.command.expectedHash:'b'.repeat(64)});await assert.rejects(createNativeDraft(s.command,s.io),/CHANGED_BEFORE_WRITE/);assert.equal(s.creates(),0)});
await test('wrong account cannot create',async()=>{const s=setup();s.io.assertIdentity=async()=>{throw Error('WRONG_ACCOUNT')};await assert.rejects(createNativeDraft(s.command,s.io),/WRONG_ACCOUNT/);assert.equal(s.creates(),0)});
await test('operation reuse with different template rejected',async()=>{const s=setup();await createNativeDraft(s.command,s.io);await assert.rejects(createNativeDraft({...s.command,templateName:'SV Zahlungsbefürwortung'},s.io),/OPERATION_CONFLICT/);assert.equal(s.creates(),1)});
console.log(`${n} native creation tests passed; UI transport remains simulated.`);
