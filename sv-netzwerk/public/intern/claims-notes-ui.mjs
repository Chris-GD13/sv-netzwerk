import {CaseNotesState,notesIdentity} from './claims-notes-state.mjs';

export function bridgeNotes(identity,{signal}={},win=window){
  return new Promise((resolve,reject)=>{
    const requestId=crypto.randomUUID();let timer;
    const finish=(error,value)=>{clearTimeout(timer);win.removeEventListener('message',receive);error?reject(error):resolve(value)};
    const receive=event=>{
      if(event.source!==win||event.origin!==win.location.origin||event.data?.type!=='SVNET_CLAIMS_NOTES_RESULT'||event.data.requestId!==requestId)return;
      if(signal?.aborted)return finish(Error('NOTES_ABORTED'));
      event.data.ok?finish(null,event.data.result):finish(Error(event.data.error||'NOTES_FAILED'));
    };
    win.addEventListener('message',receive);
    timer=setTimeout(()=>finish(Error('NOTES_BRIDGE_TIMEOUT')),65000);
    win.postMessage({type:'SVNET_CLAIMS_NOTES_READ',requestId,identity},win.location.origin);
  });
}
export function mountNotes(doc=document,win=window){
  const panel=doc.getElementById('vf-claims-notes'),button=doc.getElementById('vf-claims-notes-refresh'),status=doc.getElementById('vf-claims-notes-status'),list=doc.getElementById('vf-claims-notes-list');
  if(!panel||!button||!status||!list)return null;
  const controller=new CaseNotesState({
    readManually:async(identity,{signal})=>{
      const result=await bridgeNotes(identity,{signal},win);
      if(signal.aborted)throw Error('NOTES_ABORTED');
      const returned=notesIdentity({loaded:true,identity:result?.identity});
      if(JSON.stringify(returned)!==JSON.stringify(identity)||!Array.isArray(result?.notes))throw Error('NOTES_IDENTITY_MISMATCH');
      const response=await fetch('/intern/api/google-drive-sync.php?action=merge_claimsforce_notes',{method:'POST',credentials:'same-origin',signal,headers:{'Content-Type':'application/json','X-SVNET-Expert-Profile':identity.profile},body:JSON.stringify({folder_id:identity.folderId,identity,notes:result.notes})});
      const saved=await response.json();if(!response.ok||saved.ok!==true)throw Error(saved.error||'NOTES_SAVE_FAILED');
      if(signal.aborted)throw Error('NOTES_ABORTED');
      win.dispatchEvent(new CustomEvent('svnet:notes-saved',{detail:saved}));
      return saved;
    },
    onChange:state=>{
      panel.hidden=!state.identity;button.disabled=!state.identity||state.status==='loading';
      status.textContent=state.status==='loading'?'Notizen werden eingelesen und gespeichert …':state.status==='error'?'Notizen konnten nicht übernommen werden: '+state.error:state.notes.length+' gespeicherte Notiz(en).';
      list.replaceChildren();for(const note of state.notes){const article=doc.createElement('article'),meta=doc.createElement('small'),body=doc.createElement('div');meta.textContent=[note.createdAt,note.author].filter(Boolean).join(' · ');body.textContent=note.text;article.append(meta,body);list.append(article)}
    }
  });
  let currentKey='';
  const open=active=>{
    const opened=active?._loaded===true?{loaded:true,identity:{folderId:active.folder_id,claimId:active.meta?.claimsforce_claim_id,profile:active.meta?.claimsforce_profile,claimNumber:active.meta?.schaden_nr}}:null;
    const identity=notesIdentity(opened),key=JSON.stringify(identity);
    if(key===currentKey)return;currentKey=key;
    controller.open(opened,identity?{...opened,notes:active.meta?.claimsforce_notizen||[]}:null);
  };
  button.addEventListener('click',()=>controller.refresh().catch(()=>{}));
  win.addEventListener('svnet:case-opened',event=>open(event.detail));
  open(win.svnetOpenedClaimsCase);
  return controller;
}
if(typeof document!=='undefined')mountNotes();
