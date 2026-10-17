/* Same Fall-Work panel, explicit user actions only. No timers or storage-derived identity. */
window.svnetSetupAssistant = () => {
  const el=id=>document.getElementById(id), tab=crypto.randomUUID();
  let generation=0, context=null, job=null, pendingKey=null, busy=false, controller=null, openedKey=null, candidate=null, sandbox=false;
  const buttons=[...document.querySelectorAll('[data-assistant-task]')];
  const state=text=>{el('vf-assistant-state').textContent=text;};
  const enable=()=>{el('vf-assistant-demo').disabled=busy;el('vf-assistant-open').disabled=!candidate||busy;el('vf-assistant-connections').disabled=busy;buttons.forEach(b=>b.disabled=!context||busy);el('vf-assistant-refresh').disabled=!context||!job||busy;el('vf-assistant-history').disabled=!context||busy;el('vf-assistant-resume').disabled=!context||!job||busy;el('vf-assistant-cancel').disabled=!context||!job||busy;el('vf-assistant-route').disabled=busy;};
  const clear=()=>{const previous=context,previousSandbox=sandbox;sandbox=false;generation++;controller?.abort();controller=null;context=null;openedKey=null;candidate=null;job=null;pendingKey=null;busy=false;el('vf-assistant-results').replaceChildren();el('vf-assistant-order').value='';el('vf-assistant-context').textContent='Kein bestätigter Fallkontext.';enable();
    if(previous)fetch('/intern/api/case-work.php?assistant=1',{method:'POST',credentials:'same-origin',keepalive:true,headers:{'Content-Type':'application/json','X-SV-Assistant':'1'},body:JSON.stringify({action:'assistant_close',token:previous.token,tab_id:tab,sandbox:previousSandbox})}).catch(()=>{});
  };
  async function api(action,extra={},version=generation){
    const token=context?.token;const c=new AbortController();controller=c;
    const r=await fetch('/intern/api/case-work.php?assistant=1',{method:'POST',credentials:'same-origin',signal:c.signal,headers:{'Content-Type':'application/json','X-SV-Assistant':'1'},body:JSON.stringify({action,token,tab_id:tab,sandbox,...extra})});
    const body=await r.json();if(version!==generation)throw Error('STALE_RESPONSE');
    if(!r.ok||!body.ok){
      if(r.status===401||r.status===403||['CONTEXT_INVALID','CASE_CHANGED','RULE_RELEASE_CHANGED','CASE_FORBIDDEN','USER_FORBIDDEN'].includes(body.error)){clear();state('Fall-/Regelstand oder Berechtigung geändert. Fall erneut öffnen.');}
      const messages={CASE_BUSY:'Für diesen Fall läuft bereits ein Auftrag oder eine offene Work-Übergabe. Gespeicherten Auftrag laden und prüfen oder die Übergabe beenden.',MONTHLY_BUDGET_EXCEEDED:'Das Monatsbudget ist ausgeschöpft oder bereits reserviert. Es wurde kein neuer Auftrag gestartet.',IDEMPOTENCY_CONFLICT:'Die Wiederholung enthält eine geänderte Anweisung. Bitte den vorhandenen Auftrag prüfen.',ASSISTANT_NOT_ENABLED:'Die Regelanbindung ist noch nicht eingerichtet. Unter Verbindungen prüfen wird der fehlende Schritt angezeigt.',RULE_RELEASE_NOT_CONFIGURED:'Der gemeinsame freigegebene Regelstand ist noch nicht angebunden. Bitte Verbindungen prüfen.'};
      const error=Error(messages[body.error]||body.error||'Auftrag fehlgeschlagen');error.code=body.error;throw error;
    }
    if(body.budget){const b=body.budget;el('vf-assistant-budget').textContent=`${b.budget_lane==='assistant'?'Zusatzbudget (inkl. Sicherheitspuffer)':'Simulation'} · Monatslimit ${(Number(b.limit_micro)/1e6).toFixed(2)} € · verbraucht ${(Number(b.spent_micro)/1e6).toFixed(2)} € · reserviert ${(Number(b.reserved_micro)/1e6).toFixed(2)} €`;}
    return body.data;
  }
  const render=result=>{
    el('vf-assistant-results').replaceChildren();if(!result||(!result.action&&!result.notice&&!result.answer&&!result.draft))return;
    const p=document.createElement('pre');
    const taskText=result.action==='tasks'?['Offene Aufgaben:',...(result.tasks||[]).map(t=>'• '+t.task)]:[];
    const register=result.action==='invoices'?['Belegregister:',...(result.invoice_register||[]).map(d=>`${d.id}: ${d.amount_cents===null?'Betrag offen':(d.amount_cents/100).toFixed(2)+' EUR'} · ${d.superseded?'ersetzt':d.duplicate_of?'Dublette von '+d.duplicate_of:d.verified?'belegt':'ungeprüft'}`)]:[];
    p.textContent=result.answer?(result.notice||'KI-Arbeitsentwurf: fachlich prüfen.')+'\n\n'+result.answer+'\n\nOffene Punkte:\n'+(result.open_points||[]).join('\n')+'\n\nRegelbezug:\n'+(result.findings||[]).join('\n')+'\n\nQuellenprüfung:\n'+(result.source_checks||[]).join('\n'):result.draft||[result.notice,...taskText,...register,...(result.findings||[]),...(result.action==='tasks'?[]:result.open_points||[]),...(typeof result.total_cents==='number'?[`Belegsumme: ${(result.total_cents/100).toFixed(2)} EUR`]:[]),result.action==='custom'?'Individuelle Anweisung erfasst (keine semantische KI im Mock): '+result.instruction_recorded:''].join('\n');el('vf-assistant-results').append(p);
    if(result.handoff){const pre=document.createElement('pre');pre.textContent=JSON.stringify(result.handoff,null,2);el('vf-assistant-results').append(pre);const b=document.createElement('button');b.type='button';b.textContent='Übergabe für Work kopieren';b.onclick=()=>navigator.clipboard.writeText(pre.textContent).then(()=>state('Übergabe kopiert. In Work denselben Fall und Regelstand prüfen.'));el('vf-assistant-results').append(b);}
  };
  async function run(version){
    for(let i=0;i<2;i++){const r=await api('assistant_step',{job_id:job},version);state(`Auftrag ${r.id}: ${r.status}`);render(r.result);if(['done','handoff','failed','cancelled','unknown'].includes(r.status))break;}
  }
  window.addEventListener('svnet:case-opened',event=>{
    const current=event.detail;
    if(current?._loaded!==true||!current?.folder_id||!current.meta?.schaden_nr){clear();return;}
    const key=JSON.stringify([current.folder_id,current.meta.schaden_nr,current.meta.claimsforce_claim_id,current.meta.claimsforce_profile,current.meta.versicherer,current.meta.sparte]);
    if(openedKey===key)return;clear();openedKey=key;candidate=current;
    el('vf-assistant-context').textContent=`Geöffneter Schaden ${current.meta.schaden_nr} · noch nicht vom Assistenten übernommen`;
    state('Geöffneten Fall übernehmen oder Verbindungen prüfen.');enable();
  });
  el('vf-assistant-open').onclick=async()=>{
    if(!candidate||busy)return;const current=candidate,version=generation;busy=true;enable();
    state('Fall und Profil werden serverseitig geprüft …');
    try{const c=await api('assistant_open',{folder_id:current.folder_id,claim_number:current.meta.schaden_nr},version);context=c;el('vf-assistant-context').textContent=`Schaden ${c.claim_number} · Profil ${c.profile} · Regelstand ${c.release} · ${c.mode==='live'?'KI mit 30-EUR-Zusatzbudget':'Simulation'}`;state('Fall serverseitig bestätigt. Aktion auswählen.');}
    catch(e){if(version===generation&&e.name!=='AbortError')state(e.message);}
    finally{if(version===generation){busy=false;enable();}}
  };
  el('vf-assistant-demo').onclick=async()=>{
    if(busy)return;clear();sandbox=true;const version=generation;busy=true;enable();
    state('Synthetischer Testfall wird gebunden.');
    try{const c=await api('assistant_demo',{},version);context=c;el('vf-assistant-context').textContent=`TESTDATEN: ${c.claim_number} · Profil ${c.profile} · Regelstand ${c.release} · ${c.mode==='live'?'echte KI, eigenes Monatsbudget':'Simulation'}. Keine Änderung am geöffneten echten Fall.`;job=(await api('assistant_start',{request_key:crypto.randomUUID(),task:'invoices',instruction:'Prüfe ausschließlich die synthetische Rechnung und den fehlenden Zahlungsnachweis.',route:'assistant'},version)).job_id;await run(version);}
    catch(e){if(version===generation)state(e.message);}
    finally{if(version===generation){busy=false;enable();}}
  };
  el('vf-assistant-connections').onclick=async()=>{
    const version=generation;if(busy)return;busy=true;enable();
    try{const c=await api('assistant_connections',{},version);el('vf-assistant-connections-state').textContent=c.summary;el('vf-assistant-sync').hidden=!c.can_manage_rules;}
    catch(e){if(version===generation)el('vf-assistant-connections-state').textContent=e.message;}
    finally{if(version===generation){busy=false;enable();}}
  };
  el('vf-assistant-sync').onclick=async()=>{
    const version=generation;if(busy)return;busy=true;enable();
    try{const r=await api('assistant_sync_master',{},version);el('vf-assistant-connections-state').textContent=r.summary;}
    catch(e){if(version===generation)el('vf-assistant-connections-state').textContent=e.message;}
    finally{if(version===generation){busy=false;enable();}}
  };
  document.querySelectorAll('[data-case-menu]').forEach(link=>link.addEventListener('click',()=>{const panel=el(link.dataset.caseMenu);panel?.classList.remove('is-collapsed');panel?.querySelector('.vf-collapse-trigger')?.setAttribute('aria-expanded','true');}));
  window.addEventListener('svnet:case-cleared',clear);
  window.addEventListener('storage',e=>{if(['svnet-case','svnet-case-owner'].includes(e.key))clear();});
  window.addEventListener('pagehide',clear);
  buttons.forEach(button=>button.onclick=async()=>{
    if(!context||busy)return;const version=generation;busy=true;enable();pendingKey ||= crypto.randomUUID();
    try{const r=await api('assistant_start',{request_key:pendingKey,task:button.dataset.assistantTask,instruction:el('vf-assistant-order').value,route:el('vf-assistant-route').value},version);job=r.job_id;pendingKey=null;await run(version);}
    catch(e){if(version===generation&&e.name!=='AbortError'){if(['CASE_BUSY','MONTHLY_BUDGET_EXCEEDED','IDEMPOTENCY_CONFLICT','ACTION_INVALID'].includes(e.code))pendingKey=null;state(e.message+(!e.code?' · Startstatus unklar: denselben Auftrag unverändert erneut versuchen.':''));}}
    finally{if(version===generation){busy=false;enable();}}
  });
  el('vf-assistant-history').onclick=async()=>{const v=generation;if(!context||busy)return;busy=true;enable();try{const rows=await api('assistant_history',{},v);if(!rows.length){state('Keine gespeicherten Assistentenaufträge.');return;}job=Number(rows[0].id);enable();await api('assistant_recover',{job_id:job},v);enable();state('Auftrag geladen. Status prüfen oder Wiederaufnehmen wählen.');}catch(e){if(v===generation)state(e.message);}finally{if(v===generation){busy=false;enable();}}};
  el('vf-assistant-refresh').onclick=async()=>{const v=generation;if(!context||busy)return;busy=true;enable();try{const r=await api('assistant_status',{job_id:job},v);state(`Auftrag ${r.id}: ${r.status}`);render(r.result);}catch(e){if(v===generation)state(e.message);}finally{if(v===generation){busy=false;enable();}}};
  el('vf-assistant-resume').onclick=async()=>{const v=generation;if(busy)return;busy=true;enable();try{const s=await api('assistant_status',{job_id:job},v);if(s.status==='paused')await api('assistant_resume',{job_id:job},v);await run(v);}catch(e){if(v===generation)state(e.message);}finally{if(v===generation){busy=false;enable();}}};
  el('vf-assistant-cancel').onclick=async()=>{const v=generation;if(!context||busy)return;busy=true;enable();try{await api('assistant_cancel',{job_id:job},v);state('Auftrag beendet.');job=null;render(null);enable();}catch(e){if(v===generation)state(e.message);}finally{if(v===generation){busy=false;enable();}}};
  clear(); // Opening panel never loads a case, imports notes, polls, or runs a model.
};
