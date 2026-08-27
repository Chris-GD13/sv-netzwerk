(()=>{
  if(!location.pathname.startsWith('/intern/versicherungsfaelle')) return;
  const API='/intern/api/gf-ai-generate.php';
  const seen=new Set();
  let busy=false;

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
    const text=box.textContent||'';
    return text.includes('Verarbeitung unterbrochen')?box:null;
  }

  async function resume(){
    if(busy)return;
    const box=interruptedBox();
    if(!box)return;
    const current=activeCase();
    if(!current?.folder_id)return;
    busy=true;
    const previous=box.innerHTML;
    box.innerHTML='<strong>ChatGPT arbeitet …</strong>';
    box.hidden=false;
    try{
      const latest=await post({action:'latest',folder_id:current.folder_id});
      const key=String(latest.job_id||'');
      if(!key)throw new Error('Kein laufender Auftrag gefunden.');
      if(seen.has(key))return;
      seen.add(key);
      try{await post({action:'resume',job_id:latest.job_id});}
      catch(error){
        const msg=String(error?.message||'');
        if(!msg.includes('nicht unterbrochen')&&!msg.includes('bereits fortgesetzt'))throw error;
      }
      // Die bestehende Portal-Pollinglogik übernimmt ab hier wieder.
      setTimeout(()=>{if((box.textContent||'').includes('ChatGPT arbeitet'))box.innerHTML='<strong>ChatGPT arbeitet …</strong>';},500);
    }catch(error){
      box.innerHTML=previous;
      const detail=document.createElement('div');
      detail.className='vf-meta';
      detail.textContent=String(error?.message||error);
      box.appendChild(detail);
    }finally{
      busy=false;
    }
  }

  const observer=new MutationObserver(()=>resume());
  function start(){
    const box=document.getElementById('vf-job');
    if(!box)return;
    observer.observe(box,{childList:true,subtree:true,characterData:true});
    resume();
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',start,{once:true});else start();
})();
