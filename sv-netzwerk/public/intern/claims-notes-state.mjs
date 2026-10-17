// Case-scoped state for the explicit manual notes action.
// UI identity is not an authorization grant; a future adapter must revalidate it server-side.
const profiles=new Set(['christian','jens','holger','marc']);
const uuid=/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
const number=v=>String(v||'').replace(/[\s\u00ad\u2010-\u2015\u2212-]+/gu,'').toUpperCase();
export function notesIdentity(opened){
  if(opened?.loaded!==true)return null;
  const i=opened.identity;
  if(!i?.folderId||!uuid.test(i.claimId||'')||!profiles.has(i.profile)||!number(i.claimNumber))return null;
  return {folderId:String(i.folderId),claimId:i.claimId.toLowerCase(),profile:i.profile,claimNumber:number(i.claimNumber)};
}
const key=i=>i?JSON.stringify([i.profile,i.folderId,i.claimId,i.claimNumber]):'';
export function mergeNotes(existing,incoming){
  if(!Array.isArray(existing)||!Array.isArray(incoming))throw Error('NOTES_INVALID');
  const out=new Map();
  for(const raw of [...existing,...incoming]){
    if(!raw||typeof raw.text!=='string'||!raw.text.trim())continue;
    const n={...raw};
    const id=n.id?JSON.stringify(['id',String(n.id)]):JSON.stringify(['text',n.createdAt||'',n.author||'',n.text]);
    const previous=out.get(id);
    if(previous){
      const oldDate=Date.parse(previous.updatedAt||previous.createdAt||''),newDate=Date.parse(n.updatedAt||n.createdAt||'');
      if(previous.text===n.text){if(!Number.isFinite(newDate)||(Number.isFinite(oldDate)&&newDate<=oldDate))continue;}
      else if(!Number.isFinite(oldDate)||!Number.isFinite(newDate)||newDate===oldDate)throw Error('NOTES_VERSION_CONFLICT');
      if(Number.isFinite(oldDate)&&newDate<oldDate)continue;
    }
    out.set(id,n);
  }
  return [...out.values()];
}
export class CaseNotesState {
  constructor({readManually,onChange=()=>{},timeoutMs=30000}={}){
    this.readManually=readManually;this.onChange=onChange;this.timeoutMs=timeoutMs;
    this.current=null;this.epoch=0;this.flights=new Map();this.state={identity:null,notes:[],status:'closed'};
  }
  publish(){const copy=structuredClone(this.state);this.onChange(copy);return copy;}
  open(opened,cached){
    this.epoch++;
    for(const entry of this.flights.values())entry.controller.abort();
    this.current=notesIdentity(opened);
    // Clear immediately before examining potentially stale cache or waiting for any request.
    this.state={identity:this.current,notes:[],status:this.current?'ready':'closed'};this.publish();
    if(this.current&&key(notesIdentity(cached))===key(this.current)){
      this.state.notes=mergeNotes([],cached.notes||[]);this.publish();
    }
    // No network, import, persistence or analysis on opening a case.
    return this.publish();
  }
  refresh(){
    if(!this.current)return Promise.reject(Error('NOTES_CASE_REQUIRED'));
    if(typeof this.readManually!=='function')return Promise.reject(Error('NOTES_ADAPTER_NOT_CONNECTED'));
    const identity=structuredClone(this.current),k=key(identity),epoch=this.epoch;
    const existing=this.flights.get(k);
    if(existing){
      if(existing.epoch===epoch&&!existing.controller.signal.aborted)return existing.promise;
      return Promise.reject(Error('NOTES_PREVIOUS_REQUEST_UNSETTLED'));
    }
    const controller=new AbortController(),entry={controller,epoch,promise:null};this.flights.set(k,entry);
    this.state.status='loading';delete this.state.error;this.publish();
    let timer;
    const request=Promise.resolve().then(()=>this.readManually(identity,{signal:controller.signal}));
    // Keep the same-case lock until transport actually settles, even if it ignores cancellation.
    request.then(()=>this.flights.get(k)===entry&&this.flights.delete(k),()=>this.flights.get(k)===entry&&this.flights.delete(k));
    const deadline=new Promise((_,reject)=>{timer=setTimeout(()=>{controller.abort();reject(Error('NOTES_TIMEOUT'));},this.timeoutMs);});
    entry.promise=Promise.race([request,deadline]).then(response=>{
      if(epoch!==this.epoch||key(this.current)!==k)return {discarded:true};
      if(controller.signal.aborted)throw Error('NOTES_ABORTED');
      if(key(notesIdentity({loaded:true,identity:response?.identity}))!==k)throw Error('NOTES_IDENTITY_MISMATCH');
      this.state.notes=mergeNotes(this.state.notes,response.notes);
      this.state.status='ready';return this.publish();
    }).catch(error=>{
      if(epoch===this.epoch&&key(this.current)===k){this.state.status='error';this.state.error=String(error.message||'NOTES_FAILED');this.publish();}
      throw error;
    }).finally(()=>clearTimeout(timer));
    return entry.promise;
  }
}
