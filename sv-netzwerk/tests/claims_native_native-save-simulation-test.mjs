import assert from 'node:assert/strict';import {performance} from 'node:perf_hooks';
import {simulateNativeDraft} from '../browser-extension/claimsforce-bridge/native-draft-simulation.mjs';
import {plan} from './claims_native_fixture.mjs';
const base=plan();
function setup(){
 const p={...structuredClone(base),sourceVersion:'v1',nativeFormId:'existing-form',nativeDraftId:'existing-draft',fieldHash:'fields',pdfHash:'pdf',evidenceHash:'evidence'};
 p.approval={expires:2000,contentHash:p.contentHash,caseId:p.caseId,profile:p.profile};
 const calls=[],rows=new Map();let observations=0;
 const a={offlineOnly:true,
 inspect:async()=>{observations++;return {profile:p.profile,caseId:p.caseId,sourceVersion:'v1'};},
 reconcile:async()=>null,
 uploadExistingForm:async()=>{calls.push('upload');return {ok:true};},confirmExistingForm:async()=>{calls.push('confirm');},
 readExistingForm:async()=>({id:p.nativeFormId,caseId:p.caseId,profile:p.profile,fieldHash:p.fieldHash,pdfHash:p.pdfHash}),
 updateExistingDraft:async()=>{calls.push('update');return {ok:true};},
 readExistingDraft:async()=>({id:p.nativeDraftId,caseId:p.caseId,profile:p.profile,contentHash:p.contentHash,evidenceHash:p.evidenceHash})};
 const guard={assertLease:async()=>{}},journal={load:async id=>rows.get(id),save:async(id,x)=>rows.set(id,x)};
 return {p,a,guard,journal,calls,rows,run:()=>simulateNativeDraft(p,a,guard,journal,()=>1000)};
}
const results=[];
async function test(name,fn){const start=performance.now();await fn();results.push({name,ok:true,ms:performance.now()-start});}
await test('native-existing-ids-only-and-readback',async()=>{const s=setup();assert.equal((await s.run()).state,'verified');assert.deepEqual(s.calls,['upload','confirm','update']);});
for(const [name,mutate,error] of [
 ['no-live-transport',s=>{s.a.offlineOnly=false;},'LIVE_TRANSPORT_DISABLED'],
 ['missing-existing-form-id',s=>{s.p.nativeFormId=null;},'NATIVE_TARGET_OR_VERSION_MISSING'],
 ['missing-existing-draft-id',s=>{s.p.nativeDraftId=null;},'NATIVE_TARGET_OR_VERSION_MISSING'],
 ['expired-approval',s=>{s.p.approval.expires=1000;},'APPROVAL_INVALID'],
 ['wrong-approval-profile',s=>{s.p.approval.profile='marc';},'APPROVAL_INVALID'],
 ['changed-content',s=>{s.p.approval.contentHash='old';},'APPROVAL_INVALID'],
 ['changed-source',s=>{s.p.sourceVersion='old';},'SOURCE_CHANGED'],
 ['mixed-case',s=>{s.a.inspect=async()=>({profile:s.p.profile,caseId:'foreign',sourceVersion:'v1'});},'IDENTITY_CHANGED'],
 ['lease-lost',s=>{s.guard.assertLease=async()=>{throw Error('STALE_LEASE');};},'STALE_LEASE'],
 ['upload-403-no-confirm',s=>{s.a.uploadExistingForm=async()=>{s.calls.push('upload');return {ok:false};};},'UPLOAD_FAILED'],
 ['wrong-form-content',s=>{s.a.readExistingForm=async()=>({id:s.p.nativeFormId,caseId:s.p.caseId,profile:s.p.profile,fieldHash:'wrong',pdfHash:s.p.pdfHash});},'FORM_READBACK_MISMATCH'],
 ['wrong-draft-content',s=>{s.a.readExistingDraft=async()=>({id:s.p.nativeDraftId,caseId:s.p.caseId,profile:s.p.profile,contentHash:'wrong',evidenceHash:s.p.evidenceHash});},'DRAFT_READBACK_MISMATCH'],
 ['uncertain-save-no-retry',s=>{s.rows.set(s.p.operationId,{identity:JSON.stringify([s.p.profile,s.p.caseId]),contentHash:s.p.contentHash,state:'uncertain'});},'UNCERTAIN_REQUIRES_REVIEW'],
 ['journal-foreign-case',s=>{s.rows.set(s.p.operationId,{identity:'other',contentHash:s.p.contentHash});},'JOURNAL_IDENTITY_MISMATCH']
])await test(name,async()=>{const s=setup();mutate(s);await assert.rejects(s.run(),new RegExp(error));if(name==='upload-403-no-confirm')assert.deepEqual(s.calls,['upload']);if(name==='uncertain-save-no-retry')assert.deepEqual(s.calls,[]);});
await test('lease-lost-between-upload-confirm',async()=>{const s=setup();s.a.uploadExistingForm=async()=>{s.calls.push('upload');s.guard.assertLease=async()=>{throw Error('STALE_LEASE');};return {ok:true};};await assert.rejects(s.run(),/STALE_LEASE/);assert.deepEqual(s.calls,['upload']);});
await test('resume-only-reconciles-no-new-write',async()=>{const s=setup();await s.run();s.calls.length=0;s.a.reconcile=async()=>({contentHash:s.p.contentHash,caseId:s.p.caseId,profile:s.p.profile,formId:s.p.nativeFormId,draftId:s.p.nativeDraftId,fieldHash:s.p.fieldHash,pdfHash:s.p.pdfHash,evidenceHash:s.p.evidenceHash});assert.equal((await s.run()).reconciled,true);assert.equal(s.calls.length,0);});
const output={passed:results.length,total:results.length,results,limitations:'All native transport responses simulated. Existing form/draft IDs only; creation, snapshot mutation, send, QS and signature are not implemented. No actual Claims write.'};
console.log(JSON.stringify(output));
