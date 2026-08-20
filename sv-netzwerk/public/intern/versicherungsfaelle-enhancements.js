(()=>{
  if(!location.pathname.startsWith('/intern/versicherungsfaelle')) return;
  const el=id=>document.getElementById(id);
  const section=el('vf-new-case');
  if(!section || section.dataset.enhanced==='1') return;
  section.dataset.enhanced='1';

  const style=document.createElement('style');
  style.textContent=`
    #vf-new-case{overflow:hidden}
    #vf-new-case .vf-case-row{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px;align-items:end;width:100%;min-width:0;padding:2px 0 8px;overflow:visible}
    #vf-new-case .vf-case-row>label{min-width:0;width:auto}
    #vf-new-case .vf-case-row>label.vf-wide{grid-column:span 2}
    #vf-new-case .vf-case-row .vf-save-inline{display:flex;align-items:flex-end;gap:8px;min-width:0}
    #vf-new-case .vf-case-row .vf-input{height:44px;min-width:0;width:100%}
    #vf-new-case .vf-save-inline .sv-button{white-space:nowrap}
    #vf-case-autofill-drop{margin:10px 0 14px;border:2px dashed #b8c8d6;border-radius:12px;padding:14px 16px;background:#f8fbfd;cursor:pointer;display:flex;align-items:center;justify-content:space-between;gap:14px;min-height:48px;max-width:100%;box-sizing:border-box}
    #vf-case-autofill-drop.is-dragging{border-color:#ff970f;background:#fff7eb}
    #vf-case-autofill-drop strong{display:block}
    #vf-case-autofill-drop span{font-size:.9rem;color:#63768a}
    #vf-case-autofill-status{margin-top:-6px;margin-bottom:10px}
    @media(max-width:1350px){#vf-new-case .vf-case-row{grid-template-columns:repeat(5,minmax(0,1fr))}}
    @media(max-width:1100px){#vf-new-case .vf-case-row{grid-template-columns:repeat(4,minmax(0,1fr))}}
    @media(max-width:850px){#vf-new-case .vf-case-row{grid-template-columns:repeat(2,minmax(0,1fr))}#vf-new-case .vf-case-row>label.vf-wide{grid-column:span 1}#vf-case-autofill-drop{align-items:flex-start;flex-direction:column}}
    @media(max-width:560px){#vf-new-case .vf-case-row{grid-template-columns:1fr}}
  `;
  document.head.appendChild(style);

  const heading=section.querySelector('h2');
  const originalGrid=heading?.nextElementSibling;
  if(!(originalGrid instanceof HTMLElement)) return;
  originalGrid.classList.add('vf-case-row');
  originalGrid.removeAttribute('style');

  const fields=[
    ['vf-plz','PLZ','plz',''],['vf-phone','Telefon','telefon',''],['vf-mobile','Mobil','mobil',''],['vf-email','E-Mail','email','email'],['vf-vorsteuer','Vorsteuerabzugsberechtigt','vorsteuer',''],['vf-loss-date','Schadentag','schadentag',''],['vf-report-date','Schadenmeldung am','meldedatum',''],['vf-broker','Vermittler / Firma','vermittler_firma',''],['vf-broker-contact','Vermittler Ansprechpartner','vermittler_ansprechpartner',''],['vf-broker-phone','Vermittler Telefon','vermittler_telefon',''],['vf-broker-mobile','Vermittler Mobil','vermittler_mobil',''],['vf-broker-fax','Vermittler Fax','vermittler_fax',''],['vf-broker-email','Vermittler E-Mail','vermittler_email','email']
  ];
  for(const [id,label,key,type] of fields){
    if(el(id)) continue;
    const node=document.createElement('label');
    if(label.includes('Vermittler')) node.classList.add('vf-wide');
    node.innerHTML=`<strong>${label}</strong><input class="vf-input" id="${id}" data-case-key="${key}" type="${type||'text'}" />`;
    originalGrid.appendChild(node);
  }

  const actionWrap=section.querySelector('.intern-actions');
  const save=el('vf-save-case');
  const saveStatus=el('vf-save-status');
  if(save){
    save.textContent='Speichern';
    const wrap=document.createElement('div');wrap.className='vf-save-inline';wrap.appendChild(save);if(saveStatus) wrap.appendChild(saveStatus);originalGrid.appendChild(wrap);if(actionWrap && !actionWrap.children.length) actionWrap.remove();
  }

  const fileInput=document.createElement('input');fileInput.type='file';fileInput.multiple=true;fileInput.hidden=true;fileInput.id='vf-case-autofill-files';fileInput.accept='.pdf,.doc,.docx,.txt,.csv,.jpg,.jpeg,.png,.webp,.tif,.tiff';
  const drop=document.createElement('div');drop.id='vf-case-autofill-drop';drop.tabIndex=0;drop.innerHTML='<div><strong>Unterlagen zur Fallanlage hier hineinziehen</strong><span>KI liest Schaden-Nr., Versicherungsschein-Nr., VN, Adresse, Telefon, Mobil, E-Mail, Vermittler, Schadenart und weitere Falldaten aus.</span></div><span>Loslassen oder klicken</span>';
  const status=document.createElement('div');status.id='vf-case-autofill-status';status.className='intern-meta';status.textContent='Noch keine Unterlage zur automatischen Befüllung eingelesen.';
  heading?.insertAdjacentElement('afterend',fileInput);fileInput.insertAdjacentElement('afterend',drop);drop.insertAdjacentElement('afterend',status);

  const fieldMap={schaden_nr:'vf-schaden',versicherungsschein_nr:'vf-vsnr',vn_objekt:'vf-object',strasse:'vf-strasse',plz:'vf-plz',ort:'vf-ort',telefon:'vf-phone',mobil:'vf-mobile',email:'vf-email',vorsteuer:'vf-vorsteuer',schadenart:'vf-type',schadentag:'vf-loss-date',meldedatum:'vf-report-date',reserve:'vf-reserve',kontakt:'vf-contact',vermittler_firma:'vf-broker',vermittler_ansprechpartner:'vf-broker-contact',vermittler_telefon:'vf-broker-phone',vermittler_mobil:'vf-broker-mobile',vermittler_fax:'vf-broker-fax',vermittler_email:'vf-broker-email'};
  const extraKeys=Object.fromEntries(fields.map(([id,,key])=>[key,id]));
  function fillExtra(meta={}){Object.entries(extraKeys).forEach(([key,id])=>{const n=el(id);if(n && !n.value && meta[key]) n.value=String(meta[key]);});}
  function currentExtras(){const out={};Object.entries(extraKeys).forEach(([key,id])=>{out[key]=el(id)?.value?.trim()||'';});return out;}
  function readActive(){for(const storage of [sessionStorage,localStorage]){try{const d=JSON.parse(storage.getItem('svnet-case')||'null');if(d?.folder_id)return d;}catch{}}return null;}
  fillExtra(readActive()?.meta||{});
  const active=el('vf-active-case');if(active) new MutationObserver(()=>fillExtra(readActive()?.meta||{})).observe(active,{childList:true,subtree:true,characterData:true});

  async function analyze(files){
    if(!files?.length) return;section.hidden=false;let filled=0;status.textContent=`${files.length} Unterlage/-n werden ausgelesen …`;
    for(let i=0;i<files.length;i++){
      const fd=new FormData();fd.append('file',files[i]);
      try{
        status.textContent=`Unterlage ${i+1}/${files.length} wird KI-gestützt ausgelesen: ${files[i].name}`;
        const r=await fetch('/intern/api/insurance-case-extract.php',{method:'POST',body:fd,credentials:'same-origin'});const j=await r.json().catch(()=>({}));if(!r.ok) throw new Error(j.error||`HTTP ${r.status}`);
        for(const [key,value] of Object.entries(j.fields||{})){if(!value || !fieldMap[key]) continue;const n=el(fieldMap[key]);if(n && !n.value){n.value=String(value);filled++;}}
      }catch(e){status.textContent=`Auslesen fehlgeschlagen: ${e instanceof Error?e.message:String(e)}`;return;}
    }
    status.textContent=`Auslesen abgeschlossen · ${filled} Feld(er) automatisch befüllt. Bitte prüfen, ergänzen und anschließend speichern.`;
  }
  drop.addEventListener('click',()=>fileInput.click());drop.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();fileInput.click();}});fileInput.addEventListener('change',()=>analyze(fileInput.files));['dragenter','dragover'].forEach(t=>drop.addEventListener(t,e=>{e.preventDefault();drop.classList.add('is-dragging');}));['dragleave','drop'].forEach(t=>drop.addEventListener(t,e=>{e.preventDefault();drop.classList.remove('is-dragging');}));drop.addEventListener('drop',e=>analyze(e.dataTransfer?.files));

  let extraSaveBusy=false;
  if(saveStatus){new MutationObserver(async()=>{if(extraSaveBusy || !saveStatus.textContent?.includes('In Google Drive gespeichert')) return;const activeCase=readActive();const folderId=el('vf-folder-id')?.value||activeCase?.folder_id||'';if(!folderId) return;extraSaveBusy=true;try{const meta={...(activeCase?.meta||{}),...currentExtras()};const r=await fetch('/intern/api/google-drive-sync.php?action=save_case',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify({folder_id:folderId,case:meta})});const j=await r.json().catch(()=>({}));if(!r.ok)throw new Error(j.error||`HTTP ${r.status}`);const stored={folder_id:j.folder_id||folderId,meta};sessionStorage.setItem('svnet-case',JSON.stringify(stored));localStorage.setItem('svnet-case',JSON.stringify(stored));saveStatus.textContent='In Google Drive gespeichert und als aktiver Fall übernommen.';}catch(e){saveStatus.textContent=`Falldaten gespeichert, Zusatzdaten konnten nicht vollständig übernommen werden: ${e instanceof Error?e.message:String(e)}`;}finally{setTimeout(()=>{extraSaveBusy=false;},400);}}).observe(saveStatus,{childList:true,subtree:true,characterData:true});}
})();
