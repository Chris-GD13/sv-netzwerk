// Isolated orchestration for the existing bridge. No fetch or live transport.
import {qualityGate} from './draft-agent-core.mjs';
export async function simulateNativeDraft(plan, adapter, guard, journal, now=()=>Date.now()) {
 if(adapter.offlineOnly!==true)throw Error('LIVE_TRANSPORT_DISABLED');
 if(!plan.nativeFormId||!plan.nativeDraftId||!plan.fieldHash||!plan.pdfHash||!plan.evidenceHash||!plan.sourceVersion)throw Error('NATIVE_TARGET_OR_VERSION_MISSING');
 if(!qualityGate(plan).ok)throw Error('QS_BLOCK');
 const identity=JSON.stringify([plan.profile,plan.caseId]);
 const check=async()=>{
  await guard.assertLease();
  const current=await adapter.inspect();
  if(JSON.stringify([current.profile,current.caseId])!==identity)throw Error('IDENTITY_CHANGED');
  if(current.sourceVersion!==plan.sourceVersion)throw Error('SOURCE_CHANGED');
  if(!plan.approval||plan.approval.expires<=now()||plan.approval.contentHash!==plan.contentHash
    ||plan.approval.caseId!==plan.caseId||plan.approval.profile!==plan.profile)throw Error('APPROVAL_INVALID');
 };
 await check();
 // A crash after any uncertain stage requires readback, never blind replay.
 let state=await journal.load(plan.operationId);
 if(state) {
  if(state.identity!==identity||state.contentHash!==plan.contentHash)throw Error('JOURNAL_IDENTITY_MISMATCH');
  const result=await adapter.reconcile(plan.operationId);
  if(!result||result.contentHash!==plan.contentHash||result.caseId!==plan.caseId||result.profile!==plan.profile
    ||result.formId!==plan.nativeFormId||result.draftId!==plan.nativeDraftId||result.fieldHash!==plan.fieldHash
    ||result.pdfHash!==plan.pdfHash||result.evidenceHash!==plan.evidenceHash)throw Error('UNCERTAIN_REQUIRES_REVIEW');
  return {state:'verified',reconciled:true,result};
 }
 await journal.save(plan.operationId,{identity,contentHash:plan.contentHash,state:'uncertain'});
 await check();
 const upload=await adapter.uploadExistingForm(plan.nativeFormId);
 if(!upload?.ok)throw Error('UPLOAD_FAILED');
 await check();await adapter.confirmExistingForm(plan.nativeFormId);
 await check();const form=await adapter.readExistingForm(plan.nativeFormId);
 if(form?.id!==plan.nativeFormId||form?.caseId!==plan.caseId||form?.profile!==plan.profile
   ||form?.fieldHash!==plan.fieldHash||form?.pdfHash!==plan.pdfHash)throw Error('FORM_READBACK_MISMATCH');
 await check();const updated=await adapter.updateExistingDraft(plan.nativeDraftId,plan.contentHash);
 if(!updated?.ok)throw Error('DRAFT_UPDATE_FAILED');
 await check();const draft=await adapter.readExistingDraft(plan.nativeDraftId);
 if(draft?.id!==plan.nativeDraftId||draft?.contentHash!==plan.contentHash
   ||draft?.caseId!==plan.caseId||draft?.profile!==plan.profile||draft?.evidenceHash!==plan.evidenceHash)throw Error('DRAFT_READBACK_MISMATCH');
 await journal.save(plan.operationId,{identity,contentHash:plan.contentHash,state:'verified'});
 return {state:'verified',reconciled:false,result:draft};
}
