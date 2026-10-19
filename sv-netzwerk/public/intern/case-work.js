(() => {
  const setup = () => {
    if (document.getElementById('vf-assistant') && window.svnetSetupAssistant) { window.svnetSetupAssistant(); }
    const el = id => document.getElementById(id);
    const start=el('vf-analysis-start'), refresh=el('vf-analysis-refresh'), state=el('vf-analysis-state');
    const historyBox=el('vf-work-history'), order=el('vf-analysis-order'), files=el('vf-analysis-files');
    const reload=el('vf-work-reload');
    const recovery=el('vf-work-import-help');
    if (!start || !historyBox) return;
    const taskId=new URLSearchParams(location.search).get('aufgabe') || '';
    let folder='', epoch=0, timer=null, busy=false, lastRender='', turns=[];
    const active=()=>window.svnetOpenedClaimsCase?._loaded===true?window.svnetOpenedClaimsCase:null;
    const pendingKey=id=>'svnet-work-pending:'+String(localStorage.getItem('svnet-case-owner')||'')+':'+id;
    const pending=id=>{try{return JSON.parse(sessionStorage.getItem(pendingKey(id))||'null');}catch{return null;}};
    const savePending=(id,value)=>value?sessionStorage.setItem(pendingKey(id),JSON.stringify(value)):sessionStorage.removeItem(pendingKey(id));
    const api=async(body,url='/intern/api/case-work.php',options={})=>{
      const control=new AbortController(), timeout=setTimeout(()=>control.abort(),45000);
      try {
        const response=await fetch(url,{credentials:'same-origin',method:'POST',signal:control.signal,headers:{'Content-Type':'application/json'},body:JSON.stringify(body),...options});
        const data=await response.json();
        if(!response.ok||data.ok===false)throw Error(data.error||`HTTP ${response.status}`);
        return data;
      } catch(error) {if(error.name==='AbortError')throw Error('Verbindung unterbrochen. Der Auftrag kann weiterlaufen; Status aktualisieren, nicht erneut anlegen.');throw error;}
      finally {clearTimeout(timeout);}
    };
    const notice=(text,bad=false)=>{state.textContent=text;state.className='vf-meta'+(bad?' vf-claims-bad':'');};
    const requireCase=()=>{const current=active();if(!current)notice('Kein Fall geladen. Bitte den gewünschten Fall suchen und mit Öffnen laden. Deine Eingaben und ausgewählten Anhänge bleiben zunächst erhalten.',true);return current;};
    const button=(label,handler)=>{const b=document.createElement('button');b.type='button';b.className='vf-secondary';b.textContent=label;b.onclick=handler;return b;};
    const para=(parent,text)=>{const p=document.createElement('p');p.style.whiteSpace='pre-wrap';p.textContent=text||'';parent.append(p);};
    const details=(parent,label,items)=>{if(!items?.length)return;const d=document.createElement('details'),s=document.createElement('summary');s.textContent=label;d.append(s);for(const item of items)para(d,item);parent.append(d);};
    const render=()=>{
      const fingerprint=JSON.stringify(turns);if(fingerprint===lastRender)return;lastRender=fingerprint;
      historyBox.replaceChildren();
      turns.forEach((turn,index)=>{
        const previousFailure=turn.status==='failed'&&index<turns.length-1;
        const article=document.createElement(previousFailure?'details':'article');article.className='vf-work-turn';
        const heading=document.createElement(previousFailure?'summary':'h3');heading.textContent=(previousFailure?'Früherer abgebrochener Versuch · ':'Dein Auftrag · ')+turn.created_at;article.append(heading);para(article,turn.instructions);
        const result=turn.result;
        if(result){
          const answer=document.createElement('div');answer.className='vf-work-answer';
          para(answer,result.summary);para(answer,result.reply_draft);
          answer.append(button('Vollständigen Text kopieren',async()=>{try{await navigator.clipboard.writeText(result.reply_draft);notice('Text kopiert.');}catch(error){notice(error.message,true);}}));
          if(!turn.document_id&&!result.document_requests?.length)answer.append(button('Diesen Text als Word speichern',async()=>{
            const current=active(),version=epoch;if(current?.folder_id!==folder)return;
            try{await api({action:'documents',folder_id:folder,turn_id:turn.id,as_text:true});if(version===epoch)await poll(current,version);}catch(error){notice(error.message,true);}
          }));
          answer.append(button('Als E-Mail vorbereiten',()=>{
            if(active()?.folder_id!==folder)return;
            const target=el('vf-mail-body'), subject=el('vf-mail-subject');
            if(!target){notice('E-Mail-Feld ist nicht verfügbar.',true);return;}
            if(target.value.trim()&&!confirm('Vorhandenen E-Mail-Entwurf ersetzen?'))return;
            target.value=result.reply_draft;target.dispatchEvent(new Event('input',{bubbles:true}));
            const number=String(result.case_no||active()?.meta?.schaden_nr||'').trim();
            if(subject)subject.value=String(result.subject||'').startsWith(number)?result.subject:number+' – '+(result.subject||'Schadenbearbeitung');
            const card=target.closest('.vf-card');card?.classList.remove('is-collapsed');card?.querySelector(':scope > .vf-collapse-trigger')?.setAttribute('aria-expanded','true');
            target.scrollIntoView({behavior:'smooth',block:'center'});target.focus();
            notice('Entwurf übernommen. Empfänger und Originalanhänge im Mailformular ergänzen; noch nicht versendet.');
          }));
          details(answer,'Einzelne Anliegen',(result.task_results||[]).map(item=>item.request+'\n'+item.answer));
          details(answer,'Offene Punkte',result.open_points);
          details(answer,'Fachliche Bewertung und nächste Schritte',[result.assessment,...(result.next_steps||[])]);
          details(answer,'Quellen und angewendete Vorgaben',[...(result.facts||[]),...(result.rule_checks||[]),...(result.sources||[]).map(s=>s.name),...(result.rules||[]).map(r=>r.path+' · '+r.modified_at)]);
          const docs=document.createElement('div');docs.id='vf-work-docs-'+turn.id;answer.append(docs);
          if(result.document_requests?.length&&!turn.document_id)para(docs,'Benötigte Dokumente werden als eigener Arbeitsschritt erstellt.');
          article.append(answer);
        }else para(article,turn.message||'Bearbeitung läuft …');
        historyBox.append(article);
      });
    };
    const checkDocs=async(turn,current,version)=>{
      let id=turn.document_id;
      if(!id&&turn.result?.document_requests?.length){
        const created=await api({action:'documents',folder_id:current.folder_id,turn_id:turn.id});id=created.job_id;turn.document_id=id;
      }
      if(!id)return false;
      const data=await api({action:'status',job_id:Number(id)},'/intern/api/gf-ai-generate.php?legacy=1');
      if(version!==epoch)return false;
      const box=el('vf-work-docs-'+turn.id);if(!box)return false;box.replaceChildren();
      const job=data.job;if(job.folder_id!==current.folder_id)throw Error('Dokumentauftrag gehört zu einem anderen Fall.');
      if(job.status==='done'){
        para(box,'Dokumente im Fall gespeichert:');
        for(const file of job.result?.created||[]){const a=document.createElement('a');a.textContent=file.name;a.href='/intern/api/case-file-browser.php?action=download&folder_id='+encodeURIComponent(current.folder_id)+'&file_id='+encodeURIComponent(file.id);a.className='vf-secondary';box.append(a);}
        return false;
      }
      if(job.status==='failed'||job.recoverable){para(box,'Dokumenterstellung unterbrochen: '+(job.error_text||job.message));return false;}
      para(box,job.message||'Dokumente werden erstellt …');return true;
    };
    const advanceImport=async(current,version,work)=>{
      if(work.reload===false){
        await api({action:'start',folder_id:current.folder_id,task_id:work.task_id,instructions:work.instructions,request_key:work.request_key,source_mode:'portal'});
        if(version===epoch)savePending(current.folder_id,null);return true;
      }
      if(!work.import_id){
        if(typeof window.svnetClaimsReloadCase!=='function')throw Error('Claims-Import wird noch geladen. Bitte Status aktualisieren.');
        const job=await window.svnetClaimsReloadCase(current.meta?.schaden_nr);work.import_id=job.id;savePending(current.folder_id,work);
      }
      const data=await api(null,'/intern/api/claimsforce-queue.php?action=status&id='+encodeURIComponent(work.import_id),{method:'GET',body:undefined});
      if(version!==epoch)return false;
      const job=data.job;
      if(job.status==='failed')throw Error('Claims-Nachladen fehlgeschlagen: '+(job.message||'Unbekannter Fehler')+'. Der Arbeitsauftrag bleibt erhalten.');
      if(job.status!=='done'){if(recovery)recovery.hidden=false;notice('Wartet auf Claims-Import: '+(job.message||'Die Büro-Importstation ist erforderlich.')+' Du kannst unten mit dem vorhandenen Portalbestand weiterarbeiten.');return true;}
      notice('Import abgeschlossen. Fallunterlagen werden jetzt erneut gelesen.');
      await api({action:'start',folder_id:current.folder_id,task_id:work.task_id,instructions:work.instructions,request_key:work.request_key,source_mode:'claims',import_id:work.import_id});
      if(version===epoch)savePending(current.folder_id,null);
      return true;
    };
    const poll=async(current,version)=>{
      clearTimeout(timer);
      try{
        const work=pending(current.folder_id);
        if(work){busy=true;start.disabled=true;if(recovery)recovery.hidden=work.reload===false&&!work.import_id;await advanceImport(current,version,work);if(version!==epoch)return;}
        const data=await api({action:'history',folder_id:current.folder_id});if(version!==epoch)return;
        turns=data.turns||[];render();
        let docsRunning=false;
        for(const turn of turns)if(turn.result&&(turn.document_id||turn.result.document_requests?.length))docsRunning=(await checkDocs(turn,current,version))||docsRunning;
        if(version!==epoch)return;
        const running=turns.find(t=>t.status==='running');busy=!!running||!!pending(current.folder_id);
        if(recovery)recovery.hidden=!pending(current.folder_id);
        start.disabled=busy;start.textContent=pending(current.folder_id)?'Wartet auf Claims-Import':busy?'Bearbeitung läuft …':turns.length?'Auftrag ausführen':'Bitte abarbeiten';
        if(!pending(current.folder_id))notice(running?.message||(docsRunning?'Dokumente werden erstellt …':turns.at(-1)?.status==='failed'?turns.at(-1).message:'Bereit. Du kannst einen neuen Auftrag oder eine Korrektur schreiben.'),turns.at(-1)?.status==='failed');
        if(busy||docsRunning)timer=setTimeout(()=>poll(current,version),4000);
      }catch(error){if(version!==epoch)return;notice(error.message,true);start.disabled=!!pending(current.folder_id);busy=false;}
    };
    const sync=()=>{
      const current=active(),id=current?.folder_id||'';if(id===folder)return;
      folder=id;epoch++;clearTimeout(timer);busy=false;turns=[];lastRender='';historyBox.replaceChildren();order.value='';files.value='';
      const work=id?pending(id):null;
      start.disabled=!id||!!work;refresh.disabled=!id;reload.checked=false;if(recovery)recovery.hidden=!work;
      if(work)order.value=work.instructions;
      start.textContent=work?'Auftrag wartet auf Claims-Import':'Auftrag mit Portalbestand ausführen';
      notice(work?'Ein gespeicherter Auftrag wartet auf Claims. Status prüfen oder mit dem vorhandenen Portalbestand weiterarbeiten.':id?'Bereit für den vorhandenen Portalbestand – auch ohne Büro-Importstation. Ein neuer Claims-Abgleich ist optional und benötigt die Station.':'Bitte zuerst einen Fall öffnen.');
    };
    start.onclick=async()=>{
      const current=requireCase();if(!current)return;if(busy){notice('Die Bearbeitung läuft bereits. Mit Status aktualisieren kannst du den Fortschritt prüfen.');return;}
      const version=epoch;busy=true;start.disabled=true;
      notice('Arbeitsauftrag wird vorbereitet …');
      const instructions=order.value.trim()||'Bitte abarbeiten',requestKey=crypto.randomUUID();
      try{
        for(const file of Array.from(files.files)){
          if(file.size>30*1024*1024)throw Error('Maximal 30 MB je Unterlage.');
          const form=new FormData();form.append('folder_id',current.folder_id);form.append('file',file);
          notice('Unterlage wird im Fall gespeichert: '+file.name);
          await api(null,'/intern/api/google-drive-sync.php?action=upload_case_document',{headers:{},body:form});
          if(version!==epoch)return;
        }
        files.value='';
        savePending(current.folder_id,{instructions,request_key:requestKey,task_id:taskId,reload:reload.checked});
        if(version!==epoch)return;order.value='';reload.checked=false;await poll(current,version);
      }catch(error){if(version!==epoch)return;notice(error.message,true);busy=false;start.disabled=false;}
    };
    refresh.onclick=()=>{const current=requireCase();if(current)poll(current,epoch);};
    el('vf-work-import-retry')?.addEventListener('click',()=>{const current=requireCase();if(!current)return;const work=pending(current.folder_id);if(work){delete work.import_id;savePending(current.folder_id,work);poll(current,epoch);}else{reload.checked=true;notice('Nachladen für den nächsten Arbeitsauftrag aktiviert.');}});
    el('vf-work-local')?.addEventListener('click',async()=>{
      const current=requireCase();if(!current)return;const work=pending(current.folder_id);if(!work){reload.checked=false;await start.onclick();return;}const version=++epoch;
      clearTimeout(timer);
      // Persist the chosen source mode before sending. An uncertain response is
      // retried with the same request key and never queues a second import.
      work.reload=false;savePending(current.folder_id,work);
      try{await api({action:'start',folder_id:current.folder_id,task_id:work.task_id,instructions:work.instructions,request_key:work.request_key,source_mode:'portal'});if(version!==epoch)return;savePending(current.folder_id,null);if(recovery)recovery.hidden=true;poll(current,version);}catch(error){if(version===epoch)notice(error.message,true);}
    });
    document.querySelectorAll('[data-work-prompt]').forEach(b=>b.addEventListener('click',()=>{order.value=b.dataset.workPrompt;order.focus();}));
    const observer=new MutationObserver(sync);if(el('vf-active'))observer.observe(el('vf-active'),{childList:true,subtree:true});
    window.addEventListener('storage',sync);window.addEventListener('pagehide',()=>{epoch++;clearTimeout(timer);observer.disconnect();});sync();
  };
  document.readyState==='loading'?document.addEventListener('DOMContentLoaded',setup,{once:true}):setup();
})();
