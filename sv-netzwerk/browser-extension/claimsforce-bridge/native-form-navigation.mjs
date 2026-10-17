// Read-only UI navigation in the existing bridge. Never creates, edits or saves a form.
import {waitForViewer} from './draft-agent-core.mjs';
const uuid=/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/i;
export async function openExistingNativeForm({tabId,caseId,formId,profile,requiredFields}, io, {timeoutMs=15000}={}) {
  if(!Number.isInteger(tabId)||!uuid.test(caseId)||!uuid.test(formId)||!['christian','jens'].includes(profile)
    ||!Array.isArray(requiredFields)||!requiredFields.length||requiredFields.some(x=>typeof x!=='string'||!x.trim()))throw Error('CF_FORM_CONTEXT_REQUIRED');
  const started=io.clock();
  const call=async fn=>{let timer;try{return await Promise.race([Promise.resolve().then(fn),new Promise((_,reject)=>{timer=setTimeout(()=>reject(Error('CF_FORM_NAVIGATION_TIMEOUT')),Math.max(1,timeoutMs-(io.clock()-started)));})]);}finally{clearTimeout(timer)}};
  if(await call(()=>io.sessionProfile())!==profile)throw Error('CF_FORM_PROFILE_MISMATCH');
  const list=`/claims/${caseId}/investigations`, form=`/claims/${caseId}/forms/${formId}`;
  const route=async()=>{const u=new URL((await call(()=>io.getTab(tabId))).url);if(u.origin!=='https://web.claimsforce.com'||!u.pathname.startsWith(`/claims/${caseId}/`))throw Error('CF_FORM_CASE_MISMATCH');return u.pathname;};
  let current=await route();
  // A direct document navigation may stall Apryse. Re-enter through the existing SPA list.
  if(current!==list){const back=await call(()=>io.send(tabId,{type:'NAVIGATE_NATIVE_FORM_LIST',caseId,formId}));if(!back?.ok)throw Error('CF_FORM_LIST_LINK_UNAVAILABLE');}
  while(await route()!==list){if(io.clock()-started>=timeoutMs)throw Error('CF_FORM_NAVIGATION_TIMEOUT');await io.pause(100);}
  const opened=await call(()=>io.send(tabId,{type:'OPEN_EXISTING_NATIVE_FORM',caseId,formId}));if(!opened?.ok)throw Error('CF_FORM_LINK_UNAVAILABLE');
  return waitForViewer(async()=>{
    if(await call(()=>io.sessionProfile())!==profile)return {identityMismatch:true};
    current=await route();if(current===list)return {outerLoaded:false};
    if(current!==form)return {identityMismatch:true};
    const result=await io.send(tabId,{type:'READ_FORM_READINESS',caseId,formId,requiredFields});
    return result?.ok?result.observation:{observationBlocked:true};
  },{clock:io.clock,pause:io.pause,timeoutMs:Math.max(1,timeoutMs-(io.clock()-started)),intervalMs:200});
}
