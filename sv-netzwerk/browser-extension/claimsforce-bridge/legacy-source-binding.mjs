// Pure migration preflight. No filename fallback and no metadata writes.
export function planLegacySourceBinding(expected, context, original, inventory) {
  const missing=['claimId','profile','accessProofHash'].filter(k=>typeof context?.[k]!=='string'||!context[k]);
  if(context?.verified!==true||missing.length)return {status:'TRUSTED_CASE_CONTEXT_REQUIRED',missing,writeAllowed:false};
  if(context.claimId!==expected.claimId||context.profile!==expected.profile||original.claimId!==expected.claimId||original.profile!==expected.profile||original.sourceFileId!==expected.sourceFileId)throw Error('LEGACY_BINDING_IDENTITY_MISMATCH');
  if(!/^[a-f0-9]{64}$/.test(context.accessProofHash)||!original.verified||!/^[a-f0-9]{64}$/.test(original.sha256))throw Error('LEGACY_BINDING_EVIDENCE_REQUIRED');
  const physical=new Map();
  for(const item of inventory){
    if(item.claimId!==expected.claimId||item.profile!==expected.profile)throw Error('LEGACY_INVENTORY_SCOPE_MISMATCH');
    if(!item.portalFileId||!item.verified||!/^[a-f0-9]{64}$/.test(item.sha256))throw Error('LEGACY_INVENTORY_UNVERIFIED');
    if(physical.has(item.portalFileId)&&physical.get(item.portalFileId).sha256!==item.sha256)throw Error('LEGACY_INVENTORY_VERSION_CONFLICT');
    physical.set(item.portalFileId,item);
  }
  const same=[...physical.values()].filter(x=>x.sha256===original.sha256);
  if(!same.length)return {status:'PRIOR_VERSION_BINDING_PROOF_REQUIRED',writeAllowed:false};
  if(same.length!==1)return {status:'AMBIGUOUS_LEGACY_COPIES',candidateIds:same.map(x=>x.portalFileId),writeAllowed:false};
  return {status:'EXACT_BYTES_BINDING_PLAN',portalFileId:same[0].portalFileId,sourceFileId:original.sourceFileId,claimId:context.claimId,profile:context.profile,sha256:original.sha256,accessProofHash:context.accessProofHash,preserveExistingId:true,writeAllowed:false,requiresExistingRecordRevisionBeforeAnyFutureWrite:true};
}
