(()=>{
  const API='/intern/api/whatsapp-case.php';
  const DRIVE_API='/intern/api/google-drive-sync.php';
  const messages=document.getElementById('wa-messages');
  const recipient=document.getElementById('wa-recipient-phone');
  const message=document.getElementById('wa-message-text');
  const caseFolder=document.getElementById('wa-case-folder');
  const caseNumber=document.getElementById('wa-case-number');
  const selectedCase=document.getElementById('wa-selected-case');
  const templateSelect=document.getElementById('wa-template-select');
  const composeState=document.getElementById('wa-compose-state');
  if(!messages)return;
  const request=async(url,options={})=>{const response=await fetch(url,{credentials:'same-origin',cache:'no-store',...options}),data=await response.json().catch(()=>({}));if(!response.ok||data.ok===false)throw Error(data.error||`HTTP ${response.status}`);return data};
  const state=(text,kind='')=>{if(!composeState)return;composeState.textContent=text;composeState.className='wa-compose-state '+kind};
  const esc=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
  let rows=[];
  const assign=async row=>{
    const query=prompt('Schaden-Nr. oder Name des Versicherungsnehmers eingeben:');
    if(!query||query.trim().length<2)return;
    try{
      const data=await request(`${DRIVE_API}?action=search_cases&q=${encodeURIComponent(query.trim())}`);
      const hits=(data.results||[]).slice(0,10);
      if(!hits.length){state('Kein passender Schadenfall gefunden.','bad');return}
      let pick=hits[0];
      if(hits.length>1){
        const text=hits.map((r,i)=>`${i+1}: ${(r.meta||{}).schaden_nr||r.name||'Schadenfall'} · ${(r.meta||{}).vn_objekt||''}`).join('\n');
        const chosen=prompt(`Mehrere Treffer gefunden:\n${text}\n\nNummer auswählen:`,'1');
        const index=Number(chosen)-1;
        if(!Number.isInteger(index)||index<0||index>=hits.length)return;
        pick=hits[index];
      }
      const meta=pick.meta||{};
      const no=meta.schaden_nr||'';
      await request(API+'?action=assign_message',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({wamid:row.wamid,folder_id:pick.id||'',case_no:no})});
      state(`WhatsApp wurde dem Schadenfall ${no||pick.name||''} zugeordnet.`,'ok');
      await enhance();
    }catch(error){state(error.message,'bad')}
  };
  const reply=row=>{
    if(!row.sender_phone){state('Absender-Rufnummer fehlt.','bad');return}
    if(recipient)recipient.value=row.sender_phone;
    if(templateSelect)templateSelect.value='';
    if(caseFolder)caseFolder.value=row.folder_id||'';
    if(caseNumber)caseNumber.value=row.case_no||'';
    if(selectedCase){if(row.case_no){selectedCase.textContent=`Antwort wird Schadenfall ${row.case_no} zugeordnet.`;selectedCase.hidden=false}else selectedCase.hidden=true}
    state(`Antwort an ${row.sender_phone} vorbereitet.`,'ok');
    message?.focus();
    message?.scrollIntoView({behavior:'smooth',block:'center'});
  };
  const enhance=async()=>{
    try{const data=await request(API+'?action=recent');rows=data.messages||[]}catch{return}
    const cards=[...messages.querySelectorAll('.wa-message')];
    cards.forEach((card,index)=>{
      const row=rows[index];if(!row)return;
      const middle=card.querySelector('span');
      if(middle&&!middle.querySelector('.wa-origin')){
        const origin=document.createElement('div');origin.className='wa-origin';origin.style.fontWeight='800';origin.style.marginBottom='5px';origin.textContent=`${row.direction==='inbound'?'Von':'An'}: ${row.sender_phone||'Rufnummer unbekannt'}`;middle.prepend(origin)
      }
      let actions=card.querySelector('.wa-message-actions');
      if(!actions){actions=document.createElement('div');actions.className='wa-message-actions';card.append(actions)}
      if(row.direction==='inbound'&&!actions.querySelector('.wa-reply')){const b=document.createElement('button');b.type='button';b.className='wa-reply';b.textContent='Antworten';b.addEventListener('click',()=>reply(row));actions.prepend(b)}
      if(!row.folder_id&&!actions.querySelector('.wa-assign-real')){const old=[...actions.querySelectorAll('a')].find(a=>/Manuell zuordnen/i.test(a.textContent||''));if(old)old.remove();const b=document.createElement('button');b.type='button';b.className='wa-assign-real';b.textContent='Fall zuordnen';b.addEventListener('click',()=>assign(row));actions.prepend(b)}
    })
  };
  const observer=new MutationObserver(()=>{clearTimeout(observer.t);observer.t=setTimeout(enhance,100)});
  observer.observe(messages,{childList:true,subtree:true});
  enhance();
})();
