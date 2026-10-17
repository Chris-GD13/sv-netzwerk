// Offline candidate only. Validate extracted report cells, not a caller's "checked" flag.
const columns = ['Sparte','Bislang gezahlt','Befürwortet','Damit insgesamt gezahlt'];
const branches = ['Gebäude','Hausrat','Mietausfall'];
const amount = s => {
  if (typeof s !== 'string' || !/^(?:0|[1-9]\d{0,2}(?:\.\d{3})*|[1-9]\d*),\d{2} €$/.test(s)) return null;
  const n = Number(s.replace(/[. €]/g,'').replace(',','.')) * 100;
  return Number.isSafeInteger(Math.round(n)) && n >= 0 ? Math.round(n) : null;
};
export function engelPaymentTableGate(table, sources = []) {
  const errors=[];
  let paidCents=0;
  if (!table || JSON.stringify(table.headers)!==JSON.stringify(columns)
    || table.rows?.length!==3 || !table.reportHash) return {ok:false,errors:['ENGEL_TABLE_STRUCTURE']};
  for (let i=0;i<3;i++) {
    const row=table.rows[i], values=row.cells?.slice(1).map(amount);
    if (row.cells?.[0]!==branches[i] || row.cells.length!==4 || values?.some(x=>x===null)) {
      errors.push('ENGEL_NUMERIC_CELLS:'+branches[i]); continue;
    }
    paidCents+=values[0];
    if(values[0]+values[1]!==values[2]) errors.push('ENGEL_TABLE_BALANCE:'+branches[i]);
    for(let j=0;j<3;j++) {
      const e=row.evidence?.[j];
      const source=sources.find(s=>s.id===e?.sourceId && s.sha256===e?.sourceHash && s.verified===true);
      if(!source || !e.locator || e.reportHash!==table.reportHash || e.cents!==values[j]
        || e.branch!==branches[i] || e.column!==columns[j+1]
        || (j===0 && source.kind!=='payment-confirmation' && source.kind!=='verified-payment-ledger'))
        errors.push('ENGEL_TABLE_EVIDENCE:'+branches[i]+':'+columns[j+1]);
    }
  }
  return {ok:errors.length===0,errors,paidCents};
}
