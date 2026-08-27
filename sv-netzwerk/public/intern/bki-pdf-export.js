(()=>{
  const PDF_LIB='https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.2/jspdf.umd.min.js';
  const MAIL_KEY='svnet-bki-mail-attachment';
  let pdfPromise=null;
  let lastPdf=null;

  const money=value=>new Intl.NumberFormat('de-DE',{style:'currency',currency:'EUR'}).format(Number(value)||0);
  const num=value=>{const n=Number(String(value??'').replace(',','.'));return Number.isFinite(n)?n:0;};
  const escFile=value=>String(value||'').replace(/[\\/:*?"<>|]+/g,'-').replace(/\s+/g,' ').trim();
  const today=()=>document.getElementById('bk-date')?.value||new Date().toISOString().slice(0,10);
  const bridge=()=>window.__bkiCalcBridge||null;
  const lines=()=>bridge()?.getLines?.()||[];
  const meta=()=>bridge()?.getMeta?.()||{};
  const vat=()=>num(document.getElementById('bk-vat')?.value)||0;
  const regulator=()=>document.getElementById('bk-regulator')?.value?.trim()||'Christian Wächter';
  const note=()=>document.getElementById('bk-note')?.value?.trim()||'';

  function settlementAmount(line){
    const net=num(line.quantity)*num(line.unit_price)*num(line.regional_factor);
    const gross=net*(1+vat()/100);
    const mode=line.settlement_mode||'restore';
    const percent=Math.max(0,Math.min(100,num(line.settlement_percent)||30));
    return {net,gross,mode,percent,amount:mode==='percent'?net*percent/100:gross};
  }

  function loadPdf(){
    if(window.jspdf?.jsPDF)return Promise.resolve(window.jspdf.jsPDF);
    if(pdfPromise)return pdfPromise;
    pdfPromise=new Promise((resolve,reject)=>{
      const script=document.createElement('script');
      script.src=PDF_LIB;
      script.async=true;
      script.onload=()=>window.jspdf?.jsPDF?resolve(window.jspdf.jsPDF):reject(new Error('PDF-Bibliothek konnte nicht geladen werden.'));
      script.onerror=()=>reject(new Error('PDF-Bibliothek konnte nicht geladen werden.'));
      document.head.appendChild(script);
    });
    return pdfPromise;
  }

  function addPageIfNeeded(doc,y,need=20){
    if(y+need<=278)return y;
    doc.addPage();
    return 18;
  }

  function drawHeader(doc,caseNo){
    doc.setFont('helvetica','bold');
    doc.setFontSize(17);
    doc.setTextColor(23,50,74);
    doc.text('SV-NETZWERK',18,18);
    doc.setFontSize(8.5);
    doc.setFont('helvetica','normal');
    doc.text('BAU · SCHADEN · REGULIERUNG',18,24);
    doc.setDrawColor(255,151,15);
    doc.setLineWidth(1.2);
    doc.line(18,28,192,28);
    doc.setTextColor(23,50,74);
    doc.setFont('helvetica','bold');
    doc.setFontSize(15);
    doc.text('Abgeltungsvereinbarung / Schadenkalkulation',18,39);
    if(caseNo){doc.setFontSize(9);doc.setFont('helvetica','normal');doc.text(`Schaden-Nr.: ${caseNo}`,18,46);}
    return 54;
  }

  function addWrapped(doc,text,x,y,width,options={}){
    const size=options.size||9;
    const bold=options.bold||false;
    doc.setFont('helvetica',bold?'bold':'normal');
    doc.setFontSize(size);
    doc.setTextColor(28,45,60);
    const parts=doc.splitTextToSize(String(text||''),width);
    doc.text(parts,x,y);
    return y+parts.length*(size*0.43+1.4);
  }

  async function generatePdf({download=false}={}){
    const rows=lines();
    if(!rows.length)throw new Error('Keine Kalkulationspositionen vorhanden.');
    const jsPDF=await loadPdf();
    const doc=new jsPDF({unit:'mm',format:'a4',compress:true});
    const m=meta();
    const caseNo=String(m.schaden_nr||m.schadenNr||'').trim();
    const objectName=String(m.vn_objekt||m.objekt||m.vn||'').trim();
    const location=[m.schaden_strasse||m.strasse,m.schaden_plz||m.plz,m.schaden_ort||m.ort].filter(Boolean).join(', ');
    let y=drawHeader(doc,caseNo);

    doc.setFillColor(245,248,250);
    doc.roundedRect(18,y-5,174,24,2,2,'F');
    y=addWrapped(doc,`VN / Objekt: ${objectName||'—'}`,22,y,164,{size:9,bold:true});
    y=addWrapped(doc,`Schadenort: ${location||'—'}`,22,y+1,164,{size:8.5});
    y=addWrapped(doc,`Datum: ${today()}`,22,y+1,164,{size:8.5});
    y+=8;

    const widths=[12,74,18,16,25,29];
    const headers=['Pos.','Leistung','Menge','Einheit','Betrag','Regelung'];
    const xs=[18]; for(let i=0;i<widths.length-1;i++)xs.push(xs[i]+widths[i]);
    doc.setFillColor(23,50,74);doc.rect(18,y,174,8,'F');
    doc.setTextColor(255,255,255);doc.setFont('helvetica','bold');doc.setFontSize(7.6);
    headers.forEach((h,i)=>doc.text(h,xs[i]+1.4,y+5.3));
    y+=8;

    const totalGross=rows.reduce((sum,line)=>sum+settlementAmount(line).gross,0);
    const payout=rows.filter(line=>(line.settlement_mode||'restore')!=='restore').reduce((sum,line)=>sum+settlementAmount(line).amount,0);

    rows.forEach((line,index)=>{
      const s=settlementAmount(line);
      const desc=doc.splitTextToSize(String(line.description||'Freie Position'),widths[1]-3);
      const modeText=s.mode==='percent'?`Abgeltung ${String(s.percent).replace('.',',')} %`:s.mode==='full'?'Auszahlung 100 %':'Wiederherstellung';
      const modeLines=doc.splitTextToSize(modeText,widths[5]-3);
      const rowHeight=Math.max(8,Math.max(desc.length,modeLines.length)*4+3);
      y=addPageIfNeeded(doc,y,rowHeight+3);
      doc.setDrawColor(220,229,236);doc.rect(18,y,174,rowHeight);
      doc.setTextColor(28,45,60);doc.setFont('helvetica','normal');doc.setFontSize(7.4);
      doc.text(String(line.position_code||index+1),xs[0]+1.4,y+5);
      doc.text(desc,xs[1]+1.4,y+5);
      doc.text(String(line.quantity??''),xs[2]+1.4,y+5);
      doc.text(String(line.unit||''),xs[3]+1.4,y+5);
      doc.text(money(s.mode==='percent'?s.amount:s.gross),xs[4]+1.4,y+5);
      doc.text(modeLines,xs[5]+1.4,y+5);
      y+=rowHeight;
    });

    y=addPageIfNeeded(doc,y,34)+7;
    doc.setFillColor(237,245,241);doc.roundedRect(18,y,174,22,2,2,'F');
    doc.setTextColor(23,50,74);doc.setFont('helvetica','bold');doc.setFontSize(9);
    doc.text('Kalkulierter Wiederherstellungsbetrag brutto',22,y+7);
    doc.text(money(totalGross),188,y+7,{align:'right'});
    doc.text('Tatsächliche Gesamtauszahlung / Abgeltung',22,y+15);
    doc.text(money(payout),188,y+15,{align:'right'});
    y+=31;

    const agreement=note();
    if(agreement){
      y=addPageIfNeeded(doc,y,25);
      doc.setFont('helvetica','bold');doc.setFontSize(10);doc.setTextColor(23,50,74);doc.text('Vereinbarung',18,y);y+=7;
      const paragraphs=agreement.split(/\n\s*\n/).map(p=>p.trim()).filter(Boolean);
      paragraphs.forEach((p,idx)=>{
        const split=doc.splitTextToSize(p,174);
        const need=split.length*4.5+6;
        y=addPageIfNeeded(doc,y,need);
        doc.setFont('helvetica',idx===0?'bold':'normal');doc.setFontSize(idx===0?9.5:8.6);doc.setTextColor(28,45,60);
        doc.text(split,18,y);y+=split.length*4.3+5;
      });
    }

    y=addPageIfNeeded(doc,y,45)+7;
    doc.setDrawColor(70,84,102);
    doc.line(18,y+20,84,y+20);doc.line(112,y+20,192,y+20);
    doc.setFont('helvetica','bold');doc.setFontSize(8);doc.setTextColor(23,50,74);
    doc.text('VN oder Bevollmächtigter',18,y+25);
    doc.text(regulator(),112,y+15);
    doc.text('Regulierer',112,y+25);

    const filename=`${escFile(caseNo||'Schaden')}_Abgeltungsvereinbarung_${today()}.pdf`;
    const blob=doc.output('blob');
    lastPdf={blob,filename,case_no:caseNo,created_at:new Date().toISOString()};
    if(download)doc.save(filename);
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
