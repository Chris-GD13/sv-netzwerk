(()=>{
  const old=document.getElementById('vf-claims-import'),state=document.getElementById('vf-claims-state'),settings=document.getElementById('vf-claims-settings'),download=document.getElementById('vf-claims-download');
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
  let context={},bridge=false,bridgeVersion='',agentJob=null,userJobs=[],busy=false,reconciling=false,lastRuntime={phase:'CF-IDLE',message:'Importstation wartet.',current:0,total:0,diagnostic:{}},reconciledJobs=new Set();
  const json=async(url,o={})=>{const r=await fetch(url,{credentials:'same-origin',...o}),j=await r.json().catch(()=>({}));if(!r.ok||!j.ok)throw Error(j.error||`HTTP ${r.status}`);return j};
  const driveStatus=()=>window.svnetDriveStatus?window.svnetDriveStatus():json('/intern/api/google-drive-sync.php?action=status');
  const post=(a,d={})=>json('/intern/api/claimsforce-queue.php?action='+a,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(d)});
  const show=(t,b=false)=>{state.textContent=t;state.className='vf-meta '+(b?'vf-claims-bad':'')};
  const supportedProfiles=['christian','holger','marc','jens'];
  const minimumBridgeVersion='1.3.18',currentBridgeVersion='1.3.18';
  const selectedProfile=()=>{
    const raw=String(context.backoffice?(context.selected_expert||'christian'):context.claims_profile||'').trim().toLowerCase();
    if(!supportedProfiles.includes(raw))throw Error('Kein gültiges Bearbeiterprofil ausgewählt.');
    return raw;
  };
  const versionAtLeast=(actual,required)=>{const a=String(actual).split('.').map(Number),r=String(required).split('.').map(Number);for(let i=0;i<3;i++){if((a[i]||0)!==(r[i]||0))return(a[i]||0)>(r[i]||0)}return true};
  const resultOf=job=>{try{return typeof job.result==='string'?JSON.parse(job.result):job.result||{}}catch{return{}}};
  const browserRuntime=()=>{const [status='',phase='',jobId='0',profile='']=String(document.documentElement.getAttribute('data-svnet-claims-runtime')||'').split('|');return{status,phase,jobId:Number(jobId||0),profile}};
  const heartbeat=()=>agentJob?post('heartbeat',{id:agentJob.id,message:lastRuntime.message,phase:lastRuntime.phase,current:lastRuntime.current,total:lastRuntime.total,diagnostic:lastRuntime.diagnostic}).catch(()=>{}):Promise.resolve();

  async function watch(){
    if(!userJobs.length)return;
    try{
      const jobs=await Promise.all(userJobs.map(async id=>(await json('/intern/api/claimsforce-queue.php?action=status&id='+id)).job));
      const failed=jobs.some(job=>job.status==='failed');
      show(jobs.map(job=>job.message||'Import läuft …').join(' · '),failed);
      if(jobs.every(job=>['done','failed'].includes(job.status))){button.disabled=false;userJobs=[];window.dispatchEvent(new CustomEvent('svnet:claims-summary-update'));return}
    }catch(e){show(e.message,true)}
    setTimeout(watch,3000);
  }

  async function resumeWatch(){
    try{
      const recent=await json('/intern/api/claimsforce-queue.php?action=mine');
      userJobs=(recent.jobs||[]).filter(job=>['queued','running'].includes(job.status)).map(job=>Number(job.id));
      if(userJobs.length){button.disabled=true;watch();return}
      button.disabled=false;
      const terminal=(recent.jobs||[]).filter(job=>['done','failed'].includes(job.status)).slice(0,4);
      if(terminal.length){const compact=terminal.map(job=>`${job.id}|${job.profile}|${job.status}|${job.phase||'CF-STATUS'}`).join(';');document.documentElement.setAttribute('data-svnet-claims-jobs',compact);const results=terminal.map(job=>{const r=resultOf(job);return`${job.id}|${Number(r.claims||0)}|${Number(r.updated||0)}|${Number(r.skipped||0)}|${Number(r.files||0)}|${Number(r.messages||0)}|${Number(r.appointments||0)}`}).join(';');document.documentElement.setAttribute('data-svnet-claims-results',results);const text=terminal.map(job=>`${job.profile}: ${job.status==='done'?'abgeschlossen':'fehlgeschlagen'} [${job.phase||'CF-STATUS'}] – ${job.message||'ohne Meldung'}`).join(' · '),failed=terminal.some(job=>job.status==='failed');show(text,failed);window.dispatchEvent(new CustomEvent('svnet:claims-summary-update'));setTimeout(()=>show(text,failed),1000)}
    }catch(e){show('Importstatus konnte nicht wiederhergestellt werden: '+e.message,true)}
  }

  button.addEventListener('click',async()=>{
    if(userJobs.length)return;
    if(context.claims_agent&&!bridge){show(`Diese zentrale Importstation ist nicht bereit. Browser-Brücke ${minimumBridgeVersion} oder neuer erforderlich (geladen: ${bridgeVersion||'nicht erkannt'}).`,true);return}
    button.disabled=true;
    try{
      const profile=selectedProfile();
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
    const target=String(job.profile||'').trim().toLowerCase();
    if(!supportedProfiles.includes(target))throw Error('Importauftrag enthält ein ungültiges Bearbeiterprofil.');
    sessionStorage.removeItem('svnet-case');
    localStorage.removeItem('svnet-case');
    await json('/intern/api/google-drive-sync.php?action=select_expert',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({expert:target})});
    context.selected_expert=target;
    window.postMessage({type:'SVNET_CLAIMS_IMPORT_START',profile:job.profile,jobId:Number(job.id),runId:`claimsforce-${job.id}-${job.attempt_count||1}`},location.origin);
  }

  async function poll(){
    if(!context.claims_agent||!bridge||busy){setTimeout(poll,3000);return}
    try{
      await post('schedule');
      const active=await post('active');
      if(active.job){
        const runtime=browserRuntime();
        if(runtime.status==='failed'&&runtime.jobId===Number(active.job.id)){
          await post('complete',{id:Number(active.job.id),ok:false,result:null,message:`Browserlauf wurde abgebrochen (${runtime.phase||'CF-RUNTIME'}).`});
          show(`Import ${active.job.id} wurde nach einem abgebrochenen Browserlauf sicher beendet.`,true);
          await resumeWatch();
        }else show(`Import ${active.job.id} ist noch als laufend markiert und wird nicht automatisch neu gestartet.`,true);
      }
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
    try{await post('complete',{id,ok,result:result||null,message:ok?`${result?.claims||0} Aufträge geprüft · ${result?.updated||0} aktualisiert · ${result?.skipped||0} unverändert übersprungen.`:(error||'ClaimsForce-Import fehlgeschlagen.')});if(ok)window.dispatchEvent(new CustomEvent('svnet:claims-summary-update'))}
    catch(e){show(`Import ${id}: Abschlussstatus konnte nicht gespeichert werden (${e.message}).`,true)}
    agentJob=null;busy=false;lastRuntime={phase:'CF-IDLE',message:'Importstation wartet.',current:0,total:0,diagnostic:{}};
    await resumeWatch();
  }

  window.addEventListener('message',async e=>{
    if(e.source!==window||e.origin!==location.origin)return;
    const d=e.data||{},runtime=d.runtime||{};
    if(d.type==='SVNET_CLAIMS_BRIDGE_READY'){
      bridgeVersion=String(d.version||'0.0.0');
      bridge=versionAtLeast(bridgeVersion,minimumBridgeVersion);
      if(!bridge)show(`Browser-Brücke ${minimumBridgeVersion} oder neuer erforderlich (geladen: ${bridgeVersion}).`,true);
      else if(!versionAtLeast(bridgeVersion,currentBridgeVersion))show(`Browser-Brücke ${bridgeVersion} ist einsatzbereit. Version ${currentBridgeVersion} steht als empfohlenes Update bereit.`);
    }
    if(d.type==='SVNET_CLAIMS_RUNTIME_STATUS'&&!agentJob&&!reconciling){
      const active=d.status?.active||{},diag=d.status?.diagnostic||{};
      if(active.status==='failed'&&Number(active.jobId||0)>0&&!reconciledJobs.has(Number(active.jobId))){
        reconciledJobs.add(Number(active.jobId));
        reconciling=true;
        try{
          await post('complete',{id:Number(active.jobId),ok:false,result:null,message:`Browserlauf wurde abgebrochen (${diag.phase||active.phase||'CF-RUNTIME'}).`});
          show(`Import ${active.jobId} wurde nach einem abgebrochenen Browserlauf sicher beendet.`,true);
          await resumeWatch();
        }catch(error){if(!/bereits abgeschlossen|erneut eingeplant/i.test(error.message))show(`Import ${active.jobId}: Abbruchstatus konnte nicht gespeichert werden (${error.message}).`,true)}
        finally{reconciling=false}
      }
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
  driveStatus().then(async d=>{
    context=d;
    const personalBridgeBlocked=!d.backoffice&&supportedProfiles.includes(String(d.claims_profile||''));
    if(personalBridgeBlocked){
      settings?.remove();
      download?.remove();
      show('Dein ClaimsForce-Import wird zentral ausgeführt. Auf diesem Rechner ist keine Browser-Brücke erforderlich.');
    }
    window.postMessage({type:'SVNET_CLAIMS_BRIDGE_PING'},location.origin);
    await resumeWatch();
    poll();
  }).catch(e=>show(e.message,true));
})();
