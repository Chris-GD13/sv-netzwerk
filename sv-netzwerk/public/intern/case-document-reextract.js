(()=>{
  if(!location.pathname.startsWith('/intern/versicherungsfaelle'))return;
  const $=id=>document.getElementById(id);
  const API='/intern/api/google-drive-sync.php';
  const EX='/intern/api/insurance-case-extract.php';
  const FILES='/intern/api/case-file-browser.php';
  const fieldMap={schaden_nr:'vf-schaden',versicherungsschein_nr:'vf-vsnr',vn_objekt:'vf-object',strasse:'vf-strasse',plz:'vf-plz',ort:'vf-ort',schaden_strasse:'vf-schaden-strasse',schaden_plz:'vf-schaden-plz',schaden_ort:'vf-schaden-ort',schadenart:'vf-art',reserve:'vf-reserve',telefon:'vf-telefon',mobil:'vf-mobil',email:'vf-email',sanierer_firma:'vf-sanierer-firma',sanierer_ansprechpartner:'vf-sanierer-ansprechpartner',sanierer_funktion:'vf-sanierer-funktion',sanierer_telefon:'vf-sanierer-telefon',sanierer_mobil:'vf-sanierer-mobil',sanierer_email:'vf-sanierer-email',sanierer_strasse:'vf-sanierer-strasse',sanierer_plz:'vf-sanierer-plz',sanierer_ort:'vf-sanierer-ort',sanierer_fax:'vf-sanierer-fax',sanierer_website:'vf-sanierer-website'};
  const supported=/\.(?:pdf|doc|docx|xls|xlsx|xlsm|csv|txt|md|rtf|msg|eml|jpe?g|png|webp|tiff?)$/i;
  let busy=false;

  function activeCase(){
    for(const storage of[sessionStorage,localStorage]){
      try{const data=JSON.parse(storage.getItem('svnet-case')||'null');if(data?.folder_id)return data}catch{}
    }
    return null;
  }
  async function json(url,options={}){
    const response=await fetch(url,{credentials:'same-origin',...options});
    const data=await response.json().catch(()=>({}));
    if(!response.ok)throw Error(data.error||`HTTP ${response.status}`);
    return data;
  }
  function currentMeta(current){
    const meta={...(current.meta||{})};
    for(const[key,id]of Object.entries(fieldMap)){
      const value=$(id)?.value?.trim()||'';
      if(value)meta[key]=value;
    }
    return meta;
  }
  function mergeFields(meta,fields){
    let filled=0;
    for(const[key,raw]of Object.entries(fields||{})){
      const value=String(raw||'').trim();
      if(!value||String(meta[key]||'').trim())continue;
      meta[key]=value;filled++;
      const input=$(fieldMap[key]);if(input&&!input.value.trim())input.value=value;
    }
    return filled;
  }
  async function save(current,meta){
    await json(`${API}?action=save_case`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({folder_id:current.folder_id,case:meta})});
    const stored={folder_id:current.folder_id,meta};
    sessionStorage.setItem('svnet-case',JSON.stringify(stored));
    localStorage.setItem('svnet-case',JSON.stringify(stored));
  }
  async function extractFiles(files,label='Unterlagen'){
    const current=activeCase(),status=$('vf-reextract-state');
    if(!current?.folder_id||!files.length||busy)return;
    busy=true;let processed=0,filled=0;const errors=[],meta=currentMeta(current);
    try{
      for(const file of files){
        if(!supported.test(file.name||''))continue;
        status.textContent=`${label}: ${file.name} wird ausgelesen …`;
        const form=new FormData();form.append('file',file,file.name);
        try{const data=await json(EX,{method:'POST',body:form});filled+=mergeFields(meta,data.fields);processed++}catch(error){errors.push(`${file.name}: ${error.message}`)}
      }
      if(!processed&&errors.length)throw Error(errors.join(' · '));
      if(!processed)throw Error('Keine unterstützte Unterlage zum Auslesen gefunden.');
      await save(current,meta);
      status.className='vf-meta '+(errors.length?'vf-reextract-warn':'vf-reextract-ok');
      status.textContent=`Erneut eingelesen: ${processed} Unterlage${processed===1?'':'n'} · ${filled} leere${filled===1?'s Feld':' Felder'} ergänzt.${errors.length?' Nicht vollständig: '+errors.join(' · '):''}`;
    }catch(error){status.className='vf-meta vf-reextract-bad';status.textContent=`Erneutes Einlesen fehlgeschlagen: ${error.message}`}
    finally{busy=false}
  }
  function flatten(items,out=[]){for(const item of items||[]){if(item.folder)flatten(item.children,out);else if(supported.test(item.name||'')&&!/^00_(?:Falldaten|Dokumentauftrag)/i.test(item.name||''))out.push(item)}return out}
  async function reextractStored(){
    const current=activeCase(),status=$('vf-reextract-state');
    if(!current?.folder_id){status.className='vf-meta vf-reextract-bad';status.textContent='Bitte zuerst einen Fall öffnen oder speichern.';return}
    if(busy)return;
    status.className='vf-meta';status.textContent='Gespeicherte Fallakte wird geladen …';
    try{
      const tree=await json(`${FILES}?folder_id=${encodeURIComponent(current.folder_id)}`);
      const rows=flatten(tree.items).sort((a,b)=>String(b.modifiedTime||'').localeCompare(String(a.modifiedTime||'')));
      if(!rows.length)throw Error('Im Fallordner wurden keine unterstützten Unterlagen gefunden.');
      const downloaded=[];
      for(const item of rows){
        status.textContent=`Gespeicherte Unterlage wird geladen: ${item.name}`;
        const response=await fetch(`${FILES}?folder_id=${encodeURIComponent(current.folder_id)}&file_id=${encodeURIComponent(item.id)}&action=download`,{credentials:'same-origin'});
        if(!response.ok)throw Error(`${item.name} konnte nicht geladen werden.`);
        const blob=await response.blob();
        if(blob.size>40*1024*1024)throw Error(`${item.name} ist größer als 40 MB.`);
        downloaded.push(new File([blob],item.name,{type:blob.type||item.mimeType||'application/octet-stream'}));
      }
      await extractFiles(downloaded,'Gespeicherte Akte');
    }catch(error){status.className='vf-meta vf-reextract-bad';status.textContent=`Erneutes Einlesen fehlgeschlagen: ${error.message}`}
  }

  const card=document.querySelector('.vf-upload-card');
  if(!card||$('vf-reextract'))return;
  const wrap=document.createElement('div');wrap.className='vf-reextract';
  wrap.innerHTML='<button type="button" id="vf-reextract" class="vf-secondary">Gespeicherte Unterlagen erneut einlesen</button><div id="vf-reextract-state" class="vf-meta">Ergänzt nur leere Falldaten; vorhandene Eingaben bleiben erhalten.</div>';
  card.appendChild(wrap);
  $('vf-reextract').addEventListener('click',reextractStored);
  $('vf-files')?.addEventListener('change',event=>{if(activeCase()?.folder_id)extractFiles([...event.currentTarget.files],'Neue Unterlagen')});
  $('vf-drop')?.addEventListener('drop',event=>{if(activeCase()?.folder_id)extractFiles([...event.dataTransfer.files],'Neue Unterlagen')});
})();
