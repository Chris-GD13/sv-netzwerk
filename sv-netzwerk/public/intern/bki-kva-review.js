/* BKI results are proposals. A priced hit is not proof of a complete KVA scope. */
(()=>{
  const esc=value=>String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const number=value=>Number(String(value??'').replace(',','.'));
  const unit=value=>String(value??'').toLowerCase().replace(/²/g,'2').replace(/[.\s]/g,'').replace(/rohrleitung/g,'').replace(/^(pausch|pauschale)$/,'psch').replace(/^(stk|stück)$/,'st');
  const suggestedQuantity=(row,match)=>unit(row.unit)===unit(match.unit)&&Number(row.quantity)>0?Number(row.quantity):'';
  const price=(match,level)=>number(match[level==='low'?'price_low':level==='high'?'price_high':'price_mid']);
  async function prepare({rows,request,location,caseMeta,level,group,bridge,state,host}){
    host.replaceChildren();
    let results=0,sectionAdded=false;
    const entries=[];
    const update=()=>{const open=entries.filter(e=>!e.adopted).length;state.textContent=`${results}/${rows.length} Positionen geprüft. ${open} Position(en) offen. BKI-Treffer sind Vorschläge; eine Teilsumme ist keine vollständige Vergleichskalkulation.`};
    for(let i=0;i<rows.length;i++){
      const row=rows[i],entry={adopted:false};entries.push(entry);
      const panel=document.createElement('section');panel.className='bk-kva-match';host.appendChild(panel);
      panel.innerHTML=`<h3>KVA ${esc(row.source_position||i+1)} · ${esc(row.description)}</h3><p>KVA-Menge: ${esc(row.quantity)} ${esc(row.unit)}. BKI-Suche läuft …</p>`;
      state.textContent=`${i+1}/${rows.length}: BKI-Vorschläge für ${row.description} werden gesucht …`;
      let data;
      try{data=await request('/intern/api/bki-calculator.php?action=search',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({query:row.description,quantity:String(row.quantity),unit:row.unit,location,case_meta:caseMeta})})}
      catch(error){panel.innerHTML+=`<p>Offen: ${esc(error.message)}</p>`;state.textContent=`BKI-Abgleich unterbrochen: ${error.message}. Keine automatische Preisübernahme. ${rows.length-i} Position(en) nicht abschließend geprüft.`;return}
      results++;
      panel.innerHTML=`<h3>KVA ${esc(row.source_position||i+1)} · ${esc(row.description)}</h3><p>KVA-Menge: ${esc(row.quantity)} ${esc(row.unit)}. Status: <strong data-status>offen – Leistungsumfang prüfen</strong></p>`;
      const candidates=(data.positions||[]).filter(m=>price(m,level)>0);
      if(!candidates.length)panel.innerHTML+='<p>Kein belastbarer BKI-Treffer. Die Leistung bleibt offen und darf bei einer Gesamtkalkulation nicht entfallen.</p>';
      if(data.regional_factor_note){const note=document.createElement('p');note.textContent=data.regional_factor_note;panel.appendChild(note)}
      for(const match of candidates){
        const card=document.createElement('div');card.className='bk-kva-candidate';panel.appendChild(card);
        const source=!!(match.position_code&&match.source_name&&match.source_page);
        const qty=suggestedQuantity(row,match),ep=price(match,level);
        card.innerHTML=`<strong>${esc(match.position_code)} · ${esc(match.description)}</strong><p>${esc(match.source_name)} · ${esc(match.source_page||'Quellenseite fehlt')}<br>Einheit: ${esc(match.unit)} · EP netto: ${esc(ep)} €</p><p>${esc(match.note||'Leistungsumfang anhand der Quelle prüfen.')}</p>${qty===''?'<p><strong>Abweichende Einheit: Eine Umrechnung ist nicht belegt. BKI-Menge anhand des Aufmaßes bestimmen.</strong></p>':''}<label>BKI-Menge (${esc(match.unit)}) <input data-quantity inputmode="decimal" type="text" value="${esc(qty)}" aria-label="BKI-Menge ${esc(match.position_code)}"></label><label><input data-scope type="checkbox"> Leistungsumfang und Menge geprüft; fehlende Teilleistungen und Doppelansätze berücksichtigt.</label><button type="button" data-adopt disabled>Geprüften BKI-Ansatz übernehmen</button><p data-message>${source?'':'Übernahme gesperrt: Positionsnummer, Quelle oder Quellenseite fehlt.'}</p>`;
        const input=card.querySelector('[data-quantity]'),checked=card.querySelector('[data-scope]'),button=card.querySelector('[data-adopt]');
        const ready=()=>{button.disabled=!source||!checked.checked||!(number(input.value)>0)||!Number.isFinite(number(input.value))};
        input.addEventListener('input',ready);checked.addEventListener('change',ready);
        button.addEventListener('click',()=>{
          ready();if(button.disabled)return;
          const quantity=number(input.value);
          if(!sectionAdded){bridge.addSection({...group,description:'BKI-Prüfansätze · '+group.description});sectionAdded=true}
          bridge.addLine({position_code:match.position_code,source_position_code:match.position_code,description:match.description,quantity,recommended_quantity:quantity,unit:match.unit,unit_price:ep,regional_factor:number(data.regional_factor)>0?number(data.regional_factor):1,source_name:match.source_name,source_page:match.source_page,bki_scope_checked:true,kva_source_position:row.source_position,kva_description:row.description,bki_note:match.note||''});
          button.disabled=true;input.disabled=true;checked.disabled=true;
          // Keep alternatives available for necessary separate components, without adding them automatically.
          entry.adopted=true;panel.querySelector('[data-status]').textContent='geprüfter Ansatz übernommen – Vollständigkeit der Gesamtleistung weiterhin prüfen';
          card.querySelector('[data-message]').textContent='BKI-Ansatz übernommen. Weitere Treffer nur für zusätzliche, getrennte Teilleistungen verwenden.';
          update();
        },{once:true});
      }
      update();
    }
  }
  window.SVNetBkiReview={prepare,suggestedQuantity,unit};
})();
