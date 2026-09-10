(()=>{
  const $=id=>document.getElementById(id);
  const money=value=>value===null||value===undefined?'—':new Intl.NumberFormat('de-DE',{style:'currency',currency:'EUR'}).format(Number(value)||0);
  const number=value=>{const parsed=Number(String(value??'').replace(',','.'));return Number.isFinite(parsed)?parsed:0};
  const api=async(url,options={})=>{const response=await fetch(url,{credentials:'same-origin',...options});const data=await response.json().catch(()=>({}));if(!response.ok)throw new Error(data.error||`HTTP ${response.status}`);return data};
  const activeCase=()=>{for(const storage of[sessionStorage,localStorage])try{const value=JSON.parse(storage.getItem('svnet-case')||'null');if(value?.folder_id)return value}catch{}return null};
  const selectedRows=()=>rows.filter(row=>row.selected);
  let sourceFile=null,analysis=null,rows=[];

  function setState(message,error=false){const node=$('bk-kva-calc-state');if(!node)return;node.textContent=message;node.classList.toggle('error',error)}
  function totals(){
    const chosen=selectedRows();
    const offered=chosen.reduce((sum,row)=>sum+number(row.line_total??number(row.quantity)*number(row.unit_price)),0);
    const own=chosen.reduce((sum,row)=>sum+number(row.quantity)*number(row.own_price),0);
    $('bk-kva-calc-net').textContent=money(analysis?.net_total??offered);
    $('bk-kva-own-net').textContent=money(own);
    $('bk-kva-difference').textContent=money(own-offered);
  }
  function render(){
    const body=$('bk-kva-calc-lines');body.innerHTML='';
    rows.forEach((row,index)=>{
      const offered=number(row.line_total??number(row.quantity)*number(row.unit_price));
      const own=row.own_price===null?null:number(row.quantity)*number(row.own_price);
      const tr=document.createElement('tr');
      const use=document.createElement('input');use.type='checkbox';use.checked=row.selected;use.dataset.kvaUse=String(index);use.setAttribute('aria-label',`Position ${row.position_no} übernehmen`);use.onchange=()=>{row.selected=use.checked;totals()};
      const useCell=document.createElement('td');useCell.appendChild(use);tr.appendChild(useCell);
      const description=document.createElement('td');const strong=document.createElement('strong');strong.textContent=`${row.position_no} · ${row.description}`;description.appendChild(strong);const detail=document.createElement('small');detail.textContent=row.optional?'Optional-/Bedarfsposition – nicht vorausgewählt':`Erkennung ${Math.round(number(row.confidence)*100)} %`;description.appendChild(detail);tr.appendChild(description);
      const quantity=document.createElement('td');quantity.textContent=`${row.quantity} ${row.unit}`;tr.appendChild(quantity);
      const offeredCell=document.createElement('td');offeredCell.textContent=money(row.unit_price);tr.appendChild(offeredCell);
      const ownCell=document.createElement('td');const input=document.createElement('input');input.type='number';input.step='0.01';input.min='0';input.value=row.own_price===null?'':String(row.own_price);input.placeholder='ermitteln';input.dataset.kvaOwn=String(index);input.oninput=()=>{row.own_price=input.value===''?null:number(input.value);row.position_code='';row.own_description=row.description;renderDifference(tr,row,offered);totals()};ownCell.appendChild(input);if(row.position_code){const code=document.createElement('small');code.textContent=`BKI ${row.position_code}`;ownCell.appendChild(code)}tr.appendChild(ownCell);
      const difference=document.createElement('td');difference.className='kva-difference';difference.textContent=own===null?'noch nicht ermittelt':money(own-offered);if(own===null)difference.classList.add('bk-kva-price-missing');tr.appendChild(difference);
      body.appendChild(tr);
    });
    totals();
  }
  function renderDifference(tr,row,offered){const cell=tr.querySelector('.kva-difference'),own=row.own_price===null?null:number(row.quantity)*number(row.own_price);cell.textContent=own===null?'noch nicht ermittelt':money(own-offered);cell.classList.toggle('bk-kva-price-missing',own===null)}
  async function loadFiles(){
    const current=activeCase(),select=$('bk-kva-calc-select'),file=$('bk-kva-calc-file'),read=$('bk-kva-calc-read');if(!select)return;
    if(!current){select.disabled=true;file.disabled=true;read.disabled=true;return}
    $('bk-kva-case-hint').textContent=`Fall ${current.meta?.schaden_nr||'geöffnet'}`;select.disabled=false;file.disabled=false;select.innerHTML='<option value="">KVA-Dateien werden geladen …</option>';
    try{const data=await api(`/intern/api/kva-release.php?action=files&folder_id=${encodeURIComponent(current.folder_id)}`);select.innerHTML='<option value="">KVA aus dem Fall auswählen</option>';for(const item of data.files||[]){const option=document.createElement('option');option.value=item.id;option.textContent=item.name;select.appendChild(option)}read.disabled=!(sourceFile||(data.files||[]).length)}catch(error){select.innerHTML='<option value="">KVA-Dateien konnten nicht geladen werden</option>';read.disabled=!sourceFile;setState(error.message,true)}
  }
  async function analyze(){
    const current=activeCase();if(!current)return setState('Bitte zuerst einen Schadenfall öffnen.',true);
    const select=$('bk-kva-calc-select');if(!sourceFile&&!select.value)return setState('Bitte einen KVA auswählen oder fotografieren.',true);
    const form=new FormData();form.append('folder_id',current.folder_id);if(sourceFile)form.append('file',sourceFile,sourceFile.name);else form.append('file_id',select.value);
    const button=$('bk-kva-calc-read');button.disabled=true;setState('KVA wird positionsgenau ausgelesen …');$('bk-kva-calc-review').hidden=true;
    try{const data=await api('/intern/api/kva-release.php?action=calculation_analyze',{method:'POST',body:form});analysis=data.analysis;rows=(analysis.positions||[]).map(row=>({...row,selected:!row.optional,own_price:null,own_description:row.description,position_code:''}));$('bk-kva-calc-name').textContent=[analysis.company,analysis.quote_number&&`KVA ${analysis.quote_number}`].filter(Boolean).join(' · ')||data.source;render();$('bk-kva-calc-review').hidden=false;setState(`${rows.length} Position${rows.length===1?'':'en'} erkannt. Mengen und Ansätze bitte prüfen.${analysis.warnings?.length?' '+analysis.warnings.join(' '):''}`)}catch(error){setState(error.message||String(error),true)}finally{button.disabled=false}
  }
  async function mapPrices(){
    const button=$('bk-kva-map-prices');button.disabled=true;let mapped=0;setState('Eigene BKI-Ansätze werden ermittelt …');
    try{for(let index=0;index<rows.length;index++){const row=rows[index];if(!row.selected)continue;try{const data=await api('/intern/api/bki-calculator.php?action=search',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({query:row.description,quantity:row.quantity,unit:row.unit,location:$('bk-location')?.value||'',case_meta:activeCase()?.meta||{}})});const match=(data.positions||[])[0];if(match){row.own_price=number(match.price_mid);row.position_code=match.position_code||'';row.own_description=match.description||row.description;row.regional_factor=number(match.regional_factor)||1;mapped++}}catch{}render();setState(`${mapped} von ${selectedRows().length} ausgewählten Positionen mit eigenen Preisen belegt.`)}}finally{button.disabled=false}
  }
  function importLines(){
    const bridge=window.__bkiCalcBridge;if(!bridge)return setState('Die Kalkulation ist noch nicht bereit. Bitte Seite neu laden.',true);
    const chosen=selectedRows().filter(row=>row.own_price!==null);if(!chosen.length)return setState('Bitte zuerst mindestens einen eigenen Preis ermitteln oder eintragen.',true);
    chosen.forEach(row=>bridge.addLine({position_code:row.position_code||`KVA ${row.position_no}`,description:row.own_description||row.description,quantity:row.quantity,recommended_quantity:row.quantity,unit:row.unit,unit_price:row.own_price,regional_factor:row.regional_factor||1,source_name:`KVA ${analysis.quote_number||''} · ${analysis.company||''}`.trim()}));setState(`${chosen.length} KVA-Position${chosen.length===1?'':'en'} in die Kalkulation übernommen.`);document.querySelector('.bk-protocol')?.scrollIntoView({behavior:'smooth',block:'start'})
  }
  function prepareSettlement(){
    const chosen=selectedRows().filter(row=>row.own_price!==null);if(!chosen.length)return setState('Bitte zuerst eigene Preise ermitteln und Positionen auswählen.',true);
    const net=chosen.reduce((sum,row)=>sum+number(row.quantity)*number(row.own_price),0),vat=number($('bk-vat')?.value||19),gross=net*(1+vat/100),labels=chosen.map(row=>`${row.position_no} ${row.description}`).join('; '),note=$('bk-note');
    const choice=[...document.querySelectorAll('.bk-note-choice')].find(input=>String(input.value||'').includes('pauschale Abgeltung'));if(choice)choice.checked=true;
    if(note){const text=`Abgeltungsvereinbarung auf Grundlage der Nachkalkulation des KVA ${analysis.quote_number||''}\n\nFür die Positionen ${labels} beträgt der mit eigenen Preisen nachkalkulierte Wiederherstellungsbetrag ${money(gross)} brutto. Der konkrete Abgeltungsumfang und der vereinbarte Auszahlungsbetrag sind vor Unterzeichnung abschließend zu prüfen.\n\nMit der vereinbarten Zahlung sind ausschließlich die ausdrücklich als abgegolten bezeichneten Schadenpositionen abschließend reguliert.`;note.value=note.value.trim()?`${note.value.trim()}\n\n${text}`:text;note.dispatchEvent(new Event('input',{bubbles:true}))}
    setState(`Abgeltung über ${money(gross)} brutto vorbereitet. Betrag und Umfang vor der Unterschrift prüfen.`);document.querySelector('.bk-notes')?.scrollIntoView({behavior:'smooth',block:'center'})
  }
  document.addEventListener('DOMContentLoaded',()=>{
    if(!$('bk-kva-calc-select'))return;
    $('bk-kva-calc-select').onchange=()=>{sourceFile=null;$('bk-kva-calc-file').value='';$('bk-kva-calc-read').disabled=!$('bk-kva-calc-select').value};
    $('bk-kva-calc-file').onchange=event=>{sourceFile=event.target.files?.[0]||null;if(sourceFile){$('bk-kva-calc-select').value='';$('bk-kva-calc-read').disabled=false;setState(`${sourceFile.name} ausgewählt.`)}};
    $('bk-kva-calc-read').onclick=analyze;$('bk-kva-map-prices').onclick=mapPrices;$('bk-kva-import-lines').onclick=importLines;$('bk-kva-settlement').onclick=prepareSettlement;loadFiles();
  });
})();
