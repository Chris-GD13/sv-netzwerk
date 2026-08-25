(()=>{
  if(!location.pathname.startsWith('/intern/versicherungsfaelle')) return;
  const $=id=>document.getElementById(id);
  const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const activeCase=()=>{for(const s of [sessionStorage,localStorage]){try{const v=JSON.parse(s.getItem('svnet-case')||'null');if(v?.folder_id)return v;}catch{}}return null;};
  const classify=name=>{const n=String(name).toLowerCase();if(/rechnung|rg\b|invoice/.test(n))return'Rechnung';if(/kva|kostenvoranschlag|angebot/.test(n))return'KVA / Angebot';if(/gutachten|stellungnahme/.test(n))return'Gutachten / Stellungnahme';if(/erstbericht|zwischenbericht|schlussbericht|bericht/.test(n))return'Bericht';if(/mail|email|e-mail|schriftverkehr|brief/.test(n))return'Schriftverkehr';if(/foto|bild|jpg|jpeg|png|heic/.test(n))return'Foto';if(/protokoll/.test(n))return'Protokoll';return'Dokument';};
  const fmtSize=n=>!n?'':n>1048576?(n/1048576).toFixed(1)+' MB':Math.max(1,Math.round(n/1024))+' KB';
  const fmtDate=s=>{if(!s)return'';try{return new Date(s).toLocaleDateString('de-DE')}catch{return''}};
  const style=document.createElement('style');style.textContent=`
    #vf-doc-browser{margin:14px 0 18px;background:#fff;border:1px solid #d8e2e9;border-radius:3px;color:#17324a}
    #vf-doc-browser[hidden]{display:none}
    #vf-doc-browser>summary{cursor:pointer;list-style:none;padding:12px 16px;font-weight:800}#vf-doc-browser>summary::-webkit-details-marker{display:none}#vf-doc-browser>summary::before{content:'›';display:inline-block;margin-right:9px;transition:transform .15s}#vf-doc-browser[open]>summary::before{transform:rotate(90deg)}.vfdb-body{padding:0 16px 14px}
    .vfdb-head{display:flex;gap:10px;align-items:center;justify-content:space-between;margin-bottom:10px}.vfdb-head h3{margin:0;font-size:1rem}.vfdb-actions{display:flex;gap:7px;flex-wrap:wrap}
    .vfdb-actions button,.vfdb-actions a{border:1px solid #b9c7d2;background:#fff;color:#17324a;border-radius:3px;padding:7px 10px;font-weight:700;text-decoration:none;cursor:pointer}.vfdb-actions .primary{background:#17324a;color:#fff;border-color:#17324a}
    .vfdb-search{width:100%;box-sizing:border-box;border:1px solid #bdcbd6;border-radius:3px;padding:9px 10px;margin:0 0 10px;font:inherit}
    .vfdb-list{max-height:430px;overflow:auto;border-top:1px solid #e1e8ed}.vfdb-folder{margin:5px 0}.vfdb-folder>summary{cursor:pointer;font-weight:800;padding:7px 4px}.vfdb-folder-body{padding-left:16px}
    .vfdb-file{display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:9px;align-items:center;border-bottom:1px solid #edf1f4;padding:8px 4px}.vfdb-file input{width:18px;height:18px}.vfdb-file a{text-decoration:none;color:#17324a;min-width:0}.vfdb-file strong{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.vfdb-file small{display:block;color:#697d8e;margin-top:2px}.vfdb-type{font-size:.72rem;font-weight:800;background:#eef4f8;padding:4px 6px;border-radius:6px;white-space:nowrap}
    @media(max-width:760px){#vf-doc-browser{padding:10px}.vfdb-head{align-items:flex-start;flex-direction:column}.vfdb-file{grid-template-columns:auto minmax(0,1fr)}.vfdb-type{grid-column:2}.vfdb-list{max-height:55vh}}
  `;document.head.appendChild(style);

  const box=document.createElement('details');box.id='vf-doc-browser';box.hidden=true;box.innerHTML=`<summary>Fallunterlagen <small id="vfdb-meta">Unterlagen und Unterordner des aktiven Falls</small></summary><div class="vfdb-body"><div class="vfdb-head"><div></div><div class="vfdb-actions"><button type="button" id="vfdb-refresh">Aktualisieren</button><button type="button" class="primary" id="vfdb-open-selected">Ausgewählte öffnen</button><a id="vfdb-drive" target="_blank" rel="noreferrer" hidden>Fallordner öffnen</a></div></div><input id="vfdb-search" class="vfdb-search" placeholder="Unterlagen durchsuchen …"><div id="vfdb-list" class="vfdb-list"></div></div>`;
  const active=$('vf-active');if(active)active.insertAdjacentElement('afterend',box);else document.querySelector('.vf-app')?.prepend(box);

  const portalFileUrl=(folderId,fileId)=>'/intern/api/case-file-browser.php?action=file&folder_id='+encodeURIComponent(folderId)+'&file_id='+encodeURIComponent(fileId);
  function renderItems(items,path='',folderId=''){
    return (items||[]).map(item=>{
      if(item.folder){return `<details class="vfdb-folder" data-name="${esc(item.name.toLowerCase())}"><summary>📁 ${esc(item.name)}</summary><div class="vfdb-folder-body">${renderItems(item.children||[],path+'/'+item.name,folderId)}</div></details>`;}
      const type=classify(item.name);const meta=[path.replace(/^\//,''),fmtDate(item.modifiedTime),fmtSize(item.size)].filter(Boolean).join(' · ');
      const url=portalFileUrl(folderId,item.id);return `<div class="vfdb-file" data-name="${esc((item.name+' '+type+' '+path).toLowerCase())}"><input type="checkbox" data-url="${esc(url)}" aria-label="${esc(item.name)} auswählen"><a href="${esc(url)}" target="_blank" rel="noreferrer"><strong>${esc(type)} · ${esc(item.name)}</strong><small>${esc(meta)}</small></a><span class="vfdb-type">${esc(type)}</span></div>`;
    }).join('');
  }
  async function load(){const c=activeCase();if(!c?.folder_id){box.hidden=true;return;}box.hidden=false;$('vfdb-list').innerHTML='<div style="padding:12px">Fallunterlagen werden geladen …</div>';try{const r=await fetch('/intern/api/case-file-browser.php?folder_id='+encodeURIComponent(c.folder_id),{credentials:'same-origin'});const j=await r.json().catch(()=>({}));if(!r.ok||!j.ok)throw new Error(j.error||'Unterlagen konnten nicht geladen werden.');$('vfdb-list').innerHTML=renderItems(j.items||[],'',c.folder_id);$('vfdb-meta').textContent=`${j.folder?.name||'Fallakte'} · Unterlagen und Unterordner`;$('vfdb-drive').hidden=true;}catch(e){$('vfdb-list').innerHTML='<div style="padding:12px;color:#a33">'+esc(e.message||e)+'</div>';}}
  $('vfdb-refresh')?.addEventListener('click',load);$('vfdb-open-selected')?.addEventListener('click',()=>{document.querySelectorAll('#vfdb-list input[type=checkbox]:checked').forEach(n=>{if(n.dataset.url)window.open(n.dataset.url,'_blank','noopener');});});
  $('vfdb-search')?.addEventListener('input',e=>{const q=e.target.value.trim().toLowerCase();document.querySelectorAll('#vfdb-list .vfdb-file').forEach(n=>n.hidden=!!q&&!n.dataset.name.includes(q));document.querySelectorAll('#vfdb-list .vfdb-folder').forEach(n=>{if(!q){n.hidden=false;return;}const visible=[...n.querySelectorAll('.vfdb-file')].some(f=>!f.hidden);n.hidden=!visible;if(visible)n.open=true;});});
  document.addEventListener('click',e=>{const b=e.target.closest('button,a');if(b?.textContent?.trim()==='Dokumente'){e.preventDefault();box.open=true;load();box.scrollIntoView({behavior:'smooth',block:'start'});}});
  new MutationObserver(()=>{if(activeCase()?.folder_id&&!box.dataset.loaded){box.dataset.loaded='1';load();}if(!activeCase()?.folder_id){box.dataset.loaded='';box.hidden=true;}}).observe(document.body,{subtree:true,childList:true,characterData:true});
  if(activeCase()?.folder_id){box.dataset.loaded='1';load();}
})();
