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
    if(field.id==='bk-note'&&getLines().length){
      updateSettlementNote();
      field.focus();
      field.setSelectionRange(field.value.length,field.value.length);
      return;
    }
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
  function lineNet(line){return num(line.quantity)*num(line.unit_price)*num(line.regional_factor);}
  function lineGross(line){return lineNet(line)*vatFactor();}
  function lineLabel(line){return line.description||'Freie Position';}

  function settlementModel(lines){
    let position=0;
    const items=[];
    lines.forEach((line,index)=>{
      if(line.type==='section')return;
      const mode=line.settlement_mode||'restore';
      const percent=settlementPercent(line.settlement_percent);
      const net=lineNet(line);
      const gross=lineGross(line);
      const amount=mode==='percent'?net*percent/100:gross;
      const method=mode==='percent'?`Abgeltung ${String(percent).replace('.',',')} % · netto`:mode==='full'?'Auszahlung 100 % · brutto':'Wiederherstellung · brutto';
      items.push({line,index,position:String(++position),mode,percent,net,gross,amount,method,label:lineLabel(line)});
    });
    return{
      items,
      total:items.reduce((sum,item)=>sum+item.amount,0),
      payoutTotal:items.filter(item=>item.mode!=='restore').reduce((sum,item)=>sum+item.amount,0),
      hasSettlement:items.some(item=>item.mode!=='restore')
    };
  }

  function createSettlementBlock(lines){
    const model=settlementModel(lines);
    if(!model.hasSettlement)return '';
    const parts=[generatedHeading];
    const groups=new Map();
    model.items.filter(item=>item.mode==='percent').forEach(item=>{
      const key=String(item.percent);
      if(!groups.has(key))groups.set(key,{percent:item.percent,items:[],gross:0,payout:0});
      const group=groups.get(key);
      group.items.push(item);
      group.gross+=item.gross;
      group.payout+=item.amount;
    });
    [...groups.values()].forEach(group=>{
      const positions=group.items.map(item=>item.position).join(', ');
      parts.push(`Für die Positionen ${positions} wird auf ausdrücklichen Wunsch und mit Zustimmung des VN anstelle der tatsächlichen Wiederherstellung eine pauschale Abgeltung vereinbart.`);
      parts.push(`Der kalkulierte Wiederherstellungsbetrag dieser Positionen beträgt ${euro(group.gross)} brutto. Der vereinbarte Abgeltungssatz beträgt ${String(group.percent).replace('.',',')} % auf den Nettobetrag. Daraus ergibt sich ein Abgeltungsbetrag von ${euro(group.payout)}.`);
    });
    const fullItems=model.items.filter(item=>item.mode==='full');
    if(fullItems.length){
      parts.push(`Die Positionen ${fullItems.map(item=>item.position).join(', ')} werden zu 100 % brutto ausgezahlt. Der Betrag hierfür beträgt ${euro(fullItems.reduce((sum,item)=>sum+item.amount,0))}.`);
    }
    parts.push(`Die Gesamtsumme der Auszahlung beträgt ${euro(model.payoutTotal)}.`);
    parts.push(generatedEnding);
    return parts.join('\n\n');
  }

  function ensureSettlementSummary(){
    ensureStyle();
    const calculationSummary=document.querySelector('.bk-summary');
    if(calculationSummary&&!document.getElementById('bk-settlement-total-card')){
      const card=document.createElement('div');
      card.id='bk-settlement-total-card';
      card.className='bk-settlement-total-card';
      card.innerHTML='<span>Schlusssumme</span><strong id="bk-settlement-total">0,00 €</strong><small>gemäß Auswahl</small>';
      calculationSummary.appendChild(card);
      calculationSummary.classList.add('has-settlement-total');
    }
    let summary=document.getElementById('bk-settlement-summary');
    if(!summary){
      summary=document.createElement('section');
      summary.id='bk-settlement-summary';
      summary.className='bk-settlement-summary';
      summary.innerHTML='<div class="bk-settlement-summary-head"><strong>Schlusserklärung zur Abgeltung</strong><span>Leistung links · berechneter Betrag rechts</span></div><div class="bk-settlement-summary-rows"></div><div class="bk-settlement-summary-total"><strong>Schlusssumme</strong><strong></strong></div>';
      document.querySelector('.bk-notes')?.appendChild(summary);
    }
    return summary;
  }

  function updateSettlementSummary(lines){
    const summary=ensureSettlementSummary();
    if(!summary)return;
    const model=settlementModel(lines);
    const payoutChoice=[...document.querySelectorAll('.bk-note-choice')].find(input=>String(input.value||'').includes('pauschale Abgeltung an den VN'));
    if(payoutChoice)payoutChoice.checked=model.hasSettlement;
    summary.hidden=!model.hasSettlement;
    document.querySelector('.bk-summary')?.classList.toggle('has-settlement-total',model.hasSettlement);
    const totalCard=document.getElementById('bk-settlement-total-card');
    if(totalCard)totalCard.hidden=!model.hasSettlement;
    const totalField=document.getElementById('bk-settlement-total');
    if(totalField)totalField.textContent=euro(model.total);
    const rows=summary.querySelector('.bk-settlement-summary-rows');
    rows.replaceChildren();
    model.items.forEach(item=>{
      const row=document.createElement('div');
      row.className='bk-settlement-summary-row';
      const text=document.createElement('div');
      const label=document.createElement('strong');
      label.textContent=item.label;
      const method=document.createElement('small');
      method.textContent=`Pos. ${item.position} · ${item.method}`;
      text.append(label,method);
      const amount=document.createElement('strong');
      amount.textContent=euro(item.amount);
      row.append(text,amount);
      rows.appendChild(row);
    });
    summary.querySelector('.bk-settlement-summary-total strong:last-child').textContent=euro(model.total);
  }

  function replaceSettlementBlock(value,block){
    if(lastGeneratedBlock&&value.includes(lastGeneratedBlock)){
      const next=value.replace(lastGeneratedBlock,block);
      lastGeneratedBlock=block;
      return next;
    }
    const headingIndex=value.indexOf(generatedHeading);
    const endingIndex=headingIndex>=0?value.indexOf(generatedEnding,headingIndex):-1;
    if(endingIndex>=0){
      const before=value.slice(0,headingIndex).trimEnd();
      const after=value.slice(endingIndex+generatedEnding.length).trimStart();
      lastGeneratedBlock=block;
      return [before,block,after].filter(Boolean).join('\n\n');
    }
    if(!block)return value;
    lastGeneratedBlock=block;
    return [value.trimEnd(),block].filter(Boolean).join('\n\n');
  }

  function updateSettlementNote(){
    const field=document.getElementById('bk-note');
    if(!(field instanceof HTMLTextAreaElement))return;
    const lines=getLines();
    const block=createSettlementBlock(lines);
    const storedGenerated=field.value.includes(generatedHeading)&&field.value.includes(generatedEnding)&&!field.value.includes('[Positionen eintragen]');
    if(block||lastGeneratedBlock||storedGenerated){
      const next=replaceSettlementBlock(field.value,block);
      if(next!==field.value){
        field.value=next;
        field.dispatchEvent(new Event('input',{bubbles:true}));
      }
    }
    grow(field);

    updateSettlementSummary(lines);
  }

  function changeSettlement(index,mode,percent){
    const lines=getLines();
    if(!lines[index]||lines[index].type==='section')return;
    lines[index].settlement_mode=mode;
    if(mode==='percent')lines[index].settlement_percent=settlementPercent(percent);
    else delete lines[index].settlement_percent;
    setLines(lines);
    setTimeout(()=>enhanceSettlementTable(),0);
  }

  function ensureStyle(){
    if(document.getElementById('bk-settlement-style'))return;
    const style=document.createElement('style');
    style.id='bk-settlement-style';
    style.textContent=`
      .bk-settlement-col{min-width:175px}.bk-settlement-control{display:grid;grid-template-columns:minmax(120px,1fr) 62px;gap:5px;align-items:center}.bk-settlement-control select,.bk-settlement-control input{width:100%;box-sizing:border-box;border:1px solid #bdcbd6;border-radius:3px;padding:5px;background:#fff}.bk-settlement-control input[hidden]{display:none}.bk-settlement-control select:has(option[value="percent"]:checked){border-color:#ff970f;background:#fff8ee}.bk-settlement-control small{grid-column:1/-1;color:#687b8c;font-size:.66rem}.bk-table tr.has-settlement{background:#fffaf2}.bk-summary.has-settlement-total{grid-template-columns:130px repeat(4,1fr)}.bk-settlement-total-card{background:#fff3df!important;border:1px solid #f1c98f}.bk-settlement-total-card small{color:#687b8c}.bk-settlement-summary{margin-top:16px;border:1px solid #dce5ec;border-radius:10px;overflow:hidden}.bk-settlement-summary-head{display:flex;justify-content:space-between;gap:12px;padding:12px;background:#eef4f7}.bk-settlement-summary-head span{color:#687b8c;font-size:.8rem}.bk-settlement-summary-row,.bk-settlement-summary-total{display:grid;grid-template-columns:minmax(0,1fr) max-content;gap:18px;align-items:center;padding:10px 12px;border-top:1px solid #e4ebf0}.bk-settlement-summary-row>div{display:grid;gap:3px}.bk-settlement-summary-row small{color:#687b8c}.bk-settlement-summary-row>strong,.bk-settlement-summary-total>strong:last-child{white-space:nowrap;text-align:right}.bk-settlement-summary-total{background:#fff8ee}@media(max-width:900px){.bk-settlement-col{min-width:155px}.bk-summary.has-settlement-total{grid-template-columns:repeat(2,1fr)}.bk-summary.has-settlement-total>label{grid-column:1/-1}}@media print{.bk-settlement-col{display:none!important}.bk-table tr.has-settlement{background:transparent!important}.bk-summary.has-settlement-total{grid-template-columns:repeat(5,1fr)}.bk-settlement-summary{break-inside:avoid}.bk-settlement-summary-row,.bk-settlement-summary-total{grid-template-columns:minmax(0,1fr) max-content}}
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
      const line=lines[index]||{};
      if(line.type==='section'){
        if(!row.querySelector('.bk-settlement-col')){
          const empty=document.createElement('td');
          empty.className='bk-settlement-col bk-section-settlement';
          row.insertBefore(empty,row.lastElementChild);
        }
        return;
      }
      if(row.querySelector('.bk-settlement-col'))return;
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
      let percentTimer;
      input.addEventListener('input',()=>{
        clearTimeout(percentTimer);
        percentTimer=setTimeout(()=>changeSettlement(index,'percent',input.value),150);
      });
      input.addEventListener('change',()=>{
        clearTimeout(percentTimer);
        changeSettlement(index,'percent',input.value);
      });
      row.insertBefore(td,row.lastElementChild);
    });
    updateSettlementSummary(lines);
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
      if(event.target.matches?.('input[data-k]'))updateSettlementSummary(getLines());
    });
    linesBody.addEventListener('click',event=>{
      if(event.target.closest?.('[data-remove]'))setTimeout(()=>enhanceSettlementTable(),0);
    });
  }
  enhanceSettlementTable();

  document.getElementById('bk-vat')?.addEventListener('input',()=>{
    if(getLines().length)updateSettlementSummary(getLines());
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
  addEventListener('beforeprint',()=>{document.querySelectorAll('textarea[data-auto-grow]').forEach(grow);enhanceSettlementTable();});
})();
