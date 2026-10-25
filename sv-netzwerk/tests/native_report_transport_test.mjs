import assert from 'node:assert/strict';
import {updateNativeReportDraft,reportFingerprint} from '../browser-extension/claimsforce-bridge/native-report-transport.mjs';
// Synthetic data only: this test does not contact Claimsforce.
const claimId='11111111-1111-4111-8111-111111111111',investigationId='22222222-2222-4222-8222-222222222222';
const original={profile:'christian',claimId,id:investigationId,status:'IN_PROGRESS',isLocked:false,content:{damage:{description:''}},evidence:[{id:'original-evidence',type:'document'}],template:{definition:{properties:{damage:{properties:{description:{type:'string'}}}}}}};
const command={profile:'christian',claimId,investigationId,operationId:'33333333-3333-4333-8333-333333333333',expectedFingerprint:await reportFingerprint({content:original.content,evidence:original.evidence}),changes:[{path:['damage','description'],before:'',after:'Synthetic test',sourceId:'test',sourceHash:'synthetic',locator:'page 1'}]};
function fixture(){
 let row=structuredClone(original),writes=0,tail=Promise.resolve();const entries=new Map();
 const adapter={assertIdentity:async identity=>{assert.equal(identity.claimId,claimId);},read:async()=>structuredClone(row),patch:async(identity,payload)=>{writes++;Object.assign(row,structuredClone(payload));},journal:{load:async id=>entries.get(id),save:async(id,value)=>entries.set(id,value)},withCaseLock:async(profile,id,fn)=>{const previous=tail;let release;tail=new Promise(r=>release=r);await previous;try{return await fn();}finally{release();}}};
 return {adapter,get row(){return row},set row(v){row=v},get writes(){return writes}};
}
let f=fixture();let results=await Promise.all([updateNativeReportDraft(command,f.adapter),updateNativeReportDraft(command,f.adapter)]);
assert.equal(f.writes,1);assert.equal(results[1].reconciled,true);assert.deepEqual(f.row.evidence,original.evidence);
await assert.rejects(()=>updateNativeReportDraft({...command,changes:[{...command.changes[0],after:'Different'}]},f.adapter),/OPERATION_REUSED/);
f=fixture();f.row.content.damage.description='Human edit';await assert.rejects(()=>updateNativeReportDraft(command,f.adapter),/REPORT_CHANGED/);assert.equal(f.writes,0);
f=fixture();f.row.status='SENT';await assert.rejects(()=>updateNativeReportDraft(command,f.adapter),/NOT_EDITABLE/);assert.equal(f.writes,0);
f=fixture();f.row.claimId='44444444-4444-4444-8444-444444444444';await assert.rejects(()=>updateNativeReportDraft(command,f.adapter),/IDENTITY_MISMATCH/);assert.equal(f.writes,0);
f=fixture();const realPatch=f.adapter.patch;f.adapter.patch=async(i,p)=>{await realPatch(i,p);throw Error('connection lost after save')};await assert.rejects(()=>updateNativeReportDraft(command,f.adapter),/connection lost/);assert.equal((await updateNativeReportDraft(command,f.adapter)).reconciled,true);assert.equal(f.writes,1);
f=fixture();f.adapter.patch=async()=>{};await assert.rejects(()=>updateNativeReportDraft(command,f.adapter),/READBACK_MISMATCH/);await assert.rejects(()=>updateNativeReportDraft(command,f.adapter),/UNCERTAIN/);
console.log('Native report adapter: parallel deduplication, evidence preservation, operation binding, human edits, sent reports, case isolation, uncertain-save reconciliation and readback failure tested (synthetic, no live save).');
