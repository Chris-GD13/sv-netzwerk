// Read-only preflight for existing bridge consumers. No import/upload or case writes.
export function compareNativeFields(expected, stored, current) {
 for(const k of ['caseId','profile','formId'])if(typeof expected?.[k]!=='string'||!expected[k].trim())throw Error('FRESHNESS_EXPECTED_IDENTITY_REQUIRED');
 for(const doc of [stored,current]) {
  if(doc?.caseId!==expected.caseId||doc?.profile!==expected.profile||doc?.formId!==expected.formId)throw Error('FRESHNESS_IDENTITY_MISMATCH');
  if(!/^[a-f0-9]{64}$/.test(doc.sha256)||!doc.fields||typeof doc.fields!=='object'||Array.isArray(doc.fields))throw Error('FRESHNESS_UNVERIFIED');
 }
 const changedFields=[...new Set([...Object.keys(stored.fields),...Object.keys(current.fields)])].filter(k=>JSON.stringify(stored.fields[k]??null)!==JSON.stringify(current.fields[k]??null)).sort();
 return {sameBytes:stored.sha256===current.sha256,changedFields,fieldsMatch:changedFields.length===0,
  status:changedFields.length?'STALE_NATIVE_FIELDS':stored.sha256===current.sha256?'IDENTICAL':'APPEARANCE_OR_PACKAGE_REVIEW_REQUIRED',
  paidStatus:'not established by this comparison'};
}
