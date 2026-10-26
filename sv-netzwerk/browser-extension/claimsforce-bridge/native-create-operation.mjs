// A lost response must never cause a second native form to be created.
// The adapter owns the observed original UI and a persistent case lock.
export async function createNativeDraft(command, io) {
  const uuid = v => typeof v === 'string' && /^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i.test(v);
  if (!uuid(command.operationId) || !uuid(command.caseId) || !command.profile ||
      !command.claimNumber || !command.templateName || !/^[a-f0-9]{64}$/.test(command.expectedHash || '')) throw Error('NATIVE_CREATE_INVALID');
  const identity = {profile:command.profile,caseId:command.caseId,claimNumber:command.claimNumber};
  const commandHash = await io.hash(command);
  return io.withCaseLock(identity, async () => {
    await io.assertIdentity(identity);
    const prior = await io.journal.get(command.operationId);
    if (prior && prior.commandHash !== commandHash) throw Error('NATIVE_CREATE_OPERATION_CONFLICT');
    if (prior) {
      // Reconcile only the exact returned ID. Never adopt an unrelated concurrent draft.
      if (!prior.formId) throw Error('NATIVE_CREATE_UNCERTAIN_REVIEW_REQUIRED');
      const saved = await io.readDraft({...identity,formId:prior.formId});
      verify(saved, identity, command.templateName, prior.formId);
      await io.journal.set(command.operationId,{...prior,state:'verified'});
      return {created:true,verified:true,reconciled:true,formId:prior.formId,snapshot:saved};
    }
    const before = await io.inventory(identity);
    if (!before.ready || before.hash !== command.expectedHash) throw Error('NATIVE_CREATE_STALE_INVENTORY');
    if (before.forms.some(f => f.status === 'unknown')) throw Error('NATIVE_CREATE_STATUS_UNKNOWN');
    if (before.forms.some(f => f.templateName === command.templateName && f.status === 'draft')) throw Error('NATIVE_CREATE_DRAFT_EXISTS');
    if (before.templates.filter(t => t === command.templateName).length !== 1) throw Error('NATIVE_CREATE_TEMPLATE_UNAVAILABLE');
    await io.assertIdentity(identity);
    const preflight = await io.inventory(identity);
    if (!preflight.ready || preflight.hash !== before.hash) throw Error('NATIVE_CREATE_CHANGED_BEFORE_WRITE');
    // Durable BEFORE clicking. Even a failed click acknowledgement is uncertain.
    await io.journal.set(command.operationId,{commandHash,state:'uncertain',identity});
    const result = await io.createFromTemplate({...identity,templateName:command.templateName});
    if (!uuid(result?.formId) || before.forms.some(f => f.formId === result.formId)) throw Error('NATIVE_CREATE_ID_UNVERIFIED');
    await io.journal.set(command.operationId,{commandHash,state:'uncertain',identity,formId:result.formId});
    const saved = await io.readDraft({...identity,formId:result.formId});
    verify(saved, identity, command.templateName, result.formId);
    await io.journal.set(command.operationId,{commandHash,state:'verified',identity,formId:result.formId});
    return {created:true,verified:true,reconciled:false,formId:result.formId,snapshot:saved};
  });
}
function verify(saved, identity, templateName, formId) {
  if (!saved || saved.caseId !== identity.caseId || saved.profile !== identity.profile ||
      saved.claimNumber !== identity.claimNumber || saved.formId !== formId ||
      saved.templateName !== templateName || saved.status !== 'draft' || saved.dirty !== false)
    throw Error('NATIVE_CREATE_READBACK_MISMATCH');
}
