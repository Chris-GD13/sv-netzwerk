(()=>{
  const MAIL_KEY='svnet-bki-mail-attachment';
  const PDF_API='/intern/api/bki-settlement-pdf.php';
  let lastPdf=null;
  const num=value=>{const n=Number(String(value??'').replace(',','.'));return Number.isFinite(n)?n:0;};
  const today=()=>document.getElementById('bk-date')?.value||new Date().toISOString().slice(0,10);
  const bridge=()=>window.__bkiCalcBridge||null;
  const lines=()=>bridge()?.getLines?.()||[];
  const meta=()=>bridge()?.getMeta?.()||{};
  const vat=()=>num(document.getElementById('bk-vat')?.value)||0;
  const regulator=()=>document.getElementById('bk-regulator')?.value?.trim()||'Christian Wächter';
  const note=()=>document.getElementById('bk-note')?.value?.trim()||'';

  function payload(){
    const m=meta();
    const caseNo=String(m.schaden_nr||m.schadenNr||'').trim();
    const vn=String(m.vn_objekt||m.objekt||m.vn||'').trim();
    const location=[m.schaden_strasse||m.strasse,m.schaden_plz||m.plz,m.schaden_ort||m.ort].filter(Boolean).join(', ');
    return {case_no:caseNo,vn,location,date:today(),regulator:regulator(),note:note(),vat:vat(),lines:lines()};
  }

  function filenameFrom(response,data){
    const disposition=response.headers.get('content-disposition')||'';
    const match=disposition.match(/filename="?([^";]+)"?/i);
    return match?.[1]||`${data.case_no||'Schaden'}_Abgeltungsvereinbarung_${data.date}.pdf`;
  }

  async function generatePdf({download=false}={}){
    const data=payload();
    if(!data.lines.length)throw new Error('Keine Kalkulationspositionen vorhanden.');
    const response=await fetch(PDF_API,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/pdf'},body:JSON.stringify(data),credentials:'same-origin'});
    if(!response.ok){const msg=(await response.text()).trim();throw new Error(msg||`PDF konnte nicht erstellt werden (${response.status}).`);}
    const blob=await response.blob();
    if(blob.type&&!blob.type.includes('pdf'))throw new Error('Der Server hat kein PDF zurückgegeben.');
    const filename=filenameFrom(response,data);
    lastPdf={blob,filename,case_no:data.case_no,created_at:new Date().toISOString()};
    if(download){const url=URL.createObjectURL(blob);const a=document.createElement('a');a.href=url;a.download=filename;document.body.appendChild(a);a.click();a.remove();setTimeout(()=>URL.revokeObjectURL(url),2000);}
    updateStatus(`PDF erstellt: ${filename}`,'ok');
    return lastPdf;
  }

  function blobToDataUrl(blob){return new Promise((resolve,reject)=>{const reader=new FileReader();reader.onload=()=>resolve(String(reader.result||''));reader.onerror=()=>reject(reader.error||new Error('PDF konnte nicht vorbereitet werden.'));reader.readAsDataURL(blob);});}

  async function handoffToMail(){
    const pdf=lastPdf||await generatePdf({download:false});
    const dataUrl=await blobToDataUrl(pdf.blob);
    const payload={name:pdf.filename,type:'application/pdf',data_url:dataUrl,case_no:pdf.case_no,created_at:pdf.created_at};
    try{sessionStorage.setItem(MAIL_KEY,JSON.stringify(payload));}
    catch{throw new Error('PDF ist zu groß für die direkte E-Mail-Übernahme. Bitte PDF herunterladen und manuell anhängen.');}
    updateStatus('PDF wird als Anhang an Punkt 5 übergeben.','ok');
    location.href='/intern/versicherungsfaelle/#vf-mail';
  }

  function updateStatus(text,type=''){
    const state=document.getElementById('bk-pdf-state');
    if(!state)return;
    state.textContent=text;state.dataset.state=type;
  }

  function install(){
    if(document.getElementById('bk-create-pdf'))return;
    const host=document.querySelector('.bk-footer-actions')||document.querySelector('.bk-notes');
    if(!host)return;
    const wrap=document.createElement('div');
    wrap.className='bk-pdf-actions';
    wrap.innerHTML='<button id="bk-create-pdf" type="button" class="bk-secondary">PDF Abgeltungsvereinbarung erstellen</button><button id="bk-mail-pdf" type="button" class="bk-primary">Als E-Mail-Anhang übernehmen</button><span id="bk-pdf-state" class="bk-pdf-state"></span>';
    host.insertAdjacentElement('beforebegin',wrap);
    const style=document.createElement('style');style.textContent='.bk-pdf-actions{display:flex;flex-wrap:wrap;align-items:center;gap:10px;margin-top:18px;padding-top:14px;border-top:1px solid #dce5ec}.bk-pdf-state{font-size:.82rem;color:#536a7d}.bk-pdf-state[data-state="ok"]{color:#236e50}.bk-pdf-state[data-state="bad"]{color:#a82929}@media print{.bk-pdf-actions{display:none!important}}';document.head.appendChild(style);
    document.getElementById('bk-create-pdf').onclick=async()=>{try{updateStatus('PDF wird erstellt …');await generatePdf({download:true});}catch(error){updateStatus(error.message||String(error),'bad');}};
    document.getElementById('bk-mail-pdf').onclick=async()=>{try{updateStatus('PDF wird vorbereitet …');await handoffToMail();}catch(error){updateStatus(error.message||String(error),'bad');}};
  }

  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',install);else install();
  setTimeout(install,500);
})();
