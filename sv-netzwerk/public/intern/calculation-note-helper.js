(()=>{
  const template=`Abgeltungsvereinbarung – optischer Schaden\n\nFür die Positionen [Positionen eintragen] wird auf ausdrücklichen Wunsch und mit Zustimmung des VN anstelle der tatsächlichen Wiederherstellung eine pauschale Abgeltung vereinbart.\n\nDer kalkulierte Wiederherstellungsbetrag dieser Positionen beträgt [Betrag] € brutto. Der vereinbarte Abgeltungssatz beträgt [Prozentsatz] %. Daraus ergibt sich ein Abgeltungsbetrag von [Betrag] €.\n\nMit Auszahlung des vereinbarten Abgeltungsbetrages sind ausschließlich die in dieser Kalkulation ausdrücklich als pauschal abgegolten bezeichneten Schadenpositionen abschließend reguliert. Aus diesen Positionen werden keine weiteren Ansprüche geltend gemacht.`;
  const generatedHeading='Abgeltungsvereinbarung – optischer Schaden';
  const generatedEnding='Mit Auszahlung des vereinbarten Abgeltungsbetrages sind ausschließlich die in dieser Kalkulation ausdrücklich als pauschal abgegolten bezeichneten Schadenpositionen abschließend reguliert. Aus diesen Positionen werden keine weiteren Ansprüche geltend gemacht.';
  const euro=value=>new Intl.NumberFormat('de-DE',{style:'currency',currency:'EUR'}).format(Number(value)||0);
  const num=value=>{const n=Number(String(value??'').replace(',','.'));return Number.isFinite(n)?n:0;};
  const settlementPercent=value=>{const raw=String(value??'').trim(),parsed=Number(raw.replace(',','.'));return raw&&Number.isFinite(parsed)?Math.max(0,Math.min(100,parsed)):30;};
  let lastGeneratedBlock='';

  function grow(field){
    if(!(field instanceof HTMLTextAreaElement))return;
    field.style.height='auto';
    field.style.height=`${Math.max(96,field.scrollHeight+2)}px`;
  }

  function insertTemplate(button){
    const field=document.getElementById(button.dataset.noteTarget||'');
    if(!(field instanceof HTMLTextAreaElement))return;
    if(!field.value.includes(generatedHeading)){
      field.value=[field.value.trim(),template].filter(Boolean).join('\n\n');
      field.dispatchEvent(new Event('input',{bubbles:true}));
    }
    grow(field);
    field.focus();
    field.setSelectionRange(field.value.length,field.value.length);
  }

  function bridge(){return window.__bkiCalcBridge||null;}
  function getLines(){return bridge()?.getLines?.()||[];}
  function setLines(lines){bridge()?.setLines?.(lines);}
  function vatFactor(){return 1+(num(document.getElementById('bk-vat')?.value)||0)/100;}
  function lineGross(line){return num(line.quantity)*num(line.unit_price)*num(line.regional_factor)*vatFactor();}
  function positionList(indices){return indices.map(index=>index+1).join(', ');}

  function createSettlementBlock(lines){
    const percentGroups=new Map();
    const fullIndices=[];
    let totalPayout=0;

    lines.forEach((line,index)=>{
      const mode=line.settlement_mode||'restore';
      const gross=lineGross(line);
      if(mode==='full'){
        fullIndices.push(index);
        totalPayout+=gross;
      }
      if(mode==='percent'){
        const percent=settlementPercent(line.settlement_percent);
        const key=String(percent);
        if(!percentGroups.has(key))percentGroups.set(key,{percent,indices:[],gross:0,payout:0});
        const group=percentGroups.get(key);
        group.indices.push(index);
        group.gross+=gross;
        group.payout+=gross*percent/100;
        totalPayout+=gross*percent/100;
      }
    });

    if(!percentGroups.size&&!fullIndices.length)return '';

    const parts=[generatedHeading];
    [...percentGroups.values()].forEach(group=>{
      parts.push(`Für die Positionen ${positionList(group.indices)} wird auf ausdrücklichen Wunsch und mit Zustimmung des VN anstelle der tatsächlichen Wiederherstellung eine pauschale Abgeltung vereinbart.`);
      parts.push(`Der kalkulierte Wiederherstellungsbetrag dieser Positionen beträgt ${euro(group.gross)} brutto. Der vereinbarte Abgeltungssatz beträgt ${String(group.percent).replace('.',',')} %. Daraus ergibt sich ein Abgeltungsbetrag von ${euro(group.payout)}.`);
    });

    if(fullIndices.length){
      const gross=fullIndices.reduce((sum,index)=>sum+lineGross(lines[index]),0);
      parts.push(`Die Positionen ${positionList(fullIndices)} werden als Abgeltung zu 100 % ausgezahlt. Der Betrag hierfür beträgt ${euro(gross)}.`);
    }

    parts.push(`Gesamtsumme der Auszahlung beträgt: ${euro(totalPayout)}.`);
    parts.push('Mit Auszahlung des vereinbarten Abgeltungsbetrages sind ausschließlich die in dieser Kalkulation ausdrücklich als pauschal abgegolten bezeichneten Schadenpositionen abschließend reguliert. Aus diesen Positionen werden keine weiteren Ansprüche geltend gemacht.');
    return parts.join('\n\n');
  }

  function replaceSettlementBlock(value,block){
    const current=value.trim();
    if(lastGeneratedBlock&&current.includes(lastGeneratedBlock)){
      const next=current.replace(lastGeneratedBlock,block).replace(/\n{3,}/g,'\n\n').trim();
      lastGeneratedBlock=block;
      return next;
    }
    if(!block)return current;
    const headingIndex=current.indexOf(generatedHeading);
    const endingIndex=headingIndex>=0?current.indexOf(generatedEnding,headingIndex):-1;
    if(endingIndex>=0){
      const before=current.slice(0,headingIndex).trim();
      const after=current.slice(endingIndex+generatedEnding.length).trim();
      lastGeneratedBlock=block;
      return [before,block,after].filter(Boolean).join('\n\n');
    }
    lastGeneratedBlock=block;
    return [current,block].filter(Boolean).join('\n\n');
  }

  function updateSettlementNote(){
    const field=document.getElementById('bk-note');
    if(!(field instanceof HTMLTextAreaElement))return;
    const lines=getLines();
    const block=createSettlementBlock(lines);
    field.value=replaceSettlementBlock(field.value,block);
    field.dispatchEvent(new Event('input',{bubbles:true}));
    grow(field);

    const payoutChoice=[...document.querySelectorAll('.bk-note-choice')].find(input=>String(input.value||'').includes('pauschale Abgeltung an den VN'));
    if(payoutChoice)payoutChoice.checked=Boolean(block);
  }

  function changeSettlement(index,mode,percent){
    const lines=getLines();
    if(!lines[index])return;
    lines[index].settlement_mode=mode;
    if(mode==='percent')lines[index].settlement_percent=settlementPercent(percent);
    else delete lines[index].settlement_percent;
    setLines(lines);
    setTimeout(()=>{enhanceSettlementTable();updateSettlementNote();},0);
  }

  function ensureStyle(){
    if(document.getElementById('bk-settlement-style'))return;
    const style=document.createElement('style');
    style.id='bk-settlement-style';
    style.textContent=`
      .bk-settlement-col{min-width:175px}.bk-settlement-control{display:grid;grid-template-columns:minmax(120px,1fr) 62px;gap:5px;align-items:center}.bk-settlement-control select,.bk-settlement-control input{width:100%;box-sizing:border-box;border:1px solid #bdcbd6;border-radius:3px;padding:5px;background:#fff}.bk-settlement-control input[hidden]{display:none}.bk-settlement-control select:has(option[value="percent"]:checked){border-color:#ff970f;background:#fff8ee}.bk-settlement-control small{grid-column:1/-1;color:#687b8c;font-size:.66rem}.bk-table tr.has-settlement{background:#fffaf2}@media(max-width:900px){.bk-settlement-col{min-width:155px}}@media print{.bk-settlement-col{display:none!important}.bk-table tr.has-settlement{background:transparent!important}}
    `;
    document.head.appendChild(style);
  }

  function enhanceSettlementTable(){
    const table=document.querySelector('.bk-table');
    const body=document.getElementById('bk-lines');
    if(!table||!body)return;
    ensureStyle();
    const header=table.querySelector('thead tr');
    if(header&&!header.querySelector('.bk-settlement-col')){
      const th=document.createElement('th');
      th.className='bk-settlement-col';
      th.textContent='Abgeltung';
      header.insertBefore(th,header.lastElementChild);
    }

    const lines=getLines();
    [...body.querySelectorAll('tr')].forEach((row,index)=>{
      if(row.querySelector('.bk-settlement-col'))return;
      const line=lines[index]||{};
      const mode=line.settlement_mode||'restore';
      const percent=settlementPercent(line.settlement_percent);
      const td=document.createElement('td');
      td.className='bk-settlement-col';
      td.innerHTML=`<div class="bk-settlement-control"><select aria-label="Abgeltung Position ${index+1}"><option value="restore">Wiederherstellung</option><option value="full">Auszahlung 100 %</option><option value="percent">Abgeltung %</option></select><input type="number" min="0" max="100" step="1" value="${percent}" aria-label="Abgeltungssatz Position ${index+1}" ${mode==='percent'?'':'hidden'}><small>${mode==='full'?'volle Auszahlung ohne Wiederherstellung':mode==='percent'?'pauschale Abgeltung':'reguläre Wiederherstellung'}</small></div>`;
      const select=td.querySelector('select');
      const input=td.querySelector('input');
      select.value=mode;
      row.classList.toggle('has-settlement',mode!=='restore');
      select.addEventListener('change',()=>changeSettlement(index,select.value,input.value));
      input.addEventListener('change',()=>changeSettlement(index,'percent',input.value));
      row.insertBefore(td,row.lastElementChild);
    });
  }

  document.querySelectorAll('textarea[data-auto-grow]').forEach(field=>{
    grow(field);
    field.addEventListener('input',()=>grow(field));
  });
  document.querySelectorAll('[data-optical-settlement-template]').forEach(button=>{
    button.addEventListener('click',()=>insertTemplate(button));
  });

  const tableObserver=new MutationObserver(()=>enhanceSettlementTable());
  const linesBody=document.getElementById('bk-lines');
  if(linesBody){
    tableObserver.observe(linesBody,{childList:true,subtree:true});
    linesBody.addEventListener('input',event=>{
      if(event.target.matches?.('input[data-k="quantity"], input[data-k="unit_price"], input[data-k="regional_factor"]'))updateSettlementNote();
    });
    linesBody.addEventListener('click',event=>{
      if(event.target.closest?.('[data-remove]'))setTimeout(()=>{enhanceSettlementTable();updateSettlementNote();},0);
    });
  }
  enhanceSettlementTable();

  document.getElementById('bk-vat')?.addEventListener('input',()=>{
    if(getLines().some(line=>(line.settlement_mode||'restore')!=='restore'))updateSettlementNote();
  });

  document.addEventListener('click',event=>{
    if(event.target.closest?.('[data-edit-calc], [data-draft], #bk-new-free-draft')){
      setTimeout(()=>{
        document.querySelectorAll('textarea[data-auto-grow]').forEach(grow);
        enhanceSettlementTable();
      },0);
    }
  },true);

  setTimeout(()=>{document.querySelectorAll('textarea[data-auto-grow]').forEach(grow);enhanceSettlementTable();},250);
  setTimeout(()=>{document.querySelectorAll('textarea[data-auto-grow]').forEach(grow);enhanceSettlementTable();},1000);
  addEventListener('beforeprint',()=>{document.querySelectorAll('textarea[data-auto-grow]').forEach(grow);updateSettlementNote();});
})();
