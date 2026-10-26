import {createNativeDraft} from './native-create-operation.mjs';
import {openExistingNativeForm} from './native-form-navigation.mjs';
export async function runNativeCreate(request,io,tabId,hash) {
 const send=async message=>{const r=await io.send(tabId,message);if(!r?.ok)throw Error(r?.error||'NATIVE_CREATE_CHANNEL_FAILED');return r.result;};
 const inventory=async()=>{
  let forms,previous='',ready=false;
  const deadline=Date.now()+15000;
  do{
   forms=await send({type:'NATIVE_FORM_INVENTORY',caseId:request.caseId});
   const signature=JSON.stringify(forms);
   if(forms.length&&forms.every(f=>['draft','sent'].includes(f.status))&&signature===previous){ready=true;break;}
   previous=signature;await io.pause(200);
  }while(Date.now()<deadline);
  const {templates}=await send({type:'NATIVE_FORM_TEMPLATES',caseId:request.caseId});
  return{forms,templates,ready,hash:await hash({forms,templates})};
 };
 if(request.action==='inspect_templates')return{readOnly:true,caseId:request.caseId,profile:request.profile,...await inventory()};
 return createNativeDraft(request,{
  hash,journal:io.journal,
  assertIdentity:async()=>{await io.assertAccount(request.profile);const tab=await io.getTab(tabId);if(!tab.url?.startsWith(`https://web.claimsforce.com/claims/${request.caseId}/`))throw Error('NATIVE_CREATE_ROUTE_MISMATCH');},
  withCaseLock:async(identity,fn)=>{
   const key=`create-case:${identity.profile}:${identity.caseId}`,lock=await io.journal.get(key);
   if(lock&&lock.operationId!==request.operationId)throw Error('NATIVE_CREATE_CASE_LOCKED');
   await io.journal.set(key,{operationId:request.operationId});
   try{const result=await fn();await io.journal.set(key,null);return result;}
   catch(error){
    // A failed preflight is safe to release. An uncertain click keeps its lock.
    if(!await io.journal.get(request.operationId))await io.journal.set(key,null);
    throw error;
   }
  },inventory,
  createFromTemplate:async()=>{
   await send({...request,type:'NATIVE_FORM_CREATE'});
   const deadline=Date.now()+15000;
   do{
    const tab=await io.getTab(tabId),url=new URL(tab.url);
    const match=url.pathname.match(/^\/claims\/([a-f0-9-]+)\/forms\/([a-f0-9-]+)$/i);
    if(url.origin==='https://web.claimsforce.com'&&match?.[1]===request.caseId)return{formId:match[2]};
    await io.pause(200);
   }while(Date.now()<deadline);
   throw Error('NATIVE_CREATE_ROUTE_TIMEOUT');
  },
  readDraft:async({formId})=>{
   const current=await io.getTab(tabId);
   if(new URL(current.url).pathname.includes('/forms/')){
    const deadline=Date.now()+15000;
    let navigated=false;
    do{const r=await io.send(tabId,{type:'NAVIGATE_NATIVE_FORM_LIST',caseId:request.caseId,formId});if(r?.ok){navigated=true;break;}await io.pause(200);}while(Date.now()<deadline);
    if(!navigated)throw Error('NATIVE_CREATE_BACKLINK_NOT_READY');
   }
   let list,found;
   const deadline=Date.now()+15000;
   do{
    const r=await io.send(tabId,{type:'NATIVE_FORM_INVENTORY',caseId:request.caseId});
    if(r?.ok){list=r.result;found=list.find(f=>f.formId===formId);if(found?.status==='draft')break;}
    await io.pause(200);
   }while(Date.now()<deadline);
   if(!found||found.status!=='draft')throw Error('NATIVE_CREATE_NEW_DRAFT_NOT_LISTED');
   const expected={...request,formId};
   const ready=await openExistingNativeForm({...expected,tabId,requiredFields:['insurerClaimId']},{clock:Date.now,pause:io.pause,sessionProfile:async()=>{await io.assertAccount(request.profile);return request.profile;},getTab:io.getTab,send:io.send});
   if(!ready.ok)throw Error('NATIVE_CREATE_VIEWER_NOT_READY');
   const snapshot=await send({...expected,type:'NATIVE_FORM_SNAPSHOT'});
   return{...snapshot,status:found.status,templateName:found.templateName};
  }
 });
}
