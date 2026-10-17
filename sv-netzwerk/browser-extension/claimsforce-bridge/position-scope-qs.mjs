// Checks already evidenced scope mappings; does not infer quantities from prose/photos.
export function checkPositionScopes(caseId, lines) {
 const errors=[],used=new Set();
 for(const l of lines){
  if(l.caseId!==caseId)errors.push('FOREIGN_CASE');
  if(!l.sourceId||!l.sourceHash||!l.locator)errors.push('SOURCE_MISSING');
  if(![l.netCents,l.vatCents,l.grossCents].every(n=>Number.isSafeInteger(n)&&n>=0)
    ||l.netCents+l.vatCents!==l.grossCents)errors.push('AMOUNT');
  if(!['quoted-zero','quoted-standard'].includes(l.taxBasis))errors.push('TAX_UNKNOWN');
  if(l.taxBasis==='quoted-zero'&&l.vatCents!==0)errors.push('ZERO_VAT_CONTRADICTION');
  if(l.included){
   if(l.scopeVerified!==true||!l.scopeUnits?.length)errors.push('SCOPE_UNRESOLVED');
   for(const unit of l.scopeUnits||[]){
    // Explicit, sourced work/room/unit identifiers only. A room name alone is insufficient.
    const key=JSON.stringify([caseId,unit]);if(used.has(key))errors.push('PARTIAL_SCOPE_DUPLICATE');used.add(key);
   }
  }
 }
 return {ok:errors.length===0,errors:[...new Set(errors)]};
}
