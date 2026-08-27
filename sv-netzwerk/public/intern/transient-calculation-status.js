(()=>{
  if(!location.pathname.startsWith('/intern/versicherungsfaelle')) return;
  const API='/intern/api/gf-ai-generate.php';
  const transientText='Verarbeitung unterbrochen';
  const resumedJobs=new Set();
  let resuming=false;

  function activeCase(){
    for(const storage of [sessionStorage,localStorage]){
      try{const row=JSON.parse(storage.getItem('svnet-case')||'null');if(row?.folder_id)return row;}catch{}
    }
    return null;
  }

  async function post(body){
    const response=await fetch(API,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
    const json=await response.json().catch(()=>({}));
    if(!response.ok)throw new Error(json.error||`HTTP ${response.status}`);
    return json;
  }

  function interruptedBox(){
    const box=document.getElementById('vf-job');
    if(!box)return null;
    return (box.textContent||'').includes(transientText)?box:null;
  }

  async function autoResume(){
    if(resuming)return;
    const box=interruptedBox();
    if(!box)return;
    const current=activeCase();
    if(!current?.folder_id)return;
    resuming=true;
    box.innerHTML='<strong>ChatGPT arbeitet …</strong>';
    box.hidden=false;
    try{
      const latest=await post({action:'latest',folder_id:current.folder_id});
      const jobId=String(latest.job_id||'');
      if(!jobId)throw new Error('Kein laufender Auftrag gefunden.');
      if(!resumedJobs.has(jobId)){
        resumedJobs.add(jobId);
        try{await post({action:'resume',job_id:latest.job_id});}
        catch(error){
          const msg=String(error?.message||'');
          if(!msg.includes('nicht unterbrochen')&&!msg.includes('bereits fortgesetzt'))throw error;
        }
      }
      // Die vorhandene Pollinglogik übernimmt danach wieder den aktuellen Jobstatus.
      setTimeout(()=>{
        if((box.textContent||'').includes(transientText))box.innerHTML='<strong>ChatGPT arbeitet …</strong>';
      },300);
    }catch(error){
      box.innerHTML='<strong>Bearbeitung angehalten.</strong><br><span class="vf-meta"></span>';
      const detail=box.querySelector('.vf-meta');
      if(detail)detail.textContent=String(error?.message||error);
    }finally{
      resuming=false;
    }
  }

  function scan(){
    if(interruptedBox())autoResume();
  }

  function start(){
    const box=document.getElementById('vf-job');
    if(!box)return;
    new MutationObserver(scan).observe(box,{childList:true,subtree:true,characterData:true});
    scan();
  }

  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});
  else start();
})();
