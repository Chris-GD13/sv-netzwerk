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
        <label>${noun} aus dem Fall<select id="${prefix}file"><option value="">Bitte einen Fall öffnen</option></select></label>
        <div class="dr-actions"><button class="vf-secondary" type="button" id="${prefix}reload">Belege aktualisieren</button><label class="vf-secondary dr-upload">Neuen Beleg hochladen<input type="file" id="${prefix}upload" accept=".pdf,.docx,.jpg,.jpeg,.png,.webp" hidden></label><a id="${prefix}original" class="vf-secondary" target="_blank" rel="noopener" hidden>Original öffnen</a></div>
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
    const state = {folder:'', files:[], token:'', recordId:'', busy:false, sender:'', source:''};
    const message = (text, bad=false) => { el('state').textContent=text; el('state').classList.toggle('dr-error',bad); };
    const reset = () => { state.token=''; state.recordId=''; el('mail').hidden=true; el('send').disabled=true; el('confirmed').checked=false; };
    const clear = () => {
      reset(); el('edit').hidden=true; el('checks').replaceChildren(); el('send-state').textContent='';el('history').hidden=true;el('history').replaceChildren();
      for (const name of ['company','number','date','net','vat','gross','assessment','reason','release_amount','extra-to','extra-cc','extra-bcc']) el(name).value='';
      el('decision').value=''; el('direct').checked=false;
      panel.querySelectorAll('[data-recipient]').forEach(input=>input.checked=false);
      panel.querySelector('[data-address="supplier"]').value=''; panel.querySelector('[data-address="insurer"]').value='';
      message(''); syncMode();
    };
    const sameCase = folder => { if (active()?.folder_id!==folder) throw Error('Der aktive Fall wurde gewechselt. Bitte den Beleg im richtigen Fall neu auswählen.'); };
    function syncMode() {
      const direct=el('direct').checked;
      el('prepare').textContent=direct?'Beleg für direkte Übernahme vorbereiten':`${noun} prüfen`;
      el('reference-label').hidden=direct; el('assessment-label').hidden=direct; el('confirm-label').hidden=direct;
    }
    function original() {
      const id=el('file').value;
      el('original').hidden=!id;
      if (id) el('original').href=`/intern/api/case-file-browser.php?action=file&folder_id=${encodeURIComponent(state.folder)}&file_id=${encodeURIComponent(id)}`;
    }
    async function load() {
      const current=active(), folder=current?.folder_id||'';
      if (state.folder!==folder) { clear(); state.folder=folder; }
      if (!folder) { el('file').innerHTML='<option value="">Bitte zuerst einen Fall öffnen</option>'; state.files=[]; original(); return; }
      const old=el('file').value, ref=el('reference').value;
      const data=await request('files',folder); sameCase(folder);
      state.files=data.files; state.sender=data.sender;
      const history=(data.reviews||[]).filter(r=>r.kind===kind);
      el('history').hidden=!history.length;
      el('history').innerHTML='<strong>Gespeicherte Entscheidungen</strong>'+history.map(r=>`<p><a target="_blank" rel="noopener" href="/intern/api/case-file-browser.php?action=file&folder_id=${encodeURIComponent(folder)}&file_id=${encodeURIComponent(r.id)}">${esc(r.company)} · ${esc(r.number)}</a><br>${r.decision==='approved'?'freigegeben':'nicht freigegeben'}${r.decision==='approved'?` · ${Number(r.release_amount).toLocaleString('de-DE',{minimumFractionDigits:2,maximumFractionDigits:2})} EUR brutto`:''} · ${r.mode==='direct'?'ohne erneute Prüfung':'fachlich bestätigt'} · ${r.send_status==='sent'?'Mail versendet':r.send_status==='unsent'?'noch nicht versendet':'Versandstatus klären'}</p>`).join('');
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
    el('file').onchange=()=>{reset();el('edit').hidden=true;original();};
    el('reference').onchange=()=>{reset();el('edit').hidden=true;};
    el('direct').onchange=()=>{reset();el('edit').hidden=true;syncMode();};
    el('upload').onchange=async()=>{
      const file=el('upload').files?.[0], folder=active()?.folder_id;
      if (!file) return;
      if (!folder) {message('Bitte zuerst einen Fall öffnen.',true);return;}
      if (file.size>30*1024*1024) {message('Der Originalbeleg darf höchstens 30 MB groß sein.',true);return;}
      message('Originalbeleg wird im aktiven Fall gespeichert …');
      try {
        const form=new FormData(); form.append('folder_id',folder);form.append('file',file);
        const response=await fetch('/intern/api/google-drive-sync.php?action=upload_case_document',{method:'POST',credentials:'same-origin',body:form});
        const raw=await response.text(); const data=JSON.parse(raw);
        if (!response.ok||!data.ok) throw Error(data.error||'Upload fehlgeschlagen.');
        sameCase(folder); await load();
        const found=state.files.find(f=>f.id===data.file?.id)||state.files.find(f=>f.name===file.name);
        if (!found) throw Error('Upload bestätigt, aber Beleg noch nicht in der Fallliste sichtbar. Bitte aktualisieren.');
        el('file').value=found.id;reset();el('edit').hidden=true;original();message('Originalbeleg gespeichert. Bitte Prüfmodus wählen.');
      } catch(error) {message(error.message,true);} finally {el('upload').value='';}
    };
    el('prepare').onclick=async()=>{
      const folder=active()?.folder_id;
      if (!folder||folder!==state.folder) {message('Bitte zuerst den richtigen Fall öffnen.',true);return;}
      if (!el('file').value) {message('Bitte einen Originalbeleg auswählen.',true);return;}
      if (el('reference').value===el('file').value && !el('direct').checked) {message('Original und Vergleichsbeleg müssen verschieden sein.',true);return;}
      reset(); el('prepare').disabled=true; state.busy=true;
      const preparedFile=el('file').value, preparedDirect=el('direct').checked, preparedReference=el('reference').value;
      message(el('direct').checked?'Beleg wird ohne inhaltliche Prüfung vorbereitet …':'Der vollständige Beleg wird geprüft. Das kann einige Minuten dauern …');
      try {
        const result=await request('prepare',folder,{kind,mode:preparedDirect?'direct':'review',file_id:preparedFile,reference_id:preparedReference});sameCase(folder);
        if(el('file').value!==preparedFile||el('direct').checked!==preparedDirect||el('reference').value!==preparedReference)throw Error('Die Belegauswahl wurde während der Prüfung geändert. Bitte erneut vorbereiten.');
        state.token=result.token; state.source=result.file_name;
        const a=result.analysis||{};
        for (const key of ['company','number','date','assessment']) el(key).value=a[key]||'';
        for (const key of ['net','vat','gross']) el(key).value=Number.isFinite(a[key])?a[key].toLocaleString('de-DE',{minimumFractionDigits:2,maximumFractionDigits:2}):'';
        if (!a.company) el('company').value=document.getElementById('vf-sanierer-firma')?.value||active()?.meta?.sanierer_firma||'';
        if (a.company_email) panel.querySelector('[data-address="supplier"]').value=a.company_email;
        else if (a.company && a.company!==document.getElementById('vf-sanierer-firma')?.value) panel.querySelector('[data-address="supplier"]').value='';
        el('release_amount').value=el('gross').value; el('decision').value='';el('reason').value='';
        el('checks').innerHTML=(a.checks||[]).map(check=>`<p><strong>${esc(check.item)}: ${esc(check.result)}</strong><br>${esc(check.detail)}</p>`).join('')+(a.warnings||[]).map(w=>`<p class="dr-error">${esc(w)}</p>`).join('')+(a.currency&&a.currency!=='EUR'?'<p class="dr-error">Originalwährung ist nicht EUR. Beträge vor einer Entscheidung klären.</p>':'');
        el('attachment').textContent=`Mailanhang: ${state.source} (Originalbeleg)`;
        el('edit').hidden=false;syncMode();
        message(el('direct').checked?'Keine erneute Prüfung ausgeführt. Originaldaten ergänzen, Grund und Entscheidung auswählen.':'Prüfvorschlag liegt vor. Originaldaten und Ergebnis kontrollieren, dann Entscheidung auswählen.');
      } catch(error) {message(error.message,true);} finally {state.busy=false;el('prepare').disabled=false;}
    };
    el('decision').onchange=()=>{el('amount-label').hidden=el('decision').value==='rejected';state.recordId='';el('mail').hidden=true;};
    const values = () => {
      const v={};for(const key of ['company','number','date','net','vat','gross','reason','assessment','release_amount','decision'])v[key]=el(key).value.trim();
      v.review_confirmed=el('confirmed').checked;
      for(const role of ['to','cc','bcc'])v[role]=el('extra-'+role).value.trim();
      panel.querySelectorAll('[data-recipient]:checked').forEach(input=>{const key=input.dataset.recipient,role=panel.querySelector(`[data-role="${key}"]`).value,address=panel.querySelector(`[data-address="${key}"]`).value.trim();if(!address)throw Error('Bitte die E-Mail-Adresse des ausgewählten Empfängers ergänzen.');v[role]=[v[role],address].filter(Boolean).join(', ');});
      return v;
    };
    // Editing any decision or recipient invalidates a previously saved sendable draft.
    el('edit').addEventListener('input',()=>{state.recordId='';el('mail').hidden=true;el('send').disabled=true;});
    el('edit').addEventListener('change',()=>{state.recordId='';el('mail').hidden=true;el('send').disabled=true;});
    el('save').onclick=async()=>{
      const folder=active()?.folder_id;
      if (!state.token||folder!==state.folder) {message('Bitte den Beleg erneut vorbereiten.',true);return;}
      el('save').disabled=true;
      try {
        const v=values();const data=await request('save',folder,{token:state.token,values:v});sameCase(folder);
        state.recordId=data.record_id;
        el('mail-header').textContent=`Von: ${data.sender}\nAn: ${v.to||'noch nicht ausgewählt'}\nCC: ${v.cc||'–'}\nBCC: ${v.bcc||'–'}\nBetreff: ${data.subject}\nAnhang: ${state.source}`;
        el('mail-body').textContent=data.body;el('mail').hidden=false;el('send').disabled=!v.to;el('send-state').textContent='';
        message(`Entscheidung im Fall unter 06_Freigaben_Zahlungen gespeichert. ${v.to?'Mail bereit zur Durchsicht.':'Für den Versand einen An-Empfänger auswählen und erneut speichern.'}`);
      } catch(error) {message(error.message,true);}finally{el('save').disabled=false;}
    };
    el('send').onclick=async()=>{
      const folder=active()?.folder_id;
      if (!state.recordId||folder!==state.folder) {message('Bitte im richtigen Fall erneut speichern.',true);return;}
      el('send').disabled=true;el('send-state').textContent='Mail mit Originalbeleg wird versendet …';
      try {
        const data=await request('send',folder,{record_id:state.recordId});sameCase(folder);
        el('send-state').textContent=`Versand von ${data.sender} bestätigt. Originalbeleg angehängt; Versandstatus in der Fallakte gespeichert.`;
      } catch(error) {el('send-state').textContent=error.message;el('send-state').classList.add('dr-error');}
    };
    panels.push({panel,state,clear,load});
  }
  const onCaseChange=()=>{
    const folder=active()?.folder_id||'';
    for(const p of panels)if(p.state.folder!==folder){p.clear();p.state.folder=folder;if(p.panel.open)p.load().catch(e=>{document.getElementById(`dr-${p.panel.id.endsWith('invoice')?'invoice':'offer'}-state`).textContent=e.message;});}
  };
  new MutationObserver(onCaseChange).observe(document.getElementById('vf-active'),{childList:true,subtree:true});
  window.addEventListener('storage',onCaseChange);window.addEventListener('svnet:cases-changed',onCaseChange);
})();
