import {engelPaymentTableGate} from './engel-report-qs.mjs';
// Isolated extension candidate. No network, model, browser writes, or production registration.
export function viewerState(o) {
  if (o.loginRequired) return 'authentication-required';
  if (o.identityMismatch) return 'identity-unverified';
  if (o.observationBlocked) return 'observation-blocked';
  if (o.failedAsset) return 'asset-failed';
  if (!o.outerLoaded) return 'page-loading';
  if (!o.viewerInitialized) return 'viewer-loading';
  // A rendered canvas or an outer tab load is not proof of editable form fields.
  if (!o.fieldsReadable || !o.fieldsEditable) return 'form-unverified';
  if (!o.identityVerified) return 'identity-unverified';
  return 'ready';
}

export async function waitForViewer(observe, {clock, pause, timeoutMs = 5000, intervalMs = 100}) {
  const start = clock();
  const transitions = [];
  for (;;) {
    let state, timer;
    try {
      const remaining = Math.max(1, timeoutMs - (clock() - start));
      const observation = await Promise.race([
        Promise.resolve().then(observe),
        new Promise((_, reject) => { timer = setTimeout(() => reject(Error('VIEWER_OBSERVATION_TIMEOUT')), remaining); })
      ]);
      state = viewerState(observation);
    }
    catch { state = 'observation-blocked'; }
    finally { clearTimeout(timer); }
    const elapsedMs = clock() - start;
    if (transitions.at(-1)?.state !== state) transitions.push({state, elapsedMs});
    if (state === 'ready') return {ok:true, state, elapsedMs, transitions};
    if (['authentication-required','observation-blocked','asset-failed','identity-unverified'].includes(state)) {
      return {ok:false, state, elapsedMs, transitions};
    }
    if (elapsedMs >= timeoutMs) return {ok:false, state:'timeout', lastState:state, elapsedMs, transitions};
    await pause(Math.min(intervalMs, timeoutMs - elapsedMs));
  }
}

const cents = n => Number.isSafeInteger(n) && n >= 0;
const same = (a,b) => JSON.stringify(a) === JSON.stringify(b);
export function qualityGate(p) {
  const errors = [];
  const fail = (code, condition) => { if (!condition) errors.push(code); };
  fail('IDENTITY', !!p.caseId && !!p.profile && same(p.expectedIdentity,p.observedIdentity)
    && p.expectedIdentity?.caseId === p.caseId && p.expectedIdentity?.profile === p.profile);
  fail('OWNERSHIP', p.authorized === true);
  const sources = new Set(p.sources?.map(s=>s.id));
  fail('SOURCES', !!sources.size && sources.size === p.sources?.length
    && p.sources.every(s=>s.readable === true && s.caseId === p.caseId && s.profile === p.profile));
  fail('FACT_EVIDENCE', p.facts?.length > 0 && p.facts.every(f=>f.sourceIds?.length && f.sourceIds.every(id=>sources.has(id))));
  fail('REQUIRED_SOURCES', p.requiredSources?.every(id=>sources.has(id)) === true);
  const lines = p.positions || [];
  fail('POSITIONS', lines.length > 0 && new Set(lines.map(l=>l.id)).size === lines.length);
  fail('AMOUNTS', lines.every(l=>l.caseId === p.caseId && [l.netCents,l.vatCents,l.grossCents].every(cents)
    && l.netCents+l.vatCents === l.grossCents && sources.has(l.sourceId)));
  // Canonical position ledger: invoice replaces estimate; exposure never adds reserve twice.
  fail('KVA_REPLACEMENT', lines.every(l=>!l.replaces || !lines.some(old=>old.id === l.replaces && old.included && l.included)));
  const included = lines.filter(l=>l.included);
  fail('POSITION_OVERLAP_REVIEW', p.overlapReviewed === true);
  fail('DUPLICATE_INVOICE_POSITION', new Set(included.map(l=>JSON.stringify([l.sourceId,l.sourcePositionId]))).size === included.length
    && included.every(l=>!!l.sourcePositionId));
  const exposureCents = included.reduce((sum,l)=>sum+l.grossCents,0);
  fail('EXPOSURE', cents(exposureCents) && p.exposureCents === exposureCents);
  const payments = p.payments || [];
  fail('PAYMENT_EVIDENCE', payments.every(v=>cents(v.cents) && v.status === 'paid'
    && p.sources?.some(s=>s.id === v.sourceId && s.kind === 'payment-confirmation' && s.verified === true))
    && new Set(payments.map(v=>v.id)).size === payments.length);
  fail('DEDUCTIBLE', cents(p.deductibleCents) && p.deductibleApplications === 1);
  const paidCents = payments.reduce((sum,v)=>sum+v.cents,0);
  fail('BALANCE', p.remainingCents === Math.max(0,exposureCents-p.deductibleCents-paidCents));
  const docs = p.documents || [];
  fail('ATTACHMENT_HASHES', docs.length > 0 && docs.every(d=>d.hash && d.sourceIds?.length && d.sourceIds.every(id=>sources.has(id)))
    && new Set(docs.map(d=>d.hash)).size === docs.length);
  let reportingExposureCents = exposureCents;
  if (p.reportingGroup) {
    const g=p.reportingGroup, members=g.claims || [];
    fail('LINKED_CLAIMS_EVIDENCE', !!g.id && g.relationVerified === true && g.profile === p.profile
      && members.length > 1 && new Set(members.map(m=>m.caseId)).size === members.length
      && members.every(m=>m.profile === p.profile && m.evidenceVerified === true && !!m.evidenceHash && cents(m.exposureCents))
      && members.some(m=>m.caseId === p.caseId && m.exposureCents === exposureCents));
    reportingExposureCents = members.reduce((sum,m)=>sum+m.exposureCents,0);
    fail('LINKED_CLAIMS_TOTAL', cents(reportingExposureCents));
  }
  // Group exposure drives reporting only. The SE balance above remains strictly case-specific.
  fail('SE_CASE_SEPARATION', docs.filter(d=>d.kind === 'SE').every(d=>d.caseId === p.caseId
    && d.positionCaseIds?.length === 1 && d.positionCaseIds[0] === p.caseId));
  const paymentForms=docs.filter(d=>d.kind === 'Zahlungsbefürwortung');
  fail('ZB_CASE_SEPARATION', paymentForms.every(d=>d.caseId === p.caseId
    && d.positionCaseIds?.length === 1 && d.positionCaseIds[0] === p.caseId));
  fail('ZB_NATIVE_CURRENT', paymentForms.every(d=>d.native === true && d.contentChecked === true && d.current === true));
  // Linked building/household claims share reporting, never their own forms.
  // A ZB is a Zahlungsbefuerwortung, not an obsolete Zwischenbericht.
  if(p.reportingGroup || p.zbRequired === true)fail('REQUIRED_NATIVE_ZB',paymentForms.length === 1);
  const gfReportRequired = p.gf === true && reportingExposureCents >= 2500000;
  if (gfReportRequired) {
    const engel=engelPaymentTableGate(p.engelPaymentTable,p.sources);
    errors.push(...engel.errors);
    fail('ENGEL_PAID_LEDGER_BALANCE',engel.ok && engel.paidCents===paidCents);
    fail('ENGEL_TABLE_REPORT_BINDING', docs.some(d=>d.kind==='report' && d.hash===p.engelPaymentTable?.reportHash));
    fail('GF_WORD_REPORT', docs.some(d=>d.kind === 'report' && d.format === 'docx' && d.folder === 'Berichte'
      && /^Zwischenbericht Nr\. [1-9][0-9]*$/.test(d.title) && d.contentChecked === true));
    fail('GF_NATIVE_SE', docs.some(d=>d.kind === 'SE' && d.native === true && d.contentChecked === true));
  }
  fail('EXTERNAL_TEXT_REVIEW', p.externalTextReviewed === true && p.containsInternalNotes === false);
  fail('DRAFT_ONLY', p.operation === 'prepare-draft');
  return {ok:errors.length === 0, errors, exposureCents, reportingExposureCents, paidCents, gfReportRequired};
}

