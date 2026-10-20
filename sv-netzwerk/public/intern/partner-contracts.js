(() => {
  'use strict';
  const form=document.querySelector('#pc-form');if(!form)return;
  const el=id=>document.getElementById(id);
  let csrf='',defaults={},id='',revision=0,dirty=false,busy=false,archived=null,showArchive=false;
  const state=text=>{el('pc-state').textContent=text;};
  const buttons=()=>{for(const x of [el('pc-save'),el('pc-preview'),el('pc-new'),el('pc-archive'),el('pc-undo')])x.disabled=busy;};
  async function api(action,payload){
    const response=await fetch('/intern/api/partner-contracts.php?action='+action,{credentials:'same-origin',cache:'no-store',...(payload?{method:'POST',headers:{'Content-Type':'application/json','X-Partner-CSRF':csrf},body:JSON.stringify(payload)}:{})});
    const data=await response.json();if(!response.ok||!data.ok)throw new Error(data.error||'Vorgang fehlgeschlagen.');return data;
  }
  function data(){return Object.fromEntries(new FormData(form).entries());}
  function load(record){
    id=record?.id||crypto.randomUUID();revision=Number(record?.revision||0);dirty=false;
    const d=record?.data||defaults;for(const [key,value]of Object.entries(d)){const field=form.elements.namedItem(key);if(field)field.value=value;}
    el('pc-title').textContent=d.partner_name||'Neue Partnervereinbarung';
    el('pc-pdf').hidden=revision===0;el('pc-archive').hidden=revision===0;
    el('pc-pdf').href='/intern/api/partner-contracts.php?action=pdf&id='+encodeURIComponent(id);
    el('pc-preview-section').hidden=true;
    state(revision?'Gespeichert · Revision '+revision+((record.missing||[]).length?' · Noch auszufüllen: '+record.missing.join(', '):' · Pflichtangaben vollständig; Annahme noch einzuholen.'):'Neuer Entwurf · noch nicht gespeichert.');
  }
  async function list(){
    const result=await api('list&archived='+(showArchive?'1':'0'));el('pc-list').replaceChildren();
    if(!result.items.length){el('pc-list').textContent=showArchive?'Keine archivierten Entwürfe.':'Noch keine gespeicherten Entwürfe.';return;}
    for(const item of result.items){const button=document.createElement('button');button.type='button';button.className='partner-record';button.textContent=item.name+' · R'+item.revision;
      if(showArchive)button.textContent+=' · Wiederherstellen';
      button.addEventListener('click',async()=>{if(busy)return;if(showArchive){busy=true;buttons();try{await api('restore',{id:item.id,revision:Number(item.revision)});await list();el('pc-archive-result').textContent='Entwurf wiederhergestellt.';}catch(error){state(error.message);}finally{busy=false;buttons();}return;}if(dirty&&!confirm('Ungespeicherte Eingaben verwerfen und diesen Entwurf öffnen?'))return;try{load((await api('get&id='+encodeURIComponent(item.id))).record);}catch(error){state(error.message);}});el('pc-list').append(button);}
  }
  async function save(preview=false){
    if(busy||!csrf)return;
    if(!form.reportValidity())return;
    busy=true;buttons();
    const payload=data();for(const field of form.elements)field.disabled=true;
    try{const result=await api('save',{id,revision,data:payload});load(result.record);await list();if(preview){const response=await fetch('/intern/api/partner-contracts.php?action=preview&id='+encodeURIComponent(id),{credentials:'same-origin',cache:'no-store'});if(!response.ok)throw new Error('Gespeichert; Vorschau konnte nicht geladen werden.');el('pc-preview-frame').srcdoc=await response.text();el('pc-preview-section').hidden=false;el('pc-preview-section').scrollIntoView({behavior:'smooth'});}}
    catch(error){state(error.message+' Ihre Eingaben bleiben erhalten.');}
    finally{for(const field of form.elements)field.disabled=false;busy=false;buttons();}
  }
  form.addEventListener('submit',event=>{event.preventDefault();save();});
  form.addEventListener('input',()=>{dirty=true;el('pc-pdf').hidden=true;state('Ungespeicherte Änderungen. Vor PDF-Ausgabe speichern.');});
  el('pc-new').addEventListener('click',()=>{if(dirty&&!confirm('Ungespeicherte Eingaben verwerfen?'))return;load(null);});
  el('pc-show-archive').addEventListener('click',async()=>{if(busy)return;showArchive=!showArchive;el('pc-show-archive').textContent=showArchive?'Aktive Entwürfe anzeigen':'Archiv anzeigen';try{await list();}catch(error){state(error.message);}});
  el('pc-preview').addEventListener('click',()=>save(true));
  el('pc-archive').addEventListener('click',async()=>{
    if(busy)return;if(dirty&&!confirm('Ungespeicherte Änderungen verwerfen und gespeicherten Entwurf archivieren?'))return;
    busy=true;buttons();try{await api('archive',{id,revision});archived={id,revision:revision+1};load(null);await list();el('pc-archive-result').textContent='Entwurf archiviert. Er kann wiederhergestellt werden.';el('pc-undo').hidden=false;}catch(error){state(error.message);}finally{busy=false;buttons();}
  });
  el('pc-undo').addEventListener('click',async()=>{if(!archived||busy)return;busy=true;buttons();try{await api('restore',archived);await list();el('pc-archive-result').textContent='Entwurf wiederhergestellt.';el('pc-undo').hidden=true;archived=null;}catch(error){state(error.message);}finally{busy=false;buttons();}});
  window.addEventListener('beforeunload',event=>{if(dirty)event.preventDefault();});
  (async()=>{try{const result=await api('status');csrf=result.csrf;defaults=result.defaults;load(null);await list();}catch(error){state(error.message);}})();
})();
