// Bounded orchestration for an EXISTING native draft. UI transport is injected;
// success requires a second document load, not merely an acknowledged save.
const uuid=/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i;
const protectedFields=new Set(['insurerClaimId','insurerPolicyId','Gesamt','Gesamt Freigabe','Gesamt Restreserve','Endergebnis']);
export function validateNativePatch(request,snapshot){
  if(!uuid.test(request.operationId)||!uuid.test(request.caseId)||!uuid.test(request.formId)
    ||!['christian','jens'].includes(request.profile)||typeof request.claimNumber!=='string'||!request.claimNumber.trim())throw Error('NATIVE_CONTEXT_INVALID');
  if(snapshot.caseId!==request.caseId||snapshot.formId!==request.formId||snapshot.profile!==request.profile
    ||snapshot.claimNumber!==request.claimNumber)throw Error('NATIVE_IDENTITY_MISMATCH');
  if(snapshot.status!=='draft'||snapshot.dirty!==false)throw Error('NATIVE_NOT_CLEAN_DRAFT');
  if(!/^[a-f0-9]{64}$/.test(request.expectedHash)||snapshot.hash!==request.expectedHash)throw Error('NATIVE_STALE_SNAPSHOT');
  if(!Array.isArray(request.changes)||!request.changes.length||request.changes.length>100)throw Error('NATIVE_CHANGES_INVALID');
  const seen=new Set();
  for(const c of request.changes){
    if(!c||typeof c.field!=='string'||typeof c.before!=='string'||typeof c.after!=='string'||c.after.length>10000||seen.has(c.field))throw Error('NATIVE_CHANGE_INVALID');
    seen.add(c.field);
    const fields=snapshot.fields.filter(f=>f.id===c.field);
    if(protectedFields.has(c.field)||fields.length!==1||fields[0].writable!==true)throw Error('NATIVE_FIELD_NOT_WRITABLE');
    if(fields[0].value!==c.before)throw Error('NATIVE_FIELD_CONFLICT');
    if(fields[0].options&&!fields[0].options.includes(c.after))throw Error('NATIVE_OPTION_INVALID');
  }
}
export async function executeNativeDraft(request,io){
  // io.withLock must serialize per authenticated account across all forms.
  return io.withLock(request.profile,async()=>{
    const existing=await io.journal.get(request.operationId);
    const fingerprint=await io.hash(request);
    if(existing){
      if(existing.fingerprint!==fingerprint)throw Error('NATIVE_IDEMPOTENCY_CONFLICT');
      if(existing.state==='verified')return existing.result;
      // A crash after field entry/save must NEVER replay blindly.
      throw Error('NATIVE_PRIOR_ATTEMPT_REQUIRES_READBACK');
    }
    await io.assertAccount(request.profile);
    const before=await io.openAndRead(request);
    validateNativePatch(request,before);
    await io.journal.set(request.operationId,{state:'started',fingerprint});
    try{
      await io.assertAccount(request.profile);
      await io.apply(request,before);
      await io.assertAccount(request.profile);
      await io.save(request);
      const after=await io.reopenAndRead(request);
      await io.assertAccount(request.profile);
      if(after.caseId!==before.caseId||after.formId!==before.formId||after.profile!==before.profile||after.claimNumber!==before.claimNumber||after.dirty!==false)throw Error('NATIVE_READBACK_IDENTITY_OR_DIRTY');
      for(const c of request.changes){
        const matches=after.fields.filter(f=>f.id===c.field);
        if(matches.length!==1||matches[0].value!==c.after)throw Error('NATIVE_READBACK_MISMATCH');
      }
      for(const f of before.fields){
        if(request.changes.some(c=>c.field===f.id)||f.calculated===true)continue;
        const matches=after.fields.filter(a=>a.id===f.id);
        if(matches.length!==1||matches[0].value!==f.value)throw Error('NATIVE_UNREQUESTED_CHANGE');
      }
      const result={verified:true,operationId:request.operationId,caseId:request.caseId,formId:request.formId,beforeHash:before.hash,after,dispatch:false,qs:false,requiresDocumentReview:true};
      await io.journal.set(request.operationId,{state:'verified',fingerprint,result});
      return result;
    }catch(error){
      await io.journal.set(request.operationId,{state:'uncertain',fingerprint,error:String(error.message)});
      throw error;
    }
  });
}
