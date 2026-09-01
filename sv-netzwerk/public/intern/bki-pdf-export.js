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
  const germanNumber=value=>{const normalized=String(value??'').replace(/[^0-9,.-]/g,'').replace(/\./g,'').replace(',','.');const parsed=Number(normalized);return Number.isFinite(parsed)?parsed:0;};

  function kvaReview(){
    const current=window.__bkiKvaReview||{};
    const section=lines().find(line=>line?.type==='section'&&(/\bKVA\b/i.test(String(line.description||''))||line.kva_number));
    const sectionMatch=String(section?.description||'').match(/^(.*?)\s*[·-]\s*KVA\s+(.+)$/i);
    const noteMatch=note().match(/KVA(?:\s+Fa\.)?\s+(.+?)\s+([A-Z]{1,6}\d[\w/-]*)\s+über\s+([\d.]+,\d{2})\s*(?:EUR|€)/i);
    const company=String(current.company||section?.kva_company||sectionMatch?.[1]||noteMatch?.[1]||'').trim();
    const quoteNumber=String(current.quote_number||section?.kva_number||sectionMatch?.[2]||noteMatch?.[2]||'').trim();
    const netTotal=num(current.net_total||section?.kva_net_total);
    const grossTotal=germanNumber(noteMatch?.[3])||(netTotal>0?netTotal*(1+vat()/100):0);
    return {company,quote_number:quoteNumber,gross_total:grossTotal};
  }

  function payload(documentType='settlement'){
    const m=meta();
    const caseNo=String(m.schaden_nr||m.schadenNr||'').trim();
    const vn=String(m.vn_objekt||m.objekt||m.vn||'').trim();
    const location=[m.schaden_strasse||m.strasse,m.schaden_plz||m.plz,m.schaden_ort||m.ort].filter(Boolean).join(', ');
    const kva=kvaReview(),approvedInput=document.getElementById('bk-approved-gross')?.value?.trim()||'',approvedGross=approvedInput!==''?germanNumber(approvedInput):kva.gross_total;
    return {document_type:documentType,case_no:caseNo,vn,location,date:today(),regulator:regulator(),note:note(),vat:vat(),lines:lines(),kva:{...kva,approved_gross:approvedGross}};
  }

  function filenameFrom(response,data){
    const disposition=response.headers.get('content-disposition')||'';
    const match=disposition.match(/filename="?([^";]+)"?/i);
    return match?.[1]||`${data.case_no||'Schaden'}_${data.document_type==='kva_review'?'KVA-Pruefung':'Abgeltungsvereinbarung'}_${data.date}.pdf`;
  }

  async function generatePdf({download=false,type='settlement'}={}){
    const data=payload(type);
    if(!data.lines.length)throw new Error('Keine Kalkulationspositionen vorhanden.');
    if(type==='kva_review'&&(!data.kva.company||!data.kva.quote_number||data.kva.gross_total<=0))throw new Error('Für die KVA-Prüfung fehlen Firma, KVA-Nummer oder KVA-Gesamtbetrag. Bitte den KVA zuerst auslesen.');
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
    wrap.innerHTML='<label class="bk-approved-gross">Freigegebener Betrag brutto<input id="bk-approved-gross" type="text" inputmode="decimal" placeholder="leer = ursprünglicher KVA-Betrag"></label><button id="bk-create-pdf" type="button" class="bk-secondary">PDF Abgeltungsvereinbarung erstellen</button><button id="bk-create-kva-review" type="button" class="bk-secondary">PDF KVA-Prüfung erstellen</button><button id="bk-mail-pdf" type="button" class="bk-primary">Als E-Mail-Anhang übernehmen</button><span id="bk-pdf-state" class="bk-pdf-state"></span>';
    host.insertAdjacentElement('beforebegin',wrap);
    const style=document.createElement('style');style.textContent='.bk-pdf-actions{display:flex;flex-wrap:wrap;align-items:end;gap:10px;margin-top:18px;padding-top:14px;border-top:1px solid #dce5ec}.bk-approved-gross{display:grid;gap:4px;min-width:235px;font-size:.78rem;font-weight:750;color:#536a7d}.bk-approved-gross input{box-sizing:border-box;width:100%;border:1px solid #bdcbd6;border-radius:9px;padding:10px;color:#17324a;background:#fff}.bk-pdf-state{font-size:.82rem;color:#536a7d}.bk-pdf-state[data-state="ok"]{color:#236e50}.bk-pdf-state[data-state="bad"]{color:#a82929}@media print{.bk-pdf-actions{display:none!important}}';document.head.appendChild(style);
    document.getElementById('bk-create-pdf').onclick=async()=>{try{updateStatus('PDF wird erstellt …');await generatePdf({download:true});}catch(error){updateStatus(error.message||String(error),'bad');}};
    document.getElementById('bk-create-kva-review').onclick=async()=>{try{updateStatus('KVA-Prüfung wird erstellt …');await generatePdf({download:true,type:'kva_review'});}catch(error){updateStatus(error.message||String(error),'bad');}};
    document.getElementById('bk-mail-pdf').onclick=async()=>{try{updateStatus('PDF wird vorbereitet …');await handoffToMail();}catch(error){updateStatus(error.message||String(error),'bad');}};
  }

  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',install);else install();
  setTimeout(install,500);
})();
