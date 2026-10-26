// Isolated acceptance-test contract, NOT wired to production writes.
// Values must come from verified, case-specific mappings of the original forms.
// Equality proves consistency only, never entitlement or actual payment.
export const moneyKeys = ['newValue', 'deductible', 'paidVN', 'directPayments', 'compensation'];
const cents = value => Number.isSafeInteger(value) && value >= 0;
export function reconcileDocumentCents({caseId, expected, requiredKinds, snapshots} = {}) {
  const errors = [];
  if (!caseId || !expected || !moneyKeys.every(key => cents(expected[key]))) errors.push('INVALID_EXPECTED_CENTS');
  if (!Array.isArray(requiredKinds) || !requiredKinds.length || new Set(requiredKinds).size !== requiredKinds.length
      || requiredKinds.some(kind => !['SE', 'ZB', 'report'].includes(kind))) errors.push('INVALID_DOCUMENT_REQUIREMENTS');
  if (!Array.isArray(snapshots)) errors.push('MISSING_DOCUMENT_READBACKS');
  if (errors.length) return {ok:false, errors};
  for (const kind of requiredKinds) {
    const matching = snapshots.filter(row => row.kind === kind && row.caseId === caseId);
    if (matching.length !== 1) { errors.push(`${kind}:MISSING_OR_DUPLICATE_CASE_SNAPSHOT`); continue; }
    const row = matching[0];
    if (!row.documentId || !/^[a-f0-9]{64}$/.test(row.sha256 || '') || row.readBackVerified !== true
        || row.mappingVerified !== true || row.current !== true) errors.push(`${kind}:UNVERIFIED_READBACK_OR_MAPPING`);
    if (kind !== 'report' && row.native !== true) errors.push(`${kind}:NATIVE_ORIGINAL_REQUIRED`);
    for (const key of moneyKeys) {
      if (!cents(row.values?.[key])) errors.push(`${kind}:${key}:MISSING_OR_INVALID_CENTS`);
      else if (row.values[key] !== expected[key]) errors.push(`${kind}:${key}:CENT_MISMATCH`);
    }
  }
  return {ok:errors.length === 0, errors};
}
