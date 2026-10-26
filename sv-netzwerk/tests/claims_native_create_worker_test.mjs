import assert from 'node:assert/strict';import {createHash} from 'node:crypto';
import {runNativeCreate} from '../browser-extension/claimsforce-bridge/native-create-worker.mjs';
const hash=async v=>createHash('sha256').update(JSON.stringify(v)).digest('hex');
function fixture(){
 const caseId='11111111-1111-4111-8111-111111111111',formId='22222222-2222-4222-8222-222222222222';
 const request={action:'inspect_templates',caseId,profile:'christian',claimNumber:'SYNTHETIC',operationId:'33333333-3333-4333-8333-333333333333',templateName:'SV Schlusserklärung'};
 const forms=[{formId:'44444444-4444-4444-8444-444444444444',templateName:'SV Schlusserklärung',status:'sent'}],journal=new Map();let route=`/claims/${caseId}/investigations`,clicks=0;
 const io={assertAccount:async()=>{},getTab:async()=>({url:'https://web.claimsforce.com'+route}),pause:async()=>{},journal:{get:async k=>journal.get(k),set:async(k,v)=>journal.set(k,v)},send:async(_,m)=>{
  if(m.type==='NATIVE_FORM_INVENTORY')return{ok:true,result:structuredClone(forms)};
  if(m.type==='NATIVE_FORM_TEMPLATES')return{ok:true,result:{templates:['SV Schlusserklärung','SV Zahlungsbefürwortung']}};
  if(m.type==='NATIVE_FORM_CREATE'){clicks++;forms.push({formId,templateName:m.templateName,status:'draft'});route=`/claims/${caseId}/forms/${formId}`;return{ok:true,result:{acknowledged:true}};}
  if(m.type==='NAVIGATE_NATIVE_FORM_LIST'){route=`/claims/${caseId}/investigations`;return{ok:true};}
  if(m.type==='OPEN_EXISTING_NATIVE_FORM'){route=`/claims/${caseId}/forms/${formId}`;return{ok:true};}
  if(m.type==='READ_FORM_READINESS')return{ok:true,observation:{outerLoaded:true,viewerInitialized:true,fieldsReadable:true,fieldsEditable:true,identityVerified:true}};
  if(m.type==='NATIVE_FORM_SNAPSHOT')return{ok:true,result:{caseId,formId,profile:'christian',claimNumber:'SYNTHETIC',dirty:false,fields:[]}};
  throw Error('unexpected '+m.type);
 }};return{io,request,journal,clicks:()=>clicks,reset:()=>{route=`/claims/${caseId}/investigations`;}};
}
const s=fixture();const inspected=await runNativeCreate(s.request,s.io,1,hash);assert.equal(inspected.readOnly,true);assert.equal(s.clicks(),0);
const command={...s.request,action:'create',expectedHash:inspected.hash};
assert.equal((await runNativeCreate(command,s.io,1,hash)).verified,true);assert.equal(s.clicks(),1);
s.reset();assert.equal((await runNativeCreate(command,s.io,1,hash)).reconciled,true);assert.equal(s.clicks(),1);
const key=`create-case:christian:${command.caseId}`;s.journal.set(key,{operationId:'other'});
await assert.rejects(runNativeCreate({...command,operationId:'55555555-5555-4555-8555-555555555555'},s.io,1,hash),/CASE_LOCKED/);
assert.equal(s.clicks(),1);
console.log('4 worker creation contracts passed: inspection, create/readback, replay, persistent lock; simulated transport.');
