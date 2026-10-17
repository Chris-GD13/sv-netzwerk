// An instance must be supplied by a separately verified, read-only integration.
// No probing of React internals, mutation, import, export, or field values in logs.
export async function inspectApryse(instance, expected, observeIdentity, timeoutMs=3000) {
 if(!expected?.caseId||!expected?.formId||!expected?.profile||!Array.isArray(expected.requiredFields)
   ||!expected.requiredFields.length||expected.requiredFields.some(name=>typeof name!=='string'||!name.trim())
   ||new Set(expected.requiredFields).size!==expected.requiredFields.length)return {ok:false,state:'mapping-missing'};
 const dv=instance?.Core?.documentViewer,am=instance?.Core?.annotationManager;
 if(!dv||!am)return {ok:false,state:'sdk-unavailable'};
 let timer,listener;
 const timed=new Promise(resolve=>{timer=setTimeout(()=>resolve({ok:false,state:'timeout'}),timeoutMs);});
 const work=(async()=>{
  try {
   if(!dv.getDocument())await new Promise(resolve=>{listener=resolve;dv.addEventListener('documentLoaded',listener);if(dv.getDocument())resolve();});
   await dv.getAnnotationsLoadedPromise();
   const identity=await observeIdentity();
   if(identity.caseId!==expected.caseId||identity.formId!==expected.formId||identity.profile!==expected.profile)return {ok:false,state:'identity-mismatch'};
   if(identity.mode!=='FORM'||identity.locked)return {ok:false,state:'readonly'};
   const fields=am.getFieldManager().getFields();
   for(const name of expected.requiredFields){
    const found=fields.filter(f=>f.name===name);
    if(found.length!==1)return {ok:false,state:'field-missing-or-duplicate'};
    const f=found[0];
    if(typeof f.getValue!=='function'||typeof f.flags?.get!=='function')return {ok:false,state:'field-unverified'};
    if(f.type==='Sig'||f.flags.get('ReadOnly')!==false)return {ok:false,state:'readonly'};
    f.getValue(); // Verify readability without returning private values.
   }
   return {ok:true,state:'sdk-fields-readable',fieldCount:expected.requiredFields.length,editable:'field-flags-only; widget/user permissions still require validation'};
  }catch{return {ok:false,state:'observation-blocked'};}
 })();
 try{return await Promise.race([work,timed]);}
 finally{clearTimeout(timer);if(listener)dv.removeEventListener('documentLoaded',listener);}
}
