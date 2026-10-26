import assert from 'node:assert/strict';
import {runNativeForm} from '../browser-extension/claimsforce-bridge/native-form-worker.mjs';
const caseId='11111111-1111-4111-8111-111111111111',formId='22222222-2222-4222-8222-222222222222';
function fixture(){
 const request={operationId:'33333333-3333-4333-8333-333333333333',action:'save',caseId,formId,profile:'christian',claimNumber:'SYNTHETIC-001',expectedHash:'a'.repeat(64),changes:[{field:'Hinweis1',before:'Old',after:'New'}]};
 let route=`/claims/${caseId}/investigations`,value='Old',dirty=false;const calls=[],journal=new Map();
 const io={assertAccount:async()=>{},createTab:async url=>{calls.push('create-own');return{id:9,url}},waitTab:async()=>{},getTab:async()=>({url:'https://web.claimsforce.com'+route}),pause:async()=>{},closeTab:async id=>calls.push('close:'+id),journal:{get:async id=>journal.get(id),set:async(id,v)=>journal.set(id,v)},send:async(id,m)=>{
  calls.push(m.type);
  if(m.type==='NATIVE_FORM_INVENTORY')return{ok:true,result:[{formId,status:'draft'}]};
  if(m.type==='NAVIGATE_NATIVE_FORM_LIST'){route=`/claims/${caseId}/investigations`;return{ok:true}}
  if(m.type==='OPEN_EXISTING_NATIVE_FORM'){route=`/claims/${caseId}/forms/${formId}`;return{ok:true}}
  if(m.type==='READ_FORM_READINESS')return{ok:true,observation:{outerLoaded:true,viewerInitialized:true,fieldsReadable:true,fieldsEditable:true,identityVerified:true}};
  if(m.type==='NATIVE_FORM_APPLY'){value='New';dirty=true;return{ok:true}}
  if(m.type==='NATIVE_FORM_SAVE'){dirty=false;return{ok:true}}
  if(m.type==='NATIVE_FORM_SNAPSHOT')return{ok:true,result:{caseId,formId,profile:'christian',claimNumber:'SYNTHETIC-001',dirty,hash:value==='Old'?'a'.repeat(64):'b'.repeat(64),fields:[{id:'Hinweis1',value,writable:true}]}};
  throw Error('unexpected '+m.type);
 }};return{request,io,calls,journal};
}
let n=0;async function test(name,fn){await fn();n++;console.log('ok',name)}
await test('draft saved and reopened in own tab only',async()=>{const s=fixture();assert.equal((await runNativeForm(s.request,s.io)).verified,true);assert.equal(s.calls.filter(c=>c==='OPEN_EXISTING_NATIVE_FORM').length,2);assert.equal(s.calls.at(-1),'close:9')});
await test('inventory remains read-only',async()=>{const s=fixture();const r=await runNativeForm({...s.request,action:'inspect',formId:''},s.io);assert.equal(r.readOnly,true);assert.ok(!s.calls.some(c=>/APPLY|SAVE/.test(c)))});
await test('inspection reads actual fields',async()=>{const s=fixture();const r=await runNativeForm({...s.request,action:'inspect'},s.io);assert.equal(r.snapshot.fields[0].value,'Old');assert.ok(!s.calls.some(c=>/APPLY|SAVE/.test(c)))});
await test('sent original rejects patch before entry',async()=>{const s=fixture(),send=s.io.send;s.io.send=(id,m)=>m.type==='NATIVE_FORM_INVENTORY'?{ok:true,result:[{formId,status:'sent'}]}:send(id,m);await assert.rejects(runNativeForm(s.request,s.io),/NOT_CLEAN_DRAFT/);assert.ok(!s.calls.includes('NATIVE_FORM_APPLY'))});
await test('uncertain save keeps own tab for reconciliation',async()=>{const s=fixture(),send=s.io.send;s.io.send=(id,m)=>m.type==='NATIVE_FORM_SAVE'?{ok:false,error:'SAVE_FAILED'}:send(id,m);await assert.rejects(runNativeForm(s.request,s.io),/SAVE_FAILED/);assert.ok(!s.calls.includes('close:9'));assert.equal(s.journal.get(s.request.operationId).state,'uncertain')});
await test('account failure creates no tab',async()=>{const s=fixture();s.io.assertAccount=async()=>{throw Error('WRONG_ACCOUNT')};await assert.rejects(runNativeForm(s.request,s.io),/WRONG_ACCOUNT/);assert.deepEqual(s.calls,[])});
await test('delayed status badge settles before draft edit',async()=>{const s=fixture(),send=s.io.send;let reads=0,now=0;s.io.clock=()=>now;s.io.pause=async()=>{now+=200};s.io.send=(id,m)=>m.type==='NATIVE_FORM_INVENTORY'?{ok:true,result:[{formId,status:++reads<3?'unknown':'draft'}]}:send(id,m);assert.equal((await runNativeForm(s.request,s.io)).verified,true);assert.equal(reads,4)});
await test('unresolved status never permits a write',async()=>{const s=fixture(),send=s.io.send;let now=0;s.io.clock=()=>now;s.io.pause=async()=>{now+=200};s.io.send=(id,m)=>m.type==='NATIVE_FORM_INVENTORY'?{ok:true,result:[{formId,status:'unknown'}]}:send(id,m);await assert.rejects(runNativeForm(s.request,s.io),/NATIVE_INVENTORY_STATUS_NOT_READY/);assert.ok(!s.calls.includes('NATIVE_FORM_APPLY'));assert.equal(now,15000)});
console.log(`${n} worker tests passed; browser transport simulated.`);
