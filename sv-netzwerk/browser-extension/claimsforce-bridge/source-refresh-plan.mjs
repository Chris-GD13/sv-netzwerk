// Isolated plan only. Existing bridge/server adapters must supply verified bytes and CAS.
// No network, upload, document creation or production storage access.
const digest=/^[a-f0-9]{64}$/;
function identity(x) {
  for(const k of ['profile','caseId','sourceFileId'])if(typeof x?.[k]!=='string'||!x[k].trim())throw Error('REFRESH_IDENTITY_REQUIRED');
  return JSON.stringify([x.profile,x.caseId,x.sourceFileId]);
}
export function planSourceRefresh(expected,stored,current) {
  const key=identity(expected);
  if(identity(current)!==key||identity(stored)!==key)throw Error('REFRESH_IDENTITY_MISMATCH');
  if(current.originalVerified!==true||stored.originalVerified!==true||!digest.test(current.sha256)||!digest.test(stored.sha256))throw Error('REFRESH_CONTENT_UNVERIFIED');
  if(!Number.isSafeInteger(stored.revision)||stored.revision<1||!stored.portalFileId)throw Error('REFRESH_EXISTING_RECORD_REQUIRED');
  if(current.sha256===stored.sha256)return Object.freeze({action:'NOOP',key,portalFileId:stored.portalFileId,sha256:stored.sha256});
  if(current.readOnly!==true)throw Error('REFRESH_READ_ONLY_SOURCE_REQUIRED');
  return Object.freeze({action:'REPLACE_EXISTING_CONTENT',key,portalFileId:stored.portalFileId,
    expectedRevision:stored.revision,expectedSha256:stored.sha256,nextSha256:current.sha256,
    operationKey:JSON.stringify([key,stored.revision,stored.sha256,current.sha256]),
    preserve:['portalFileId','caseId','profile','sourceFileId','folder','annotations','reviewStatus','caseAssignment'],
    retainPriorVersion:true,requiresAtomicCompareAndSwap:true,requiresIndependentReadback:true,
    productionAdapter:'NOT_IMPLEMENTED'});
}
