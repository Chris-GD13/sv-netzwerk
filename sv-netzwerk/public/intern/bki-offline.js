(()=>{
  if(!location.pathname.startsWith('/intern/kalkulation')) return;
  const el=id=>document.getElementById(id);
  const bridge=window.__bkiCalcBridge;
  if(!bridge) return;
  const API='/intern/api/bki-calculator.php', DRAFT='/intern/api/bki-drafts.php';
  const active=bridge.getActive?.()||null, meta=bridge.getMeta?.()||{};
  const folderId=active?.folder_id||'';
  const freeKeyName='svnet-bki-free-draft-key';
  const requestedDraftKey=new URLSearchParams(location.search).get('draft_key')||'';
  let draftKey=requestedDraftKey||(folderId?`case:${folderId}`:(localStorage.getItem(freeKeyName)||''));
  if(!draftKey){draftKey='free:'+(globalThis.crypto?.randomUUID?.()||`${Date.now()}-${Math.random().toString(16).slice(2)}`);localStorage.setItem(freeKeyName,draftKey)}
  const localKey='svnet-bki-draft:'+draftKey;
  let pending=[];
  let saveTimer=null;

  const status=document.createElement('div');status.id='bk-offline-status';status.style.cssText='margin:8px 0 14px;padding:9px 12px;border-radius:10px;font-weight:750;background:#eef4f7;color:#17324a';
  const head=el('bk-case');head?.insertAdjacentElement('afterend',status);

  const queueCard=document.createElement('section');queueCard.className='bk-card';queueCard.id='bk-offline-queue';queueCard.hidden=true;
  queueCard.innerHTML='<div class="bk-title-row"><h2>Offline erfasste Leistungen</h2><span id="bk-offline-count"></span></div><div id="bk-offline-items"></div>';
  const inputCard=document.querySelector('.bk-input-card');inputCard?.insertAdjacentElement('afterend',queueCard);

  const draftsCard=document.createElement('section');draftsCard.className='bk-card';draftsCard.id='bk-drafts-card';
  draftsCard.innerHTML='<div class="bk-title-row"><h2>Entwürfe</h2><button id="bk-new-free-draft" class="bk-secondary" type="button">Neuer freier Entwurf</button></div><div id="bk-drafts-list">Entwürfe werden geladen …</div>';
  document.querySelector('.bk-history')?.insertAdjacentElement('beforebegin',draftsCard);
  if(folderId) el('bk-new-free-draft').hidden=true;

  function onlineState(){
    if(navigator.onLine){status.textContent=pending.length?`Online · ${pending.length} BKI-Prüfung(en) werden nachgeholt.`:'Online · Entwurf wird automatisch synchronisiert.';status.style.background='#eaf6f0';status.style.color='#236e50'}
    else{status.textContent='Offline · Eingaben werden sicher auf diesem Gerät gespeichert. BKI-Prüfung folgt automatisch bei Empfang.';status.style.background='#fff4e6';status.style.color='#875014'}
  }
  function number(v){const n=Number(String(v??'').replace(',','.'));return Number.isFinite(n)?n:0}
  function snapshot(){return{
    query:el('bk-query')?.value||'',location:el('bk-location')?.value||'',qty:el('bk-qty')?.value||'',unit:el('bk-unit')?.value||'',level:el('bk-level')?.value||'mid',vat:el('bk-vat')?.value||'19',note:el('bk-note')?.value||'',lines:bridge.getLines?.()||[],kvaReview:window.__bkiKvaReview||null,pendingQueries:pending,updatedAt:new Date().toISOString()
  }}
  function applyState(s){if(!s||typeof s!=='object')return; if(el('bk-query'))el('bk-query').value=s.query||'';if(el('bk-location'))el('bk-location').value=s.location||'';if(el('bk-qty'))el('bk-qty').value=s.qty||'';if(el('bk-unit'))el('bk-unit').value=s.unit||'';if(el('bk-level'))el('bk-level').value=s.level||'mid';if(el('bk-vat')){el('bk-vat').value=s.vat||'19';el('bk-vat').dispatchEvent(new Event('input',{bubbles:true}))}if(el('bk-note'))el('bk-note').value=s.note||'';if(s.kvaReview&&typeof s.kvaReview==='object')window.__bkiKvaReview={...s.kvaReview};pending=Array.isArray(s.pendingQueries)?s.pendingQueries:[];bridge.setLines?.(Array.isArray(s.lines)?s.lines:[]);renderPending();onlineState()}
  function saveLocal(){const s=snapshot();localStorage.setItem(localKey,JSON.stringify(s));return s}
  function scheduleSave(){clearTimeout(saveTimer);saveTimer=setTimeout(async()=>{const s=saveLocal();if(navigator.onLine)await syncRemote(s).catch(()=>{})},350)}
  async function json(url,opt={}){const r=await fetch(url,{credentials:'same-origin',...opt});const j=await r.json().catch(()=>({}));if(!r.ok)throw new Error(j.error||`HTTP ${r.status}`);return j}
  async function syncRemote(s=snapshot()){
    if(!navigator.onLine)return;
    await json(DRAFT+'?action=save',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({draft_key:draftKey,folder_id:folderId,case_meta:meta,title:[meta.schaden_nr,meta.schadenart].filter(Boolean).join(' – ')||'Freie BKI-Kalkulation',state:s})});
  }
  function pickPrice(p,level){return number(level==='low'?p.price_low:level==='high'?p.price_high:p.price_mid)}
  function esc(s){return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}
  function renderPending(){
    queueCard.hidden=!pending.length;const box=el('bk-offline-items');if(!box)return;el('bk-offline-count').textContent=pending.length?`${pending.length} offen`:'';box.innerHTML='';
    pending.forEach((q,qi)=>{const wrap=document.createElement('div');wrap.style.cssText='border-top:1px solid #dce5ec;padding:10px 0';let html=`<strong>${esc(q.query)}</strong><br><small>${q.status==='review'?'BKI-Treffer gefunden · Auswahl erforderlich':'BKI-Prüfung ausstehend'}</small>`;if(Array.isArray(q.candidates)&&q.candidates.length){html+='<div style="display:grid;gap:7px;margin-top:8px">'+q.candidates.map((p,pi)=>`<button type="button" class="bk-secondary" data-q="${qi}" data-p="${pi}" style="text-align:left"><strong>${esc(p.position_code||'BKI')}</strong> · ${esc(p.description)}<br><small>${esc(p.unit||'')} · Mittel ${new Intl.NumberFormat('de-DE',{style:'currency',currency:'EUR'}).format(number(p.price_mid))}${p.source_page?' · Seite '+esc(p.source_page):''}</small></button>`).join('')+'</div>'}wrap.innerHTML=html;box.appendChild(wrap)});
    box.querySelectorAll('[data-q]').forEach(btn=>btn.addEventListener('click',()=>{const qi=Number(btn.dataset.q),pi=Number(btn.dataset.p),q=pending[qi],p=q?.candidates?.[pi];if(!p)return;bridge.addLine?.({...p,unit_price:pickPrice(p,q.level||'mid')});pending.splice(qi,1);renderPending();scheduleSave()}));
  }
  async function processPending(){
    if(!navigator.onLine||!pending.length)return;onlineState();
    for(const q of pending){if(q.status==='review')continue;try{const d=await json(API+'?action=search',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({query:q.query,quantity:q.qty,unit:q.unit,location:q.location,case_meta:meta})});const pos=Array.isArray(d.positions)?d.positions:[];if(pos.length===1){bridge.addLine?.({...pos[0],unit_price:pickPrice(pos[0],q.level||'mid')});q.done=true}else if(pos.length>1){q.status='review';q.candidates=pos}else{q.status='review';q.candidates=[]}}catch{q.status='pending'}}
    pending=pending.filter(q=>!q.done);renderPending();const s=saveLocal();await syncRemote(s).catch(()=>{});onlineState();
  }
  async function loadRemoteCurrent(){
    if(!navigator.onLine)return;try{const d=await json(DRAFT+'?action=get&draft_key='+encodeURIComponent(draftKey));const remote=d.item?.state;if(!remote)return;const local=JSON.parse(localStorage.getItem(localKey)||'null');const rt=Date.parse(remote.updatedAt||d.item.updated_at||0)||0,lt=Date.parse(local?.updatedAt||0)||0;if(!local||rt>lt){applyState(remote);localStorage.setItem(localKey,JSON.stringify(remote))}}catch(e){if(!String(e.message).includes('404')){} }
  }
  async function loadDraftList(){
    if(!navigator.onLine){el('bk-drafts-list').textContent='Offline · Server-Entwürfe werden bei Empfang geladen.';return}try{const qs=folderId?'&folder_id='+encodeURIComponent(folderId):'';const d=await json(DRAFT+'?action=list'+qs);const items=d.items||[];el('bk-drafts-list').innerHTML=items.length?items.map(x=>`<div style="border-top:1px solid #dce5ec;padding:9px 0"><button class="bk-secondary" type="button" data-draft="${esc(x.draft_key)}">${esc(x.title||'Entwurf')}</button><br><small>${esc(x.updated_at)}${x.case_no?' · Schaden '+esc(x.case_no):''}</small></div>`).join(''):'Noch kein synchronisierter Entwurf.';el('bk-drafts-list').querySelectorAll('[data-draft]').forEach(b=>b.onclick=async()=>{const d=await json(DRAFT+'?action=get&draft_key='+encodeURIComponent(b.dataset.draft));draftKey=b.dataset.draft;applyState(d.item.state||{});localStorage.setItem('svnet-bki-draft:'+draftKey,JSON.stringify(d.item.state||{}));scheduleSave()})}catch(e){el('bk-drafts-list').textContent=e.message}}

  const local=JSON.parse(localStorage.getItem(localKey)||'null');if(local)applyState(local);else onlineState();
  document.addEventListener('input',e=>{if(e.target?.closest?.('.bk-app'))scheduleSave()},true);
  document.addEventListener('change',e=>{if(e.target?.closest?.('.bk-app'))scheduleSave()},true);
  new MutationObserver(scheduleSave).observe(el('bk-lines'),{childList:true,subtree:true});

  el('bk-search')?.addEventListener('click',e=>{if(navigator.onLine)return;e.preventDefault();e.stopImmediatePropagation();const q=(el('bk-query')?.value||'').trim();if(!q)return;pending.push({id:Date.now(),query:q,qty:el('bk-qty')?.value||'',unit:el('bk-unit')?.value||'',location:el('bk-location')?.value||'',level:el('bk-level')?.value||'mid',status:'pending'});renderPending();saveLocal();onlineState();el('bk-state').textContent='Offline erfasst · BKI-Prüfung wird bei Empfang automatisch nachgeholt.'},true);

  window.addEventListener('offline',()=>{saveLocal();onlineState()});
  window.addEventListener('online',async()=>{onlineState();await processPending();await syncRemote().catch(()=>{});await loadDraftList()});
  navigator.serviceWorker?.addEventListener('message',e=>{if(e.data?.type==='SYNC_REQUEST')processPending()});
  el('bk-new-free-draft')?.addEventListener('click',()=>{if(folderId)return;draftKey='free:'+(globalThis.crypto?.randomUUID?.()||Date.now());localStorage.setItem(freeKeyName,draftKey);pending=[];bridge.setLines?.([]);['bk-query','bk-qty','bk-note'].forEach(id=>{if(el(id))el(id).value=''});saveLocal();onlineState()});

  if('caches' in window&&navigator.onLine){fetch(location.href,{credentials:'same-origin'}).then(r=>{if(r.ok)return caches.open('portal-pages').then(c=>c.put(location.href,r.clone()))}).catch(()=>{})}
  loadRemoteCurrent().then(()=>processPending()).then(()=>syncRemote()).catch(()=>{});loadDraftList();
})();
