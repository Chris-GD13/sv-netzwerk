const PORTAL='https://www.sv-netzwerk.eu';
const FINNYA='https://factoringplus.dv-portal.de';
const key=id=>'finnya-job-'+id;
const ttl=10*60*1000;
let opening=false;
chrome.runtime.onMessage.addListener((message,sender,reply)=>{
  if(!['FINNYA_OPEN','FINNYA_JOB','FINNYA_RESULT'].includes(message?.type))return;
  (async()=>{
    if(sender.frameId!==0)throw Error('Nur das Hauptfenster ist freigegeben.');
    const url=new URL(sender.url||'https://invalid.local');
    if(message.type==='FINNYA_OPEN'){
      if(url.origin!==PORTAL||url.pathname!=='/intern/privatauftraege/angebot/'||!sender.tab?.id)throw Error('Nicht freigegebene Angebotsseite.');
      if(opening)throw Error('Finnya wird bereits geöffnet.');
      const job=message.job;
      if(!/^[a-f0-9-]{36}$/i.test(job?.recordId||'')||!/^[a-f0-9]{64}$/.test(job?.customerKey||''))throw Error('Gespeicherter Vorgang fehlt.');
      const sessions=await chrome.storage.session.get(null);
      for(const [k,v] of Object.entries(sessions))if(k.startsWith('finnya-job-')&&v.recordId===job.recordId&&Date.now()-v.createdAt<ttl)throw Error('Für diesen Vorgang läuft bereits eine Finnya-Prüfung. Bitte den geöffneten Finnya-Tab verwenden.');
      opening=true;
      try{
        // New task tab, never overwrite another user's open customer or unsaved form.
        const tab=await chrome.tabs.create({url:'about:blank',active:true});
        await chrome.storage.session.set({[key(tab.id)]:{...job,portalTabId:sender.tab.id,createdAt:Date.now()}});
        await chrome.tabs.update(tab.id,{url:FINNYA+'/debtors?createDebtor=true'});
        return {ok:true};
      }finally{opening=false;}
    }
    if(url.origin!==FINNYA||!sender.tab?.id)throw Error('Nicht freigegebene Finnya-Seite.');
    const job=(await chrome.storage.session.get(key(sender.tab.id)))[key(sender.tab.id)];
    if(!job||Date.now()-job.createdAt>ttl){await chrome.storage.session.remove(key(sender.tab.id));return {ok:false,error:'Kein aktueller Angebotsauftrag.'};}
    if(message.type==='FINNYA_JOB')return {ok:true,job};
    const result=message.result;
    if(!result||result.source_url!==sender.url)throw Error('Ungültige Finnya-Rückmeldung.');
    const saved=await chrome.tabs.sendMessage(job.portalTabId,{type:'FINNYA_PORTAL_RESULT',recordId:job.recordId,customerKey:job.customerKey,result});
    if(!saved?.ok)throw Error(saved?.error||'Portalablage nicht bestätigt.');
    await chrome.storage.session.remove(key(sender.tab.id));
    return {ok:true};
  })().then(reply,error=>reply({ok:false,error:error.message}));
  return true;
});
chrome.tabs.onRemoved.addListener(id=>chrome.storage.session.remove(key(id)));
