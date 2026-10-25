// Adapter for the existing Claimsforce LegacyInvestigationClient contract.
// No credentials, endpoints, new reports, QA or dispatch are created here.
import { planNativeContent } from './native-content-plan.mjs';

function canonical(value) {
  if (Array.isArray(value)) return '[' + value.map(canonical).join(',') + ']';
  if (value && typeof value === 'object') return '{' + Object.keys(value).sort().map(k => JSON.stringify(k) + ':' + canonical(value[k])).join(',') + '}';
  return JSON.stringify(value);
}
export async function reportFingerprint(value) {
  const data = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(canonical(value)));
  return Array.from(new Uint8Array(data), x => x.toString(16).padStart(2, '0')).join('');
}
const uuid = value => typeof value === 'string' && /^[a-f\d]{8}(?:-[a-f\d]{4}){3}-[a-f\d]{12}$/i.test(value);
export async function updateNativeReportDraft(command, adapter) {
  const { profile, claimId, investigationId, operationId, expectedFingerprint, changes } = command;
  if (!profile || !uuid(claimId) || !uuid(investigationId) || !uuid(operationId) || !/^[a-f\d]{64}$/.test(expectedFingerprint || '')) throw Error('INVALID_NATIVE_COMMAND');
  if (!adapter.withCaseLock || !adapter.read || !adapter.patch || !adapter.journal || !adapter.assertIdentity) throw Error('INCOMPLETE_NATIVE_ADAPTER');
  const identity = { profile, claimId, investigationId };
  const commandHash = await reportFingerprint(command);
  return adapter.withCaseLock(profile, claimId, async () => {
    await adapter.assertIdentity(identity);
    const current = await adapter.read(identity);
    if (current.claimId !== claimId || current.id !== investigationId || current.profile !== profile) throw Error('NATIVE_IDENTITY_MISMATCH');
    const prior = await adapter.journal.load(operationId);
    const fingerprint = await reportFingerprint({ content: current.content, evidence: current.evidence });
    if (prior) {
      if (prior.commandHash !== commandHash) throw Error('NATIVE_OPERATION_REUSED');
      if (prior.expectedResult !== fingerprint) throw Error('UNCERTAIN_NATIVE_WRITE_REQUIRES_REVIEW');
      return { verified: true, reconciled: true, ...identity, fingerprint };
    }
    if (current.isLocked || current.status !== 'IN_PROGRESS') throw Error('NATIVE_REPORT_NOT_EDITABLE');
    if (fingerprint !== expectedFingerprint) throw Error('NATIVE_REPORT_CHANGED');
    const plan = planNativeContent(current.content, current.template?.definition, changes);
    const payload = { content: plan.content, evidence: structuredClone(current.evidence) };
    const expectedResult = await reportFingerprint(payload);
    const preflight = await adapter.read(identity);
    if (preflight.claimId !== claimId || preflight.id !== investigationId || preflight.profile !== profile || preflight.isLocked || preflight.status !== 'IN_PROGRESS'
      || await reportFingerprint({content:preflight.content,evidence:preflight.evidence}) !== fingerprint) throw Error('NATIVE_REPORT_CHANGED_BEFORE_WRITE');
    // Persist before transport. An interrupted PATCH is reconciled, never blindly repeated.
    await adapter.journal.save(operationId, { commandHash, expectedResult, state: 'uncertain', identity });
    await adapter.assertIdentity(identity);
    await adapter.patch(identity, payload);
    const saved = await adapter.read(identity);
    const savedFingerprint = await reportFingerprint({ content: saved.content, evidence: saved.evidence });
    if (saved.claimId !== claimId || saved.id !== investigationId || saved.profile !== profile || savedFingerprint !== expectedResult) throw Error('NATIVE_READBACK_MISMATCH');
    await adapter.journal.save(operationId, { commandHash, expectedResult, state: 'verified', identity });
    return { verified: true, reconciled: false, ...identity, fingerprint: savedFingerprint };
  });
}
