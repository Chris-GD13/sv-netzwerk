import {openExistingNativeForm} from './native-form-navigation.mjs';
import {executeNativeDraft} from './native-draft-operation.mjs';
import {runNativeCreate} from './native-create-worker.mjs';
const hash=async value=>[...new Uint8Array(await crypto.subtle.digest('SHA-256',new TextEncoder().encode(JSON.stringify(value))))].map(n=>n.toString(16).padStart(2,'0')).join('');
let active=false;
export async function runNativeForm(request,io){
  if(active)throw Error('NATIVE_WORKER_BUSY');active=true;
  const startedAt=Date.now();
  const timed=result=>({...result,durationMs:Math.max(0,Date.now()-startedAt)});
  let tabId,keep=false;
  try{
    if(!['inspect','save','inspect_templates','create'].includes(request.action)||!['christian','jens'].includes(request.profile)||!/^[a-f0-9-]{36}$/.test(request.caseId))throw Error('NATIVE_REQUEST_INVALID');
    await io.assertAccount(request.profile);
    const tab=await io.createTab(`https://web.claimsforce.com/claims/${request.caseId}/investigations`);tabId=tab.id;
    await io.waitTab(tabId);
    if(['inspect_templates','create'].includes(request.action)){
      keep=request.action==='create';
      const result=await runNativeCreate(request,io,tabId,hash);
      keep=false;return timed({source:'claimsforce-native-form',operationId:request.operationId,...result});
    }
    const send=async message=>{const r=await io.send(tabId,message);if(!r?.ok)throw Error(r?.error||'NATIVE_CHANNEL_FAILED');return r.result};
    let forms=[];
    const clock=io.clock||Date.now,deadline=clock()+15000;
    let previous='',settled=false;
    do{
      forms=await send({type:'NATIVE_FORM_INVENTORY',caseId:request.caseId});
      // Cards arrive before their status badges. Never treat a visible link as
      // proof that the inventory has finished loading or is safe to edit.
      const signature=JSON.stringify(forms.map(f=>[f.formId,f.status]).sort());
      const ready=forms.length&&forms.every(f=>['draft','sent'].includes(f.status));
      if(ready&&signature===previous){settled=true;break;}
      previous=signature;
      await io.pause(200);
    }while(clock()<deadline);
    if(!forms.length)throw Error('NATIVE_INVENTORY_NOT_READY_OR_EMPTY');
    if(request.action==='save'&&!settled)throw Error('NATIVE_INVENTORY_STATUS_NOT_READY');
    if(!request.formId)return timed({source:'claimsforce-native-form',readOnly:true,caseId:request.caseId,profile:request.profile,forms});
    const matches=forms.filter(f=>f.formId===request.formId);
    if(matches.length!==1)throw Error('NATIVE_FORM_NOT_IN_INVENTORY');
    const openRead=async()=>{
      const ready=await openExistingNativeForm({...request,tabId,requiredFields:['insurerClaimId']},{clock:Date.now,pause:io.pause,sessionProfile:async()=>{await io.assertAccount(request.profile);return request.profile},getTab:io.getTab,send:io.send});
      if(!ready.ok)throw Error('NATIVE_VIEWER_NOT_READY');
      return{...await send({...request,type:'NATIVE_FORM_SNAPSHOT'}),status:matches[0].status};
    };
    if(request.action==='inspect')return timed({source:'claimsforce-native-form',readOnly:true,forms,snapshot:await openRead()});
    const result=await executeNativeDraft(request,{
      hash,withLock:async(_profile,fn)=>fn(),journal:io.journal,assertAccount:io.assertAccount,
      openAndRead:openRead,
      apply:async()=>{keep=true;return send({...request,type:'NATIVE_FORM_APPLY'})},
      save:()=>send({...request,type:'NATIVE_FORM_SAVE'}),
      reopenAndRead:openRead
    });
    keep=false;return timed({source:'claimsforce-native-form',...result});
  }finally{
    // Keep an uncertain/dirty own tab for reconciliation; never close user tabs.
    if(tabId&&!keep)await io.closeTab(tabId).catch(()=>{});
    active=false;
  }
}