// Caller must supply durable compare-and-swap storage before live use. Test adapter is offline only.
export class DraftJournal {
  constructor(store, now = ()=>new Date().toISOString()) { this.store=store; this.now=now; }
  async prepare(plan) {
    const qs = qualityGate(plan);
    if (!qs.ok) throw new Error(`QS_BLOCK:${qs.errors.join(',')}`);
    const key = JSON.stringify([plan.profile,plan.caseId,plan.reportType,plan.operationId]);
    const prior = await this.store.get(key);
    if (prior) {
      if (prior.contentHash !== plan.contentHash) throw new Error('CONTENT_CHANGED_REQUIRES_NEW_REVIEW');
      return prior;
    }
    if (!plan.operationId || !plan.contentHash || !plan.reportType) throw new Error('MISSING_OPERATION_IDENTITY');
    const row = {key, profile:plan.profile, caseId:plan.caseId, reportType:plan.reportType,
      operationId:plan.operationId, contentHash:plan.contentHash, state:'awaiting-approval', updatedAt:this.now()};
    await this.store.putIfAbsent(key,row);
    return await this.store.get(key);
  }
  async simulateSave(row, adapter) {
    if (adapter.offlineOnly !== true) throw new Error('PRODUCTION_WRITES_DISABLED');
    row = await this.store.get(row.key);
    if (!row) throw new Error('JOURNAL_MISSING');
    // Always reconcile even after an uncertain save. Never blindly create again.
    const found = await adapter.find(row.operationId);
    if (found) return this.verify(row,found);
    if (row.state === 'save-uncertain' || row.state === 'verified') throw new Error('RECONCILIATION_REQUIRED');
    row = {...row,state:'save-uncertain',updatedAt:this.now()};
    await this.store.set(row.key,row);
    await adapter.save(row);
    const readback = await adapter.find(row.operationId);
    if (!readback) throw new Error('READBACK_MISSING');
    return this.verify(row,readback);
  }
  async verify(row,found) {
    if (['profile','caseId','reportType','operationId','contentHash'].some(k=>found[k] !== row[k])) throw new Error('READBACK_MISMATCH');
    if (!found.nativeId) throw new Error('READBACK_NATIVE_ID_MISSING');
    const result={...row,state:'verified',nativeId:found.nativeId,updatedAt:this.now()};
    await this.store.set(row.key,result);
    return result;
  }
}
