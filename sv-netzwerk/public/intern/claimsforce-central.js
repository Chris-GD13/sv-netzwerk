(()=>{
  const old=document.getElementById('vf-claims-import'),state=document.getElementById('vf-claims-state');
  if(!old)return;
  const button=old.cloneNode(true);old.replaceWith(button);
  let context={},bridge=false,agentJob=null,userJobs=[],busy=false;
  const json=async(url,o={})=>{const r=await fetch(url,{credentials:'same-origin',...o}),j=await r.json().catch(()=>({}));if(!r.ok||!j.ok)throw Error(j.error||`HTTP ${r.status}`);return j};
  const post=(a,d={})=>json('/intern/api/claimsforce-queue.php?action='+a,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(d)});
  const show=(t,b=false)=>{state.textContent=t;state.className='vf-meta '+(b?'vf-claims-bad':'')};
  async function watch(){
    if(!userJobs.length)return;
    try{
      const jobs=await Promise.all(userJobs.map(async id=>(await json('/intern/api/claimsforce-queue.php?action=status&id='+id)).job));
      const failed=jobs.some(job=>job.status==='failed');
      show(jobs.map(job=>job.message||'Import läuft …').join(' · '),failed);
      if(jobs.every(job=>['done','failed'].includes(job.status))){button.disabled=false;userJobs=[];return}
    }catch(e){show(e.message,true)}
    setTimeout(watch,3000);
  }
  button.addEventListener('click',async()=>{
    if(userJobs.length)return;
    button.disabled=true;
    try{
      const profile=context.backoffice?(context.selected_expert||'christian'):(context.claims_profile||'christian');
      const profiles=profile==='christian'?['christian','jens']:[profile];
      userJobs=[];
      for(const source of profiles)userJobs.push((await post('enqueue',{profile:source})).job.id);
      show(profile==='christian'?'Importaufträge für Christian und die ehemaligen Jens-Maurer-Fälle wurden übergeben.':'Importauftrag wurde an die zentrale Importstation übergeben.');
      watch();
    }catch(e){button.disabled=false;userJobs=[];show(e.message,true)}
  });
  async function poll(){
    if(!context.claims_agent||!bridge||busy){setTimeout(poll,5000);return}
    try{
      const d=await post('claim');
      if(d.job){
        busy=true;agentJob=d.job;
        const target=d.job.profile==='jens'?'christian':d.job.profile;
        await json('/intern/api/google-drive-sync.php?action=select_expert',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({expert:target})});
        window.postMessage({type:'SVNET_CLAIMS_IMPORT_START',profile:d.job.profile},location.origin);
      }
    }catch(e){show('Zentrale Importstation: '+e.message,true)}
    setTimeout(poll,5000);
  }
  window.addEventListener('message',async e=>{
    if(e.source!==window||e.origin!==location.origin)return;
    const d=e.data||{};
    if(d.type==='SVNET_CLAIMS_BRIDGE_READY')bridge=true;
    if(agentJob&&['SVNET_CLAIMS_IMPORT_DONE','SVNET_CLAIMS_IMPORT_ERROR'].includes(d.type)){
      const ok=d.type==='SVNET_CLAIMS_IMPORT_DONE';
      await post('complete',{id:agentJob.id,ok,result:d.result||null,message:ok?`${d.result?.claims||0} Aufträge vollständig eingelesen.`:(d.error||'ClaimsForce-Import fehlgeschlagen.')}).catch(()=>{});
      agentJob=null;busy=false;
    }
  });
  async function automaticImport(){
    if(!context.claims_agent)return;
    const now=new Date(),day=now.toISOString().slice(0,10),key='svnet-claimsforce-auto-day';
    if(now.getHours()!==3||localStorage.getItem(key)===day)return;
    localStorage.setItem(key,day);
    try{
      for(const profile of['christian','jens','marc','holger'])await post('enqueue',{profile});
      show('Automatischer 03:00-Uhr-Import für alle ClaimsForce-Zugänge wurde eingeplant.');
    }catch(e){localStorage.removeItem(key);show('Automatischer ClaimsForce-Import: '+e.message,true)}
  }
  json('/intern/api/google-drive-sync.php?action=status').then(d=>{context=d;window.postMessage({type:'SVNET_CLAIMS_BRIDGE_PING'},location.origin);automaticImport();setInterval(automaticImport,30000);poll()}).catch(e=>show(e.message,true));
})();
