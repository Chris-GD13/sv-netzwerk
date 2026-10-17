// Evidence adapter for structured native SE fields. Never infer money from descriptive prose.
export function moneyCents(value) {
 if(value == null || String(value).trim()==='') return null;
 let s=String(value).replace(/\s|€/g,'');
 if (/^\d{1,3}(?:\.\d{3})*,\d{2}$/.test(s)||/^\d+,\d{2}$/.test(s)) s=s.replaceAll('.','').replace(',','.');
 else if(!/^\d+(?:\.\d{2})?$/.test(s)) throw new Error('AMBIGUOUS_MONEY');
 const [whole,fraction='00']=s.split('.');const n=Number(whole)*100+Number(fraction);
 if(!Number.isSafeInteger(n)||n<0)throw new Error('INVALID_MONEY');return n;
}
export function importNativeSE(evidence, expected) {
 if(!evidence.sha256||!evidence.id||!evidence.fields)throw new Error('SOURCE_IDENTITY_MISSING');
 const fields=evidence.fields;
 const normal=n=>String(n||'').replace(/\s+GF$/i,'').trim();
 if(normal(fields.insurerClaimId)!==normal(expected.caseNumber))throw new Error('CASE_MISMATCH');
 const rows=[];
 for(const [key,value] of Object.entries(fields)){
  const m=key.match(/^GesamtsummeRow(\d+)$/);if(!m)continue;
  const amount=moneyCents(value);if(amount===null)continue;
  const label=fields[`Position ${m[1]}`];if(!label?.trim())throw new Error('AMOUNT_WITHOUT_POSITION');
  rows.push({caseNumber:expected.caseNumber,sourceId:evidence.id,sourceHash:evidence.sha256,
   locator:`field:${key}`,position:Number(m[1]),label,amountCents:amount,status:'reported-in-SE'});
 }
 const total=moneyCents(fields.Neuwert_Schaden);
 const sum=rows.reduce((n,r)=>n+r.amountCents,0);
 if(total===null||sum!==total)throw new Error('SE_TOTAL_MISMATCH');
 const rawDirect=fields.Ergebnis_Direktzahlungen;
 return {caseNumber:expected.caseNumber,profile:expected.profile,rows,totalCents:total,
  reportedDirectAllocationCents:moneyCents(rawDirect),directAllocationStatus:'not-proof-of-payment',
  deductibleCents:moneyCents(fields.Ergebnis_Selbstbehalt),partialPaymentsCents:moneyCents(fields.Ergebnis_Teilzahlungen),
  remainingCents:moneyCents(fields['Neuwert Entschädigung']),confirmedPayments:[],
  paymentCompleteness:'unverified',sourceId:evidence.id,sourceHash:evidence.sha256};
}
export function validateLinkedEvidence(group,claims) {
 if(!group?.relationSourceId||!group.relationSourceHash||group.relationVerified!==true)throw new Error('LINK_PROOF_REQUIRED');
 const keys=claims.map(c=>c.caseNumber);
 if(keys.length<2||new Set(keys).size!==keys.length||claims.some(c=>c.profile!==group.profile))throw new Error('LINK_SCOPE_MISMATCH');
 if(keys.some(k=>!group.explicitCaseNumbers?.includes(k))||group.explicitCaseNumbers.length!==keys.length)throw new Error('LINK_NUMBERS_NOT_EXPLICIT');
 return {reportingExposureCents:claims.reduce((n,c)=>n+c.totalCents,0),seByCase:claims.map(c=>({caseNumber:c.caseNumber,totalCents:c.totalCents}))};
}
