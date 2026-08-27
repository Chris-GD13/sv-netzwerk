(()=>{
  if(!location.pathname.startsWith('/intern/versicherungsfaelle')) return;
  const transientText='Verarbeitung unterbrochen';
  const calculationText='Kalkulation:';
  const successHints=['Erstellte Dokumente','Kalkulation wurde','Kalkulation erstellt','Kalkulation gespeichert'];
  const pending=new WeakMap();

  function isTransientBox(el){
    if(!(el instanceof HTMLElement)) return false;
    const text=(el.textContent||'').trim();
    return text.includes(calculationText)&&text.includes(transientText);
  }

  function hasSuccess(){
    const page=document.body?.innerText||'';
    return successHints.some(h=>page.includes(h)) && !page.includes('Noch keine erzeugten Dokumente vorhanden.');
  }

  function deferBox(box){
    if(pending.has(box)) return;
    const previousDisplay=box.style.display;
    box.style.display='none';
    box.dataset.transientCalculationNotice='1';
    const timer=setTimeout(()=>{
      pending.delete(box);
      if(!box.isConnected) return;
      if(hasSuccess()){
        box.remove();
        return;
      }
      if(isTransientBox(box)) box.style.display=previousDisplay;
    },12000);
    pending.set(box,timer);
  }

  function scan(root=document){
    const candidates=[];
    if(root instanceof HTMLElement&&isTransientBox(root)) candidates.push(root);
    root.querySelectorAll?.('div,section,p').forEach(el=>{if(isTransientBox(el)) candidates.push(el);});
    candidates.sort((a,b)=>a.querySelectorAll('*').length-b.querySelectorAll('*').length);
    if(candidates[0]) deferBox(candidates[0]);

    if(hasSuccess()){
      document.querySelectorAll('[data-transient-calculation-notice="1"]').forEach(el=>el.remove());
    }
  }

  const observer=new MutationObserver(records=>{
    for(const record of records){
      record.addedNodes.forEach(node=>{if(node instanceof HTMLElement) scan(node);});
      if(record.type==='characterData'&&record.target.parentElement) scan(record.target.parentElement);
    }
    scan();
  });

  function start(){
    scan();
    observer.observe(document.body,{childList:true,subtree:true,characterData:true});
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',start,{once:true});
  else start();
})();
