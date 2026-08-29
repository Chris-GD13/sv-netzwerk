(()=>{
  const old=document.getElementById('vf-claims-import'),state=document.getElementById('vf-claims-state');
  if(!old)return;
  const button=old.cloneNode(true);old.replaceWith(button);
  const claimsCard=button.closest('.vf-claims-import');
  const plaudCard=document.querySelector('.vf-plaud');
  const polycamCard=document.querySelector('.vf-polycam');
  const placeImportCards=()=>{
    if(!claimsCard)return;
    claimsCard.style.borderTop='4px solid #b9852f';
    const badge=claimsCard.querySelector('.vf-step > b');
    if(badge){badge.style.background='#b9852f';badge.style.color='#fff'}
    const main=document.querySelector('.vf-app'),systemHome=document.getElementById('vf-system-home');
    if(main){
      const anchor=systemHome||null;
      [plaudCard,polycamCard,claimsCard].forEach(card=>{
        if(!card)return;
        if(anchor)main.insertBefore(card,anchor);
        else main.appendChild(card);
        card.style.marginTop='12px';
      });
    }
  };
  placeImportCards();
  let context={},bridge=false,bridgeVersion='',agentJob=null,userJobs=[],busy=false,lastRuntime={phase:'CF-IDLE',message:'Importstation wartet.',current:0,total:0,diagnostic:{}};
  const json=async(url,o={})=>{const r=await fetch(url,{credentials:'same-origin',...o}),j=await r.json().catch(()=>({}));if(!r.ok||!j.ok)throw Error(j.error||`HTTP ${r.status}`);return j};
  const post=(a,d={})=>json('/intern/api/claimsforce-queue.php?action='+a,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(d)});
  const show=(t,b=false)=>{state.textContent=t;state.className='vf-meta '+(b?'vf-claims-bad':'')};
  const versionAtLeast=(actual,required)=>{const a=String(actual).split('.').map(Number),r=String(required).split('.').map(Number);for(let i=0;i<3;i++){if((a[i]||0)!==(r[i]||0))return(a[i]||0)>(r[i]||0)}return true};
  const resultOf=job=>{try{return typeof job.result==='string'?JSON.parse(job.result):job.result||{}}catch{return{}}};
  const heartbeat=()=>agentJob?post('heartbeat',{id:agentJob.id,message:lastRuntime.message,phase:lastRuntime.phase,current:lastRuntime.current,total:lastRuntime.total,diagnostic:lastRuntime.diagnostic}).catch(()=>{}):Promise.resolve();

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

  async function resumeWatch(){
    try{
      const recent=await json('/intern/api/claimsforce-queue.php?action=mine');
      userJobs=(recent.jobs||[]).filter(job=>['queued','running'].includes(job.status)).map(job=>Number(job.id));
      if(userJobs.length){button.disabled=true;watch();return}
      const terminal=(recent.jobs||[]).filter(job=>['done','failed'].includes(job.status)).slice(0,4);
      if(terminal.length){const compact=terminal.map(job=>`${job.id}|${job.profile}|${job.status}|${job.phase||'CF-STATUS'}`).join(';');document.documentElement.setAttribute('data-svnet-claims-jobs',compact);const results=terminal.map(job=>{const r=resultOf(job);return`${job.id}|${Number(r.claims||0)}|${Number(r.updated||0)}|${Number(r.skipped||0)}|${Number(r.files||0)}|${Number(r.messages||0)}|${Number(r.appointments||0)}`}).join(';');document.documentElement.setAttribute('data-svnet-claims-results',results);const text=terminal.map(job=>`${job.profile}: ${job.status==='done'?'abgeschlossen':'fehlgeschlagen'} [${job.phase||'CF-STATUS'}] – ${job.message||'ohne Meldung'}`).join(' · '),failed=terminal.some(job=>job.status==='failed');show(text,failed);setTimeout(()=>show(text,failed),1000)}
    }catch(e){show('Importstatus konnte nicht wiederhergestellt werden: '+e.message,true)}
  }

  button.addEventListener('click',async()=>{
    if(userJobs.length)return;
    button.disabled=true;
    try{
      const profile=context.backoffice?(context.selected_expert||'christian'):(context.claims_profile||'christian');
      userJobs=[];
      userJobs.push((await post('enqueue',{profile})).job.id);
      show('Importauftrag wurde an die zentrale Importstation übergeben.');
      watch();
    }catch(e){button.disabled=false;userJobs=[];show(e.message,true)}
  });

  async function launch(job,resumed=false){
    busy=true;agentJob=job;
    lastRuntime={phase:resumed?'CF-RECOVER':'CF-CLAIMED',message:resumed?'Unterbrochener Import wird wiederaufgenommen.':'Import wird im Browser gestartet.',current:Number(job.progress_current||0),total:Number(job.progress_total||0),diagnostic:{attempt:Number(job.attempt_count||1),resumed}};
    await heartbeat();
    const target=job.profile==='jens'?'christian':job.profile;
    await json('/intern/api/google-drive-sync.php?action=select_expert',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({expert:target})});
    window.postMessage({type:'SVNET_CLAIMS_IMPORT_START',profile:job.profile,jobId:Number(job.id),runId:`claimsforce-${job.id}-${job.attempt_count||1}`},location.origin);
  }

  async function poll(){
    if(!context.claims_agent||!bridge||busy){setTimeout(poll,3000);return}
    try{
      await post('schedule');
      const active=await post('active');
      if(active.job)show(`Import ${active.job.id} ist noch als laufend markiert und wird nicht automatisch neu gestartet.`,true);
      else{
        const claimed=await post('claim');
        if(claimed.job)await launch(claimed.job,false);
      }
    }catch(e){show('Zentrale Importstation: '+e.message,true);agentJob=null;busy=false}
    setTimeout(poll,3000);
  }

  async function completeAgent(ok,result,error){
    if(!agentJob)return;
    const id=agentJob.id;
    try{await post('complete',{id,ok,result:result||null,message:ok?`${result?.claims||0} Aufträge geprüft · ${result?.updated||0} aktualisiert · ${result?.skipped||0} unverändert übersprungen.`:(error||'ClaimsForce-Import fehlgeschlagen.')})}
    catch(e){show(`Import ${id}: Abschlussstatus konnte nicht gespeichert werden (${e.message}).`,true)}
    agentJob=null;busy=false;lastRuntime={phase:'CF-IDLE',message:'Importstation wartet.',current:0,total:0,diagnostic:{}};
    await resumeWatch();
  }

  window.addEventListener('message',async e=>{
    if(e.source!==window||e.origin!==location.origin)return;
    const d=e.data||{},runtime=d.runtime||{};
    if(d.type==='SVNET_CLAIMS_BRIDGE_READY'){
      bridgeVersion=String(d.version||'0.0.0');
      bridge=versionAtLeast(bridgeVersion,'1.3.6');
      if(!bridge)show(`Browser-Brücke 1.3.6 erforderlich (geladen: ${bridgeVersion}). Bitte die Erweiterung aktualisieren.`,true);
    }
    if(d.type==='SVNET_CLAIMS_RUNTIME_STATUS'&&agentJob){
      const active=d.status?.active||{},diag=d.status?.diagnostic||{};
      if(Number(active.jobId||0)===Number(agentJob.id)){
        lastRuntime={phase:diag.phase||active.phase||'CF-RUN',message:diag.text||active.error||'Browserlauf wird fortgesetzt.',current:Number(diag.details?.current||0),total:Number(diag.details?.total||0),diagnostic:diag.details||{}};
        show(`[${lastRuntime.phase}] ${lastRuntime.message}`,active.status==='failed');
        await heartbeat();
      }
    }
    if(!agentJob)return;
    if(runtime.jobId&&Number(runtime.jobId)!==Number(agentJob.id))return;
    if(d.type==='SVNET_CLAIMS_IMPORT_ACCEPTED'){
      lastRuntime={phase:'CF-ACCEPTED',message:runtime.resumed?'Browserlauf wurde wiederaufgenommen.':'Browserlauf wurde angenommen.',current:0,total:0,diagnostic:{runId:runtime.runId||'',resumed:!!runtime.resumed}};
      await heartbeat();
    }
    if(d.type==='SVNET_CLAIMS_IMPORT_PROGRESS'){
      lastRuntime={phase:runtime.phase||'CF-RUN',message:d.text||'Import läuft …',current:Number(d.current||0),total:Number(d.total||0),diagnostic:runtime.details||{}};
      await heartbeat();
    }
    if(d.type==='SVNET_CLAIMS_IMPORT_DONE')await completeAgent(true,d.result,null);
    if(d.type==='SVNET_CLAIMS_IMPORT_ERROR')await completeAgent(false,null,d.error);
  });

  setInterval(heartbeat,20000);
  setInterval(()=>window.postMessage({type:'SVNET_CLAIMS_RUNTIME_PING'},location.origin),5000);
  json('/intern/api/google-drive-sync.php?action=status').then(async d=>{context=d;window.postMessage({type:'SVNET_CLAIMS_BRIDGE_PING'},location.origin);await resumeWatch();poll()}).catch(e=>show(e.message,true));
})();
