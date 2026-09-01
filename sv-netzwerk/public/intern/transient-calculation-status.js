(()=>{
  if(!location.pathname.startsWith('/intern/versicherungsfaelle'))return;
  const API='/intern/api/gf-ai-generate.php';
  const interruptedTexts=['Verarbeitung unterbrochen','Bearbeitung angehalten','Failed to fetch','Verbindung zur Bearbeitung unterbrochen'];
  const resumeStore='svnet-ai-resume-attempts-v2';
  let recovering=false;
  let timer=0;

  function activeCase(){
    for(const storage of[sessionStorage,localStorage]){
      try{const row=JSON.parse(storage.getItem('svnet-case')||'null');if(row?.folder_id)return row}catch{}
    }
    return null;
  }

  async function post(body){
    const response=await fetch(API,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
    const json=await response.json().catch(()=>({}));
    if(!response.ok)throw new Error(json.error||`HTTP ${response.status}`);
    return json;
  }

  const escapeHtml=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));

  function resumeAttempts(){
    try{return JSON.parse(localStorage.getItem(resumeStore)||'{}')||{}}catch{return {}}
  }

  function rememberResume(jobId){
    const attempts=resumeAttempts();
    attempts[String(jobId)]=Date.now();
    for(const[id,stamp]of Object.entries(attempts))if(!Number(stamp)||Number(stamp)<Date.now()-7*24*60*60*1000)delete attempts[id];
    localStorage.setItem(resumeStore,JSON.stringify(attempts));
  }

  function interruptedBox(){
    const box=document.getElementById('vf-job');
    const text=box?.textContent||'';
    return box&&interruptedTexts.some(needle=>text.includes(needle))?box:null;
  }

  function renderDone(box,job){
    const files=job.result?.created||[];
    box.className='vf-job ok';
    box.innerHTML='<strong>Dokument vollständig erstellt.</strong>'+(files.length?'<ul>'+files.map(file=>`<li><a href="${escapeHtml(file.webViewLink||'#')}" target="_blank" rel="noreferrer">${escapeHtml(file.name||'Dokument öffnen')}</a></li>`).join('')+'</ul>':'');
    window.dispatchEvent(new CustomEvent('svnet:cases-changed'));
  }

  function renderStopped(box,message){
    box.className='vf-job bad';
    box.innerHTML='<strong>Dokumenterstellung wurde angehalten.</strong><br><span class="vf-meta">'+escapeHtml(message||'Der Auftrag konnte nicht abgeschlossen werden.')+'</span>';
  }

  async function poll(jobId,box,deadline=Date.now()+12*60*1000){
    window.clearTimeout(timer);
    try{
      const data=await post({action:'status',job_id:Number(jobId)}),job=data.job||{};
      if(job.status==='done'){renderDone(box,job);return}
      if(job.status==='failed'){renderStopped(box,job.error_text||job.message);return}
      if(job.recoverable){renderStopped(box,'Die einmalige Wiederaufnahme blieb erfolglos. Es wird kein weiterer KI-Aufruf automatisch gestartet.');return}
      if(Date.now()>deadline){renderStopped(box,'Die Statusprüfung wurde nach zwölf Minuten beendet. Es wird kein weiterer KI-Aufruf automatisch gestartet.');return}
      box.className='vf-job';
      box.innerHTML='<strong>'+escapeHtml(job.message||'Dokument wird erstellt …')+'</strong>';
      timer=window.setTimeout(()=>poll(jobId,box,deadline),2500);
    }catch(error){
      if(Date.now()>deadline){renderStopped(box,String(error?.message||error));return}
      timer=window.setTimeout(()=>poll(jobId,box,deadline),5000);
    }
  }

  async function recoverOnce(fromVisibleInterruption=true){
    if(recovering)return;
    const box=document.getElementById('vf-job'),current=activeCase();
    if(!box||!current?.folder_id||(fromVisibleInterruption&&!interruptedBox()))return;
    recovering=true;
    box.hidden=false;
    try{
      const latest=await post({action:'latest',folder_id:current.folder_id}),jobId=Number(latest.job_id||0);
      if(!jobId)throw new Error('Kein laufender Auftrag gefunden.');
      const status=await post({action:'status',job_id:jobId}),job=status.job||{};
      if(job.status==='done'){if(fromVisibleInterruption)renderDone(box,job);return}
      if(job.status==='failed'){if(fromVisibleInterruption)renderStopped(box,job.error_text||job.message);return}
      if(job.recoverable){
        if(resumeAttempts()[String(jobId)]){renderStopped(box,'Dieser Auftrag wurde bereits einmal wiederaufgenommen. Zum Kostenschutz erfolgt keine weitere automatische Wiederholung.');return}
        rememberResume(jobId);
        box.className='vf-job';
        box.innerHTML='<strong>Rekon-Schadenbericht wird einmalig fortgesetzt …</strong>';
        await post({action:'resume',job_id:jobId});
      }
      await poll(jobId,box);
    }catch(error){
      renderStopped(box,String(error?.message||error));
    }finally{
      recovering=false;
    }
  }

  function start(){
    const box=document.getElementById('vf-job');
    if(!box)return;
    new MutationObserver(()=>{if(interruptedBox())recoverOnce()}).observe(box,{childList:true,subtree:true,characterData:true});
    if(interruptedBox())recoverOnce();
    // Nach einem Seitenneuladen ist die sichtbare Statusmeldung verloren, der
    // serverseitige Auftrag besteht jedoch weiter. Den aktiven Fall kurz
    // abwarten und ausschließlich laufende oder wiederaufnehmbare Jobs prüfen.
    let attempts=0;
    const restore=()=>{
      if(activeCase()){recoverOnce(false);return}
      if(++attempts<12)window.setTimeout(restore,500);
    };
    window.setTimeout(restore,500);
  }

  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});
  else start();
})();
