export function plan(total=2500000) {
 const identity={caseId:'offline-test-case',profile:'christian',vn:'Testobjekt',object:'Testobjekt'};
 const source={id:'offer',readable:true,caseId:identity.caseId,profile:identity.profile};
 return {caseId:identity.caseId,profile:identity.profile,expectedIdentity:identity,observedIdentity:{...identity},
 authorized:true,sources:[source,{...source,id:'payment-proof',kind:'verified-payment-ledger',verified:true,sha256:'synthetic-payment-hash'},{...source,id:'payment-confirmation',kind:'payment-confirmation',verified:true}],requiredSources:['offer'],facts:[{sourceIds:['offer']}],
 positions:[{id:'estimate',caseId:identity.caseId,sourceId:'offer',sourcePositionId:'1',netCents:total,vatCents:0,grossCents:total,included:true}],overlapReviewed:true,
 exposureCents:total,payments:[{id:'payment1',status:'paid',sourceId:'payment-confirmation',cents:10000}],
 deductibleCents:50000,deductibleApplications:1,remainingCents:Math.max(0,total-60000),gf:true,
 documents:[{kind:'report',format:'docx',folder:'Berichte',title:'Zwischenbericht Nr. 2',contentChecked:true,hash:'report-hash',sourceIds:['offer']},
 {kind:'SE',caseId:identity.caseId,positionCaseIds:[identity.caseId],native:true,contentChecked:true,hash:'se-hash',sourceIds:['offer','payment-proof']}],
 engelPaymentTable:{reportHash:'report-hash',headers:['Sparte','Bislang gezahlt','Befürwortet','Damit insgesamt gezahlt'],rows:['Gebäude','Hausrat','Mietausfall'].map((branch,i)=>({cells:[branch,i===0?'100,00 €':'0,00 €','0,00 €',i===0?'100,00 €':'0,00 €'],evidence:['Bislang gezahlt','Befürwortet','Damit insgesamt gezahlt'].map((column,j)=>({sourceId:'payment-proof',sourceHash:'synthetic-payment-hash',locator:'synthetic ledger row '+i,reportHash:'report-hash',branch,column,cents:i===0&&j!==1?10000:0}))}))},
 externalTextReviewed:true,containsInternalNotes:false,operation:'prepare-draft',reportType:'Nachtrag',operationId:'replay-1',contentHash:'payload-and-attachments-hash'};
}
