// One explicit read; never starts authentication, case discovery, tasks or imports.
export function createManualNotesReader({getToken,getConfig,request,isImportRunning=()=>false}){
  let flight=false;
  const read=async identity=>{
    if(flight||isImportRunning())throw Error('NOTES_REQUEST_BUSY');
    if(!identity?.folderId||!identity.claimNumber||!['christian','holger','marc','jens'].includes(identity.profile)||!/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i.test(identity.claimId||''))throw Error('NOTES_CONTEXT_REQUIRED');
    flight=true;
    try{
      const token=await getToken(identity.profile);if(!token)throw Error('NOTES_PROFILE_SESSION_REQUIRED');
      const config=await getConfig();const base=new URL(config.COMMUNICATION_API_ENDPOINT);
      if(base.protocol!=='https:'||!base.hostname.endsWith('.claimsforce.com'))throw Error('NOTES_CONFIG_INVALID');
      if(isImportRunning())throw Error('NOTES_REQUEST_BUSY');
      if(await getToken(identity.profile)!==token)throw Error('NOTES_PROFILE_SESSION_CHANGED');
      const raw=await request(`${base.href.replace(/\/$/,'')}/claims/${identity.claimId}/notes`,token);
      if(raw?.claimId&&raw.claimId!==identity.claimId)throw Error('NOTES_IDENTITY_MISMATCH');
      const rows=Array.isArray(raw)?raw:Array.isArray(raw?.notes)?raw.notes:Array.isArray(raw?.data?.notes)?raw.data.notes:Array.isArray(raw?.data)?raw.data:null;
      if(!rows)throw Error('NOTES_RESPONSE_INVALID');
      const notes=rows.map(note=>{
        if(note?.claimId&&note.claimId!==identity.claimId)throw Error('NOTES_IDENTITY_MISMATCH');
        const text=typeof note==='string'?note:String(note?.text||note?.body||note?.content||note?.note||'');
        if(!text.trim())throw Error('NOTES_RESPONSE_INVALID');
        return {id:String(note?.id||''),text,createdAt:String(note?.created?.date||note?.createdAt||note?.date||''),updatedAt:String(note?.updated?.date||note?.updatedAt||''),author:String(note?.created?.by?.name||note?.author?.name||note?.author||note?.createdBy?.name||'')};
      });
      return {identity,notes};
    }finally{flight=false}
  };
  read.isBusy=()=>flight;
  return read;
}
