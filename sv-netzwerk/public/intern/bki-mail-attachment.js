(()=>{
  const KEY='svnet-bki-mail-attachment';
  function dataUrlToFile(payload){
    const parts=String(payload.data_url||'').split(',');
    if(parts.length<2)return null;
    const bytes=atob(parts[1]),buffer=new Uint8Array(bytes.length);
    for(let i=0;i<bytes.length;i++)buffer[i]=bytes.charCodeAt(i);
    return new File([buffer],payload.name||'Abgeltungsvereinbarung.pdf',{type:payload.type||'application/pdf'});
  }
  function install(){
    if(location.hash!=='#vf-mail'&&!sessionStorage.getItem(KEY))return;
    const input=document.getElementById('vf-mail-files');
    const section=document.querySelector('.vf-mail');
    if(!input||!section)return;
    let payload;
    try{payload=JSON.parse(sessionStorage.getItem(KEY)||'null');}catch{return;}
    if(!payload?.data_url)return;
    const file=dataUrlToFile(payload);if(!file)return;
    const transfer=new DataTransfer();
    [...input.files].forEach(existing=>transfer.items.add(existing));
    transfer.items.add(file);input.files=transfer.files;
    let info=document.getElementById('vf-auto-attachment');
    if(!info){info=document.createElement('div');info.id='vf-auto-attachment';info.className='vf-job ok';input.closest('label')?.insertAdjacentElement('afterend',info);}
    info.innerHTML=`<strong>Abgeltungsvereinbarung übernommen.</strong><br>${payload.name}`;
    sessionStorage.removeItem(KEY);
    section.scrollIntoView({behavior:'smooth',block:'start'});
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',()=>setTimeout(install,80));else setTimeout(install,80);
})();
