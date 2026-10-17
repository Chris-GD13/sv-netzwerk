// Claims InvestigationPage passes section-keyed formData to updateInvestigation.
// This builds an offline change plan only; it never calls that client.
export function planNativeContent(original, schema, changes) {
 if(!original||typeof original!=='object'||Array.isArray(original))throw Error('CONTENT_NOT_OBJECT');
 if(!schema?.properties||!Array.isArray(changes)||!changes.length)throw Error('SCHEMA_OR_CHANGES_MISSING');
 const next=structuredClone(original),seen=new Set();
 for(const c of changes){
  if(!Array.isArray(c.path)||c.path.length!==2||c.path.some(k=>typeof k!=='string'||['__proto__','constructor','prototype'].includes(k)))throw Error('UNSAFE_PATH');
  const [section,field]=c.path,key=JSON.stringify(c.path),spec=schema.properties[section]?.properties?.[field];
  if(!spec||seen.has(key))throw Error('UNKNOWN_OR_DUPLICATE_FIELD');seen.add(key);
  if(!Object.hasOwn(original,section)||!Object.hasOwn(original[section],field))throw Error('ORIGINAL_SLOT_MISSING');
  if(JSON.stringify(original[section][field])!==JSON.stringify(c.before))throw Error('STALE_ORIGINAL');
  if(!['string','number','boolean'].includes(spec.type)||typeof c.after!==spec.type||typeof c.after==='number'&&!Number.isFinite(c.after))throw Error('SCHEMA_TYPE');
  if(spec.enum&&!spec.enum.includes(c.after))throw Error('SCHEMA_ENUM');
  if(!c.sourceId||!c.sourceHash||!c.locator)throw Error('CHANGE_WITHOUT_EVIDENCE');
  next[section][field]=c.after;
 }
 return {content:next,changes:structuredClone(changes),operation:'local-plan-only'};
}
