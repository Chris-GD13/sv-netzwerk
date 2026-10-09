(() => {
  'use strict';
  const API = '/intern/api/document-review.php';
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const active = () => {
    for (const store of [sessionStorage, localStorage]) {
      try { const row = JSON.parse(store.getItem('svnet-case') || 'null'); if (row?.folder_id) return row; } catch {}
    }
    return null;
  };
  const request = async (action, folder, data) => {
    const response = await fetch(`${API}?action=${action}${data ? '' : `&folder_id=${encodeURIComponent(folder)}`}`, {
      credentials: 'same-origin', ...(data ? {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({folder_id:folder, ...data})} : {})
    });
    const raw = await response.text(); let result;
    try { result = JSON.parse(raw); } catch { throw Error('Die Portalantwort konnte nicht gelesen werden. Bitte erneut öffnen.'); }
    if (!response.ok || !result.ok) throw Error(result.error || `HTTP ${response.status}`);
    return result;
  };
  const host = document.querySelector('.vf-release-grid');
  if (!host || document.getElementById('vf-document-reviews')) return;
  const wrap = document.createElement('div'); wrap.id = 'vf-document-reviews'; wrap.className = 'dr-grid';
  host.after(wrap);
  const panels = [];
  const fixed = [
    ['controlling','controlling@dieregulierer.de','controlling@dieregulierer.de'],
    ['archiv','Archiv@sv.de','Archiv@sv.de'],
    ['ws','ws@sv-schuett.eu','ws@sv-schuett.eu']
  ];
  for (const [kind, title, noun, step] of [['invoice','Rechnungsprüfung','Rechnung','7'],['offer','Angebotsprüfung','Angebot','8']]) {
    const panel = document.createElement('details'); panel.className = 'vf-card dr-panel'; panel.id = `dr-${kind}`;
    const prefix = `dr-${kind}-`;
    const field = (name, label, type='text') => `<label>${label}<input id="${prefix+name}" type="${type}" autocomplete="off"></label>`;
    const recipient = (key, name, address, locked=false) => `<div class="dr-recipient"><label><input type="checkbox" data-recipient="${key}"> ${esc(name)}</label><input type="email" data-address="${key}" value="${esc(address)}" aria-label="E-Mail ${esc(name)}" ${locked?'readonly':''}><select data-role="${key}" aria-label="Empfängerart ${esc(name)}"><option value="to">An</option><option value="cc" ${key==='controlling'||key==='ws'?'selected':''}>CC</option><option value="bcc" ${key==='archiv'?'selected':''}>BCC</option></select></div>`;
    panel.innerHTML = `<summary><b>${step}</b><span><strong>${title}</strong><small>${noun} prüfen oder direkt übernehmen · Freigabe und Mail mit Beleg</small></span><span class="dr-arrow">›</span></summary>
      <div class="dr-content">
        <p id="${prefix}sender" class="vf-meta" style="white-space:pre-line"></p>
        <label class="dr-choice"><input id="${prefix}standalone" type="checkbox"> Ohne angelegten Schadenfall prüfen</label>
        <div id="${prefix}workspace-controls" hidden>${field('case_no','Vorgangs- / Schaden-Nr. (optional)')}<button id="${prefix}new-workspace" type="button" class="vf-secondary">Neue freie Prüfung</button><p class="vf-meta">Belege und Ergebnis werden in deinem persönlichen Prüfvorgang gespeichert. Es wird kein Schadenfall angelegt.</p></div>
        <label>Hauptbeleg: ${noun} / Sammelangebot<select id="${prefix}file"><option value="">Belege hochladen oder aus dem Fall wählen</option></select></label>
        <div class="dr-actions"><button class="vf-secondary" type="button" id="${prefix}reload">Belege aktualisieren</button><label class="vf-secondary dr-upload">Mehrere Belege hochladen<input type="file" id="${prefix}upload" multiple accept=".pdf,.docx,.jpg,.jpeg,.png,.webp" hidden></label><a id="${prefix}original" class="vf-secondary" target="_blank" rel="noopener" hidden>Original öffnen</a></div>
        <fieldset><legend>Ergänzende Belege gemeinsam prüfen</legend><div id="${prefix}supports" class="dr-supports"></div><p class="vf-meta">z. B. Messprotokoll, Energieverbrauch, Fotos oder bisherige Freigaben. Hauptbeleg oben auswählen.</p></fieldset>
        <label class="dr-choice"><input id="${prefix}attach-support" type="checkbox" checked> Ergänzende Belege ebenfalls der Mail anhängen</label>
        <label>Kommentar / Hinweise zur Prüfung<textarea id="${prefix}comment" rows="5" maxlength="12000" placeholder="Begleitmail einfügen oder Hinweise ergänzen, z. B. Demontage und Trocknung bereits freigegeben; jetzt Gesamtsumme prüfen."></textarea></label>
        <label class="dr-choice"><input type="checkbox" id="${prefix}direct"> ${noun} ohne erneute Prüfung übernehmen</label>
        <label id="${prefix}reference-label">Vergleichsangebot / bisheriger Beleg (optional)<select id="${prefix}reference"><option value="">Kein Vergleichsbeleg ausgewählt</option></select></label>
        <button id="${prefix}prepare" class="vf-primary" type="button">${noun} prüfen</button>
        <p id="${prefix}state" class="vf-meta" role="status"></p>
        <div id="${prefix}history" class="dr-history" hidden></div>
        <div id="${prefix}edit" hidden>
          <div id="${prefix}checks" class="dr-checks"></div>
          <div class="dr-fields">${field('company',kind==='invoice'?'Rechnungssteller':'Angebotssteller')}${field('number',`${noun}snummer`)}${field('date','Belegdatum')}${field('net','Netto EUR')}${field('vat','Umsatzsteuer EUR')}${field('gross','Original-Bruttobetrag EUR')}</div>
          <label id="${prefix}assessment-label">Prüfergebnis / Stellungnahme<textarea id="${prefix}assessment" rows="5"></textarea></label>
          <label id="${prefix}confirm-label" class="dr-choice"><input id="${prefix}confirmed" type="checkbox"> Prüfergebnis fachlich kontrolliert und bestätigt</label>
          <label>Entscheidung<select id="${prefix}decision"><option value="">Bitte auswählen</option><option value="approved">freigegeben</option><option value="rejected">nicht freigegeben</option></select></label>
          <label id="${prefix}amount-label">Freigabebetrag brutto EUR<input id="${prefix}release_amount" inputmode="decimal" autocomplete="off"></label>
          <label>Begründung / Grund der direkten Übernahme<textarea id="${prefix}reason" rows="3" placeholder="z. B. bereits geprüft am … oder Abschlagsrechnung; bei Nichtfreigabe den Grund nennen"></textarea></label>
          <fieldset><legend>Energieverbrauch / separate Erstattung an den VN</legend><div class="dr-fields">${field('energy_kwh','Nachgewiesener Verbrauch kWh')}${field('energy_rate','Strompreis EUR/kWh (änderbar)')}${field('energy_vn','VN / Zahlungsempfänger')}</div><label>Stromkosten EUR – separat vom Angebot<input id="${prefix}energy_amount" readonly></label><p class="vf-meta">Standard 0,35 €/kWh. Energieverbrauch wird separat ausgewertet; Stromkosten werden nicht auf die Freigabesumme des Auftragnehmers aufgeschlagen.</p></fieldset>
          <fieldset><legend>Empfänger auswählen</legend>
            ${recipient('supplier',kind==='invoice'?'Rechnungssteller':'Angebotssteller','')}
            ${recipient('insurer','Versicherung (z. B. Sparkassenversicherung)','')}
            ${fixed.map(([key,name,address])=>recipient(key,name,address,true)).join('')}
            <label>Weitere An-Empfänger<input id="${prefix}extra-to" placeholder="E-Mail-Adressen, mit Komma getrennt"></label>
            <label>Weitere CC-Empfänger<input id="${prefix}extra-cc"></label><label>Weitere BCC-Empfänger<input id="${prefix}extra-bcc"></label>
          </fieldset>
          <p class="vf-meta" id="${prefix}attachment"></p>
          <button class="vf-primary" type="button" id="${prefix}save">Entscheidung speichern und Mail vorbereiten</button>
        </div>
        <div id="${prefix}mail" class="dr-mail" hidden><strong>Mailvorschau</strong><p id="${prefix}mail-header"></p><pre id="${prefix}mail-body"></pre><button type="button" class="vf-primary" id="${prefix}send">Mail mit Originalbeleg senden</button><p class="vf-meta" id="${prefix}send-state" role="status"></p></div>
      </div>`;
    wrap.append(panel);
    const el = name => document.getElementById(prefix+name);
    const state = {folder:'', workspace:sessionStorage.getItem('svnet-review-'+kind)||'', files:[], support:new Set(), token:'', recordId:'', busy:false, sender:'', source:''};
    const context = () => el('standalone').checked ? state.workspace : (active()?.folder_id||'');
    const message = (text, bad=false) => { el('state').textContent=text; el('state').classList.toggle('dr-error',bad); };
    const reset = () => { state.token=''; state.recordId=''; el('mail').hidden=true; el('send').disabled=true; el('confirmed').checked=false; };
    const clear = () => {
      reset(); el('edit').hidden=true; el('checks').replaceChildren(); el('sender').textContent=''; el('send-state').textContent='';el('history').hidden=true;el('history').replaceChildren();
      state.support.clear();el('supports').replaceChildren();el('comment').value='';el('case_no').value='';
      for (const key of ['energy_kwh','energy_amount','energy_vn']) el(key).value='';el('energy_rate').value='0,35';
      for (const name of ['company','number','date','net','vat','gross','assessment','reason','release_amount','extra-to','extra-cc','extra-bcc']) el(name).value='';
      el('decision').value=''; el('direct').checked=false;
      panel.querySelectorAll('[data-recipient]').forEach(input=>input.checked=false);
      panel.querySelector('[data-address="supplier"]').value=''; panel.querySelector('[data-address="insurer"]').value='';
      message(''); syncMode();
    };
    const sameCase = folder => { if (context()!==folder) throw Error('Der Prüfvorgang wurde gewechselt. Bitte die Belege im richtigen Vorgang neu auswählen.'); };
    const sourceLink = id => `${API}?action=download&folder_id=${encodeURIComponent(state.folder)}&file_id=${encodeURIComponent(id)}`;
    function syncMode() {
      const direct=el('direct').checked;
      el('prepare').textContent=direct?'Beleg für direkte Übernahme vorbereiten':`${noun} prüfen`;
      el('reference-label').hidden=direct; el('assessment-label').hidden=direct; el('confirm-label').hidden=direct;
      el('workspace-controls').hidden=!el('standalone').checked;
    }
    function original() {
      const id=el('file').value;
      el('original').hidden=!id;
      if (id) el('original').href=sourceLink(id);
      renderSupport();
    }
    function renderSupport() {
      const primary=el('file').value;
      el('supports').innerHTML=state.files.filter(f=>f.id!==primary).map(f=>`<label class="dr-choice"><input type="checkbox" data-support="${esc(f.id)}" ${state.support.has(f.id)?'checked':''}> ${esc(f.name)}</label>`).join('')||'<p class="vf-meta">Noch keine ergänzenden Belege vorhanden.</p>';
    }
    function energyAmount() {
      const num=v=>{const text=v.trim();return text===''?null:Number(text.includes(',')?text.replace(/\./g,'').replace(',','.'):text);};
      const kwh=num(el('energy_kwh').value), rate=num(el('energy_rate').value);
      el('energy_amount').value=kwh!==null&&Number.isFinite(kwh)&&rate!==null&&Number.isFinite(rate)?(Math.round((kwh*rate+Number.EPSILON)*100)/100).toLocaleString('de-DE',{minimumFractionDigits:2,maximumFractionDigits:2}):'';
    }
    el('energy_kwh').addEventListener('input',energyAmount);el('energy_rate').addEventListener('input',energyAmount);
    async function load() {
      if (!active()?.folder_id && !el('standalone').checked) el('standalone').checked=true;
      syncMode();
      if (el('standalone').checked && !state.workspace) {
        const created=await request('workspace','',{});state.workspace=created.folder_id;sessionStorage.setItem('svnet-review-'+kind,state.workspace);
      }
      const current=el('standalone').checked?{}:active(), folder=context();
      if (state.folder!==folder) { clear(); state.folder=folder; }
      if (!folder) { el('file').innerHTML='<option value="">Belege hochladen oder Fall öffnen</option>'; state.files=[]; original(); return; }
      const old=el('file').value, ref=el('reference').value;
      const data=await request('files',folder); sameCase(folder);
      state.files=data.files; state.sender=data.sender;
      el('sender').textContent=`Absender: ${data.sender}\n${data.signature || data.sender_name || ''}`;
      const history=(data.reviews||[]).filter(r=>r.kind===kind);
      el('history').hidden=!history.length;
      el('history').innerHTML='<strong>Gespeicherte Entscheidungen</strong>'+history.map(r=>`<p><a target="_blank" rel="noopener" href="${sourceLink(r.id)}">${esc(r.company)} · ${esc(r.number)}</a><br>${r.decision==='approved'?'freigegeben':'nicht freigegeben'}${r.decision==='approved'?` · ${Number(r.release_amount).toLocaleString('de-DE',{minimumFractionDigits:2,maximumFractionDigits:2})} EUR brutto`:''} · ${r.mode==='direct'?'ohne erneute Prüfung':'fachlich bestätigt'} · ${r.send_status==='sent'?'Mail versendet':r.send_status==='unsent'?'noch nicht versendet':'Versandstatus klären'}</p>`).join('');
      // All eligible originals remain selectable, including generically named imported scans.
      const ranked=[...data.files].sort((a,b)=>Number(new RegExp(kind==='invoice'?'rechnung|invoice|abschlag|schlussrechnung':'angebot|kva|kostenvoranschlag','i').test(b.name))-Number(new RegExp(kind==='invoice'?'rechnung|invoice|abschlag|schlussrechnung':'angebot|kva|kostenvoranschlag','i').test(a.name)));
      el('file').innerHTML='<option value="">Originalbeleg auswählen</option>'+ranked.map(f=>`<option value="${esc(f.id)}">${esc(f.name)}</option>`).join('');
      el('reference').innerHTML='<option value="">Kein Vergleichsbeleg ausgewählt</option>'+data.files.map(f=>`<option value="${esc(f.id)}">${esc(f.name)}</option>`).join('');
      if (data.files.some(f=>f.id===old)) el('file').value=old;
      if (data.files.some(f=>f.id===ref)) el('reference').value=ref;
      const meta=current.meta||{};
      const insurer=String(meta.versicherer||meta.insurer||current.insurer||'');
      const insurerAddress=meta.versicherer_email||meta.insurer_email||(/sparkassen|^sv$/i.test(insurer)?'service.schaden@sparkassenversicherung.de':'');
      if (!panel.querySelector('[data-address="insurer"]').value) panel.querySelector('[data-address="insurer"]').value=insurerAddress;
      if (!panel.querySelector('[data-address="supplier"]').value) panel.querySelector('[data-address="supplier"]').value=meta.sanierer_email||'';
      original();
    }
    panel.addEventListener('toggle',()=>{ if (panel.open) load().catch(e=>message(e.message,true)); });
    el('reload').onclick=()=>load().catch(e=>message(e.message,true));
    el('standalone').onchange=()=>{clear();state.folder='';load().catch(e=>message(e.message,true));};
    el('new-workspace').onclick=()=>{clear();state.workspace='';state.folder='';load().catch(e=>message(e.message,true));};
    el('supports').onchange=event=>{const id=event.target.dataset.support;if(id){event.target.checked?state.support.add(id):state.support.delete(id);reset();el('edit').hidden=true;}};
    for (const key of ['comment','case_no','attach-support']) el(key).addEventListener('input',()=>{reset();el('edit').hidden=true;});
    el('file').onchange=()=>{reset();el('edit').hidden=true;original();};
    el('reference').onchange=()=>{reset();el('edit').hidden=true;};
    el('direct').onchange=()=>{reset();el('edit').hidden=true;syncMode();};
    el('upload').onchange=async()=>{
      const files=Array.from(el('upload').files||[]);
      if (!files.length) return;
      if (files.length>12 || files.some(f=>f.size>30*1024*1024) || files.reduce((n,f)=>n+f.size,0)>50*1024*1024) {message('Maximal 12 Belege, 30 MB je Beleg und insgesamt 50 MB.',true);return;}
      message('Originalbelege werden gespeichert …');
      try {
        if (!context()) await load();const folder=context();
        const form=new FormData(); form.append('folder_id',folder);for (const file of files) form.append('files[]',file);
        const response=await fetch(API+'?action=upload',{method:'POST',credentials:'same-origin',body:form});
        const raw=await response.text(); const data=JSON.parse(raw);
        if (!response.ok||!data.ok) throw Error(data.error||'Upload fehlgeschlagen.');
        sameCase(folder); await load();
        const uploaded=data.files||[];
        const found=uploaded.find(f=>new RegExp(kind==='offer'?'sammelangebot|angebot|kva':'rechnung|abschlag','i').test(f.name))||uploaded[0];
        if (!found) throw Error('Upload bestätigt, aber Beleg noch nicht in der Fallliste sichtbar. Bitte aktualisieren.');
        el('file').value=found.id;for(const file of uploaded) if(file.id!==found.id)state.support.add(file.id);reset();el('edit').hidden=true;original();message(`${uploaded.length} Originalbelege gespeichert. Hauptbeleg und ergänzende Nachweise kontrollieren.`);
      } catch(error) {message(error.message,true);} finally {el('upload').value='';}
    };
    el('prepare').onclick=async()=>{
      const folder=context();
      if (!folder||folder!==state.folder) {message('Bitte zuerst den richtigen Fall öffnen.',true);return;}
      if (!el('file').value) {message('Bitte einen Originalbeleg auswählen.',true);return;}
      if (el('reference').value===el('file').value && !el('direct').checked) {message('Original und Vergleichsbeleg müssen verschieden sein.',true);return;}
      reset(); el('prepare').disabled=true; state.busy=true;
      const preparedFile=el('file').value, preparedDirect=el('direct').checked, preparedReference=el('reference').value;
      const supportIds=[...state.support].filter(id=>id!==preparedFile), comment=el('comment').value.trim(), caseNo=el('case_no').value.trim(), attachSupport=el('attach-support').checked;
      message(el('direct').checked?'Beleg wird ohne inhaltliche Prüfung vorbereitet …':'Der vollständige Beleg wird geprüft. Das kann einige Minuten dauern …');
      try {
        const result=await request('prepare',folder,{kind,mode:preparedDirect?'direct':'review',file_id:preparedFile,reference_id:preparedReference,support_ids:supportIds,comment,case_no:caseNo,attach_support:attachSupport});sameCase(folder);
        if(el('file').value!==preparedFile||el('direct').checked!==preparedDirect||el('reference').value!==preparedReference||el('comment').value.trim()!==comment||JSON.stringify([...state.support].filter(id=>id!==preparedFile))!==JSON.stringify(supportIds)||el('attach-support').checked!==attachSupport||el('case_no').value.trim()!==caseNo)throw Error('Die Belegauswahl oder Hinweise wurden während der Prüfung geändert. Bitte erneut vorbereiten.');
        state.token=result.token; state.source=result.file_name;
        const a=result.analysis||{};
        el('energy_kwh').value=Number.isFinite(a.energy_kwh)?a.energy_kwh.toLocaleString('de-DE',{maximumFractionDigits:4}):'';el('energy_rate').value='0,35';el('energy_vn').value=a.energy_vn||(!el('standalone').checked?active()?.meta?.object||document.getElementById('vf-object')?.value||'':'');energyAmount();
        if (el('standalone').checked && !caseNo && a.case_no) el('case_no').value=a.case_no;
        for (const key of ['company','number','date','assessment']) el(key).value=a[key]||'';
        for (const key of ['net','vat','gross']) el(key).value=Number.isFinite(a[key])?a[key].toLocaleString('de-DE',{minimumFractionDigits:2,maximumFractionDigits:2}):'';
        if (!a.company && !el('standalone').checked) el('company').value=document.getElementById('vf-sanierer-firma')?.value||active()?.meta?.sanierer_firma||'';
        if (a.company_email) panel.querySelector('[data-address="supplier"]').value=a.company_email;
        else if (a.company && a.company!==document.getElementById('vf-sanierer-firma')?.value) panel.querySelector('[data-address="supplier"]').value='';
        el('release_amount').value=el('gross').value; el('decision').value='';el('reason').value='';
        el('checks').innerHTML=(a.checks||[]).map(check=>`<p><strong>${esc(check.item)}: ${esc(check.result)}</strong><br>${esc(check.detail)}</p>`).join('')+(a.warnings||[]).map(w=>`<p class="dr-error">${esc(w)}</p>`).join('')+(a.currency&&a.currency!=='EUR'?'<p class="dr-error">Originalwährung ist nicht EUR. Beträge vor einer Entscheidung klären.</p>':'');
        if (a.energy_findings) el('checks').innerHTML+=`<p><strong>Energieverbrauch – gesonderte Auswertung</strong><br>${esc(a.energy_findings)}</p>`;
        state.source=(attachSupport?result.source_names||[result.file_name]:[result.file_name]).join(', ');
        el('attachment').textContent=`Mailanhänge: ${state.source} (Originalbelege)`;
        el('edit').hidden=false;syncMode();
        message(el('direct').checked?'Keine erneute Prüfung ausgeführt. Originaldaten ergänzen, Grund und Entscheidung auswählen.':'Prüfvorschlag liegt vor. Originaldaten und Ergebnis kontrollieren, dann Entscheidung auswählen.');
      } catch(error) {message(error.message,true);} finally {state.busy=false;el('prepare').disabled=false;}
    };
    el('decision').onchange=()=>{el('amount-label').hidden=el('decision').value==='rejected';state.recordId='';el('mail').hidden=true;};
    const values = () => {
      const v={};for(const key of ['company','number','date','net','vat','gross','reason','assessment','release_amount','decision','energy_kwh','energy_rate','energy_vn'])v[key]=el(key).value.trim();
      v.review_confirmed=el('confirmed').checked;
      for(const role of ['to','cc','bcc'])v[role]=el('extra-'+role).value.trim();
      panel.querySelectorAll('[data-recipient]:checked').forEach(input=>{const key=input.dataset.recipient,role=panel.querySelector(`[data-role="${key}"]`).value,address=panel.querySelector(`[data-address="${key}"]`).value.trim();if(!address)throw Error('Bitte die E-Mail-Adresse des ausgewählten Empfängers ergänzen.');v[role]=[v[role],address].filter(Boolean).join(', ');});
      return v;
    };
    // Editing any decision or recipient invalidates a previously saved sendable draft.
    el('edit').addEventListener('input',()=>{state.recordId='';el('mail').hidden=true;el('send').disabled=true;});
    el('edit').addEventListener('change',()=>{state.recordId='';el('mail').hidden=true;el('send').disabled=true;});
    el('save').onclick=async()=>{
      const folder=context();
      if (!state.token||folder!==state.folder) {message('Bitte den Beleg erneut vorbereiten.',true);return;}
      el('save').disabled=true;
      try {
        const v=values();const data=await request('save',folder,{token:state.token,values:v,case_no:el('case_no').value.trim()});sameCase(folder);
        state.recordId=data.record_id;
        el('mail-header').textContent=`Von: ${data.sender}\nAn: ${v.to||'noch nicht ausgewählt'}\nCC: ${v.cc||'–'}\nBCC: ${v.bcc||'–'}\nBetreff: ${data.subject}\nAnhang: ${state.source}`;
        el('mail-body').textContent=data.body;el('mail').hidden=false;el('send').disabled=!v.to;el('send-state').textContent='';
        message(`Entscheidung ${el('standalone').checked?'im persönlichen Prüfvorgang':'im Fall unter 06_Freigaben_Zahlungen'} gespeichert. ${v.to?'Mail bereit zur Durchsicht.':'Für den Versand einen An-Empfänger auswählen und erneut speichern.'}`);
      } catch(error) {message(error.message,true);}finally{el('save').disabled=false;}
    };
    el('send').onclick=async()=>{
      const folder=context();
      if (!state.recordId||folder!==state.folder) {message('Bitte im richtigen Fall erneut speichern.',true);return;}
      el('send').disabled=true;el('send-state').textContent='Mail mit Originalbeleg wird versendet …';
      try {
        const data=await request('send',folder,{record_id:state.recordId});sameCase(folder);
        el('send-state').textContent=`Versand von ${data.sender} bestätigt. Originalbeleg angehängt; Versandstatus in der Fallakte gespeichert.`;
      } catch(error) {el('send-state').textContent=error.message;el('send-state').classList.add('dr-error');}
    };
    panels.push({panel,state,clear,load,standalone:()=>el('standalone').checked});
  }
  const onCaseChange=()=>{
    const folder=active()?.folder_id||'';
    for(const p of panels)if(!p.standalone()&&p.state.folder!==folder){p.clear();p.state.folder=folder;if(p.panel.open)p.load().catch(e=>{document.getElementById(`dr-${p.panel.id.endsWith('invoice')?'invoice':'offer'}-state`).textContent=e.message;});}
  };
  new MutationObserver(onCaseChange).observe(document.getElementById('vf-active'),{childList:true,subtree:true});
  window.addEventListener('storage',onCaseChange);window.addEventListener('svnet:cases-changed',onCaseChange);
})();
