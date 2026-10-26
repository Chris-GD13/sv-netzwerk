import assert from 'node:assert/strict';
import {reconcileDocumentCents, moneyKeys} from './support/document-cent-reconciliation.mjs';
// Wholly synthetic fixtures. Not a calculation or payment decision for a live claim.
function fixture() {
  const expected={newValue:100000,deductible:10000,paidVN:20000,directPayments:30000,compensation:40000};
  return {caseId:'synthetic-building',expected,requiredKinds:['SE','ZB','report'],
    snapshots:['SE','ZB','report'].map(kind=>({kind,caseId:'synthetic-building',documentId:kind,
      sha256:'a'.repeat(64),readBackVerified:true,mappingVerified:true,current:true,native:true,values:{...expected}}))};
}
let count=0;
const check=(label,mutate,ok=false)=>{const input=fixture();mutate(input);assert.equal(reconcileDocumentCents(input).ok,ok,label);count++;};
check('exact agreement',()=>{},true);
for(const kind of ['SE','ZB','report'])for(const field of moneyKeys) {
  check(`${kind} ${field} one cent`,p=>{p.snapshots.find(s=>s.kind===kind).values[field]++;});
  check(`${kind} ${field} absent`,p=>{delete p.snapshots.find(s=>s.kind===kind).values[field];});
}
check('ninety cent transcription',p=>{p.snapshots[0].values.paidVN+=90;});
check('missing ZB',p=>{p.snapshots=p.snapshots.filter(s=>s.kind!=='ZB');});
check('foreign case ZB',p=>{p.snapshots[1].caseId='synthetic-household';});
check('duplicate active SE',p=>{p.snapshots.push({...p.snapshots[0]});});
check('PDF is not native ZB',p=>{p.snapshots[1].native=false;});
check('unverified mapping',p=>{p.snapshots[0].mappingVerified=false;});
check('unverified readback',p=>{p.snapshots[2].readBackVerified=false;});
check('obsolete export',p=>{p.snapshots[2].current=false;});
check('unknown is not zero',p=>{p.expected.paidVN=null;p.snapshots.forEach(s=>s.values.paidVN=null);});
check('floating cents rejected',p=>{p.expected.paidVN=20000.1;});
check('same error everywhere still fails canonical value',p=>{p.snapshots.forEach(s=>s.values.paidVN+=90);});
check('common report must expose own branch',p=>{p.snapshots[2].values.newValue+=50000;});
check('separate report branch remains separate',p=>{p.snapshots.push({...p.snapshots[2],caseId:'synthetic-household',values:{...p.expected,newValue:50000}});},true);
check('case-specific form requirements',p=>{p.requiredKinds=['SE','report'];p.snapshots=p.snapshots.filter(s=>s.kind!=='ZB');},true);
assert.equal(reconcileDocumentCents().ok,false);count++;
console.log(JSON.stringify({passed:count,total:count,mode:'offline synthetic; no production hook or native field mapping asserted'}));
