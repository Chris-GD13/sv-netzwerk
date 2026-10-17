/* Same Fall-Work panel, explicit user actions only. No timers or storage-derived identity. */
window.svnetSetupAssistant = () => {
  const el=id=>document.getElementById(id), tab=crypto.randomUUID();
  let generation=0, context=null, job=null, pendingKey=null, busy=false, controller=null, openedKey=null;
  const buttons=[...document.querySelectorAll('[data-assistant-task]')];
  const state=text=>{el('vf-analysis-state').textContent=text;};
  const enable=()=>{buttons.forEach(b=>b.disabled=!context||busy);el('vf-analysis-refresh').disabled=!context||!job||busy;el('vf-assistant-history').disabled=!context||busy;el('vf-assistant-resume').disabled=!context||!job||busy;el('vf-assistant-cancel').disabled=!context||!job||busy;el('vf-work-route').disabled=busy;};
  const clear=()=>{const previous=context;generation++;controller?.abort();controller=null;context=null;openedKey=null;job=null;pendingKey=null;busy=false;el('vf-work-history').replaceChildren();el('vf-analysis-order').value='';el('vf-assistant-context').textContent='Kein bestätigter Fallkontext.';enable();
    if(previous)fetch('/intern/api/case-work.php?assistant=1',{method:'POST',credentials:'same-origin',keepalive:true,headers:{'Content-Type':'application/json','X-SV-Assistant':'1'},body:JSON.stringify({action:'assistant_close',token:previous.token,tab_id:tab})}).catch(()=>{});
  };
  async function api(action,extra={},version=generation){
    const token=context?.token;const c=new AbortController();controller=c;
    const r=await fetch('/intern/api/case-work.php?assistant=1',{method:'POST',credentials:'same-origin',signal:c.signal,headers:{'Content-Type':'application/json','X-SV-Assistant':'1'},body:JSON.stringify({action,token,tab_id:tab,...extra})});
    const body=await r.json();if(version!==generation)throw Error('STALE_RESPONSE');
    if(!r.ok||!body.ok){
      if(['CONTEXT_INVALID','CASE_CHANGED','RULE_RELEASE_CHANGED','CASE_FORBIDDEN','USER_FORBIDDEN'].includes(body.error)){clear();state('Fall-/Regelstand oder Berechtigung geändert. Fall erneut öffnen.');}
      const messages={CASE_BUSY:'Für diesen Fall läuft bereits ein Auftrag oder eine offene Work-Übergabe. Gespeicherten Auftrag laden und prüfen oder die Übergabe beenden.',MONTHLY_BUDGET_EXCEEDED:'Das Monatsbudget ist ausgeschöpft oder bereits reserviert. Es wurde kein neuer Auftrag gestartet.',IDEMPOTENCY_CONFLICT:'Die Wiederholung enthält eine geänderte Anweisung. Bitte den vorhandenen Auftrag prüfen.',ASSISTANT_NOT_ENABLED:'Der SV-Assistent ist auf diesem Server noch nicht freigeschaltet.'};
      const error=Error(messages[body.error]||body.error||'Auftrag fehlgeschlagen');error.code=body.error;throw error;
    }
    if(body.budget){const b=body.budget;el('vf-assistant-budget').textContent=`Simulation · Monatslimit ${(Number(b.limit_micro)/1e6).toFixed(2)} € · verbraucht ${(Number(b.spent_micro)/1e6).toFixed(2)} € · reserviert ${(Number(b.reserved_micro)/1e6).toFixed(2)} € · echte Kosten 0 €`;}
    return body.data;
  }
  const render=result=>{
    el('vf-work-history').replaceChildren();if(!result)return;
    const p=document.createElement('pre');
    const taskText=result.action==='tasks'?['Offene Aufgaben:',...(result.tasks||[]).map(t=>'• '+t.task)]:[];
    const register=result.action==='invoices'?['Belegregister:',...(result.invoice_register||[]).map(d=>`${d.id}: ${d.amount_cents===null?'Betrag offen':(d.amount_cents/100).toFixed(2)+' EUR'} · ${d.superseded?'ersetzt':d.duplicate_of?'Dublette von '+d.duplicate_of:d.verified?'belegt':'ungeprüft'}`)]:[];
    p.textContent=result.draft||[result.notice,...taskText,...register,...(result.findings||[]),...(result.action==='tasks'?[]:result.open_points||[]),`Belegsumme: ${((result.total_cents||0)/100).toFixed(2)} EUR`,result.action==='custom'?'Individuelle Anweisung erfasst (keine semantische KI im Mock): '+result.instruction_recorded:''].join('\n');el('vf-work-history').append(p);
    if(result.handoff){const pre=document.createElement('pre');pre.textContent=JSON.stringify(result.handoff,null,2);el('vf-work-history').append(pre);const b=document.createElement('button');b.type='button';b.textContent='Übergabe für Work kopieren';b.onclick=()=>navigator.clipboard.writeText(pre.textContent).then(()=>state('Übergabe kopiert. In Work denselben Fall und Regelstand prüfen.'));el('vf-work-history').append(b);}
  };
  async function run(version){
    for(let i=0;i<2;i++){const r=await api('assistant_step',{job_id:job},version);state(`Auftrag ${r.id}: ${r.status}`);render(r.result);if(['done','handoff','failed','cancelled','unknown'].includes(r.status))break;}
  }
  window.addEventListener('svnet:case-opened',async event=>{
    const current=event.detail;
    if(current?._loaded!==true||!current?.folder_id||!current.meta?.schaden_nr){clear();return;}
    const key=JSON.stringify([current.folder_id,current.meta.schaden_nr,current.meta.claimsforce_claim_id,current.meta.claimsforce_profile,current.meta.versicherer,current.meta.sparte]);
    if(openedKey===key)return;clear();openedKey=key;const version=generation;
    state('Fall und Profil werden serverseitig geprüft …');
    try{const c=await api('assistant_open',{folder_id:current.folder_id,claim_number:current.meta.schaden_nr},version);context=c;el('vf-assistant-context').textContent=`Schaden ${c.claim_number} · Profil ${c.profile} · Regelstand ${c.release} · Simulation`;state('Fall serverseitig bestätigt. Aktion auswählen.');enable();}
    catch(e){if(version===generation){openedKey=null;if(e.name!=='AbortError')state(e.message);}}
  });
  window.addEventListener('svnet:case-cleared',clear);
  window.addEventListener('storage',e=>{if(['svnet-case','svnet-case-owner'].includes(e.key))clear();});
  window.addEventListener('pagehide',clear);
  buttons.forEach(button=>button.onclick=async()=>{
    if(!context||busy)return;const version=generation;busy=true;enable();pendingKey ||= crypto.randomUUID();
    try{const r=await api('assistant_start',{request_key:pendingKey,task:button.dataset.assistantTask,instruction:el('vf-analysis-order').value,route:el('vf-work-route').value},version);job=r.job_id;pendingKey=null;await run(version);}
    catch(e){if(version===generation&&e.name!=='AbortError'){if(['CASE_BUSY','MONTHLY_BUDGET_EXCEEDED','IDEMPOTENCY_CONFLICT','ACTION_INVALID'].includes(e.code))pendingKey=null;state(e.message+(!e.code?' · Startstatus unklar: denselben Auftrag unverändert erneut versuchen.':''));}}
    finally{if(version===generation){busy=false;enable();}}
  });
  el('vf-assistant-history').onclick=async()=>{const v=generation;if(!context||busy)return;busy=true;enable();try{const rows=await api('assistant_history',{},v);if(!rows.length){state('Keine gespeicherten Assistentenaufträge.');return;}job=Number(rows[0].id);enable();await api('assistant_recover',{job_id:job},v);enable();state('Auftrag geladen. Status prüfen oder Wiederaufnehmen wählen.');}catch(e){if(v===generation)state(e.message);}finally{if(v===generation){busy=false;enable();}}};
  el('vf-analysis-refresh').onclick=async()=>{const v=generation;if(!context||busy)return;busy=true;enable();try{const r=await api('assistant_status',{job_id:job},v);state(`Auftrag ${r.id}: ${r.status}`);render(r.result);}catch(e){if(v===generation)state(e.message);}finally{if(v===generation){busy=false;enable();}}};
  el('vf-assistant-resume').onclick=async()=>{const v=generation;if(busy)return;busy=true;enable();try{const s=await api('assistant_status',{job_id:job},v);if(s.status==='paused')await api('assistant_resume',{job_id:job},v);await run(v);}catch(e){if(v===generation)state(e.message);}finally{if(v===generation){busy=false;enable();}}};
  el('vf-assistant-cancel').onclick=async()=>{const v=generation;if(!context||busy)return;busy=true;enable();try{await api('assistant_cancel',{job_id:job},v);state('Auftrag beendet.');job=null;render(null);enable();}catch(e){if(v===generation)state(e.message);}finally{if(v===generation){busy=false;enable();}}};
  clear(); // Opening panel never loads a case, imports notes, polls, or runs a model.
};
