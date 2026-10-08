(() => {
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const money = value => new Intl.NumberFormat('de-DE', {style:'currency',currency:'EUR'}).format(Number(value) || 0);
  const amount = row => Number(row.offered_total) || (Number(row.offered_unit_price) || 0) * Number(row.quantity);
  const sourceLines = (row, result) => result.priced_components ? [
    ...result.priced_components.map(component=>({...component,source_position_code:component.position_code,regional_factor:1,bki_scope_checked:result.status==='ready',bki_batch_checked:true,kva_source_position:row.source_position,kva_description:row.description,kva_scope:row.scope,bki_note:(result.open_items||[]).map(item=>item.label).join('; ')||result.reason,comparison_basis:result.status==='ready'?'bki':'bki_partial'})),
    ...(result.status==='ready'?[]:[{description:'Noch nicht bepreist · '+row.description,quantity:1,unit:'psch',unit_price:0,regional_factor:1,comparison_basis:'kva_open',source_name:'Offene Teilleistungen · kein Preis angesetzt',kva_source_position:row.source_position,kva_description:row.description,kva_scope:row.scope,bki_note:(result.open_items||[]).map(item=>item.label).join('; ')||result.reason,offered_total:amount(row)}]),
  ] : result.status === 'ready' ? result.components.map(component => ({
    ...component, description: component.description, source_position_code: component.position_code,
    regional_factor: 1, bki_scope_checked: true, bki_batch_checked: true,
    kva_source_position: row.source_position, kva_description: row.description, kva_scope: row.scope,
    bki_note: result.reason, comparison_basis: 'bki',
  })) : [{
    description: row.description, quantity: Number(row.quantity), unit: row.unit,
    unit_price: Number(row.offered_unit_price) || amount(row) / Number(row.quantity),
    regional_factor: 1, source_name: 'KVA-Angebotspreis · BKI-Abgleich offen',
    kva_source_position: row.source_position, kva_description: row.description,
    bki_note: result.reason, comparison_basis: 'kva_open',
  }];
  function replaceGroup(existing, group, lines) {
    const identity = value => String(value || '').toLocaleLowerCase('de-DE').replace(/[^\p{L}\p{N}]/gu,'');
    let skip = false;
    const retained = existing.filter(line => {
      if (line.type === 'section') skip =
        !!(group.kva_number && group.kva_number === line.kva_number && identity(group.kva_company) === identity(line.kva_company)) ||
        !!(group.description && identity(line.description).includes(identity(group.description)));
      return !skip;
    });
    return [...retained, {...group, type:'section', description:'KVA-/BKI-Vergleich · '+group.description, quantity:0, unit_price:0, regional_factor:1}, ...lines];
  }
  async function prepare({rows,request,location,caseMeta,level,group,bridge,state,host}) {
    host.replaceChildren();
    const heading = document.createElement('h3');heading.textContent = 'Gemeinsamer KVA-Abgleich';host.append(heading);
    const summary = document.createElement('p');host.append(summary);
    const results = document.createElement('div');host.append(results);
    const factsPanel = document.createElement('div');factsPanel.hidden = true;
    factsPanel.innerHTML = '<strong>Fehlende Angaben einmal für den gesamten KVA ergänzen</strong><ul data-questions></ul><label>Aufmaß / Ausführung / Arbeitsbedingungen<textarea data-facts rows="3" placeholder="Nur bekannte Angaben, z. B. Rohrdurchmesser, Öffnungsfläche, Zugänglichkeit oder Abfallgewicht."></textarea></label><button data-retry type="button">Mit ergänzten Angaben erneut abgleichen</button>';
    host.append(factsPanel);
    factsPanel.querySelector('textarea').value=document.getElementById('bk-known-facts')?.value||'';
    factsPanel.querySelector('textarea').addEventListener('input',()=>{const known=document.getElementById('bk-known-facts');if(known){known.value=factsPanel.querySelector('textarea').value;known.dispatchEvent(new Event('input',{bubbles:true}));}});
    const actions = document.createElement('div');actions.className = 'bk-actions';host.append(actions);
    let data = null, pending = false;
    const run = async () => {
      pending = true;actions.replaceChildren();factsPanel.querySelector('button').disabled = true;
      state.textContent = 'Der gesamte KVA wird gemeinsam mit den gekauften BKI-Unterlagen abgeglichen …';summary.textContent = 'Leistungsumfang, Mengen und Preisbelege werden zusammen geprüft.';
      try {
        data = await request('/intern/api/bki-calculator.php?action=compare_kva', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({rows,location,case_meta:caseMeta,level,basis:document.getElementById('bk-price-basis')?.value||'bki',rpa_confirmed:document.getElementById('bk-rpa-confirmed')?.checked===true,facts:{notes:factsPanel.querySelector('textarea').value.trim()}})});
        const ready = data.positions.filter(row => row.status === 'ready').length;
        const open = rows.length - ready;
        const catalog=data.search_mode==='ionos_catalog';
        const partial=data.positions.filter(row=>row.status==='partial').length;
        summary.textContent = catalog ? `${ready} Leistungen vollständig bepreist · ${partial} mit berechneten Teilleistungen · ${open} mit offenen Teilen. Preisgrundlage: ${data.catalog_positions} Positionen auf IONOS.` : `${ready} von ${rows.length} Leistungen vollständig aus BKI belegt. ${open} offen. ${data.cached ? 'Gespeichertes Ergebnis verwendet.' : ''}`;
        let proposed = 0, offered = 0;
        const rendered = data.positions.map((result, i) => {
          const row = rows[Number(result.row_id)] || rows[i];offered += amount(row);
          const total = catalog ? result.calculated_net : result.status === 'ready' ? result.components.reduce((sum,c) => sum + c.quantity * c.unit_price, 0) : amount(row);proposed += total||0;
          return `<tr><td>${esc(row.source_position)}</td><td>${esc(row.description)}${(result.open_items||[]).length?'<small style="display:block">Offen: '+(result.open_items||[]).map(item=>esc(item.label)).join(' · ')+'</small>':''}<details><summary>Begründung und Quelle</summary><p>${esc(row.scope || "")}</p><p>${esc(result.reason)}</p>${(result.status==='ready'?result.components:(result.source_candidates||[]).filter(c=>c.evidence_verified)).map(c=>`<p>${c.scope_issue?'<em>Nicht addiert: '+esc(c.scope_issue)+'</em><br>':result.status==='ready'?'':'<em>Belegter Teilpreis; Gesamtleistung weiterhin offen.</em><br>'}<strong>${esc(c.position_code)} · ${esc(c.description)}</strong><br>${c.quantity_verified?esc(c.quantity)+' '+esc(c.unit)+' × '+money(c.unit_price):money(c.unit_price)+' / '+esc(c.unit)+' · Menge offen'}<br>${esc(c.source_name)}${c.source_page?' · '+esc(c.source_page):' · Originalauszug'}<br>${esc(c.source_quote)}${c.source_url?'<br><a href="'+esc(c.source_url)+'" target="_blank" rel="noopener">Originalfundstelle öffnen</a>':''}</p>`).join('')}</details></td><td>${money(amount(row))}</td><td>${result.status==='ready'?'BKI belegt':'BKI offen · KVA bleibt'}</td><td>${money(total)}</td></tr>`;
        }).join('');
        results.innerHTML = `<div class="bk-batch-table"><table><thead><tr><th>KVA</th><th>Leistung</th><th>Angebot netto</th><th>Grundlage</th><th>${catalog?'Berechnet netto':'Entwurf netto'}</th></tr></thead><tbody>${rendered}</tbody></table></div><p>Angebot: ${money(offered)} · ${catalog?'Aus Originalpreisen berechnet':'Vergleichsentwurf'}: ${money(proposed)}. ${catalog&&open?'Diese Summe enthält nur belegte Teilleistungen. Offene Teile sind noch nicht bepreist; Angebotspreise werden nicht als Berechnung ausgegeben.':open?'Enthält Angebotspreise für offene Leistungen; noch keine vollständige BKI-Vergleichssumme.':'Alle ausgewählten Leistungen sind durch BKI-Fundstellen belegt.'} Regionalfaktor ist nicht belegt und wird nicht ergänzt.</p>`;
        if(catalog){results.querySelectorAll('tbody tr').forEach((tr,i)=>{const result=data.positions[i];tr.cells[3].textContent=result.status==='ready'?'Vollständig berechnet':result.status==='partial'?'Teilbetrag · offene Teile':'Noch nicht bepreist';tr.cells[4].textContent=result.calculated_net===null?'—':money(result.calculated_net);});}
        if(catalog){
          const notice=document.createElement('p');notice.textContent=data.planning?'Vorläufige Terminplanung: Nutzerannahmen sind berücksichtigt. Die Werte sind keine abschließende Schadenhöhe.':'Preisrahmen aus Originalquellen; offene Aufmaße können für den Ortstermin gesammelt ergänzt werden.';results.prepend(notice);
          results.querySelectorAll('tbody tr').forEach((tr,i)=>{const result=data.positions[i];if(result.calculated_net!==null){const range=document.createElement('small');range.style.display='block';range.textContent='von '+money(result.calculated_low)+' bis '+money(result.calculated_high);tr.cells[4].append(range);}else{const priced=(result.source_candidates||[]).filter(c=>c.evidence_verified&&c.scope_issue==='');const rates=priced.slice(0,2).map(c=>money(c.unit_price)+' / '+c.unit);if(rates.length)tr.cells[4].textContent=rates.join(' · ')+' (Menge offen)';}});
          const taskList=document.createElement('details');taskList.innerHTML='<summary>Gebündelte Angaben für den Ortstermin</summary><ul>'+[...new Set((data.questions||[]).map(q=>q.label).filter(Boolean))].slice(0,8).map(label=>'<li>'+esc(label)+'</li>').join('')+'</ul>';results.append(taskList);
        }
        const questions = new Map();for(const question of data.questions || [])if(question?.label)questions.set(question.key || question.label,question.label);
        for(const position of data.positions)if(position.status==='open'&&String(position.reason).includes('Feuerwiderstandsklasse')&&!Array.from(questions.values()).some(q=>/Feuerwiderstand|Abschottungssystem/i.test(q)))questions.set('fire_resistance','Welche Feuerwiderstandsklasse ist für die Deckendurchführungen tatsächlich erforderlich?');
        factsPanel.hidden = open === 0;
        factsPanel.querySelector('[data-questions]').innerHTML = [...questions.values()].slice(0,8).map(q=>'<li>'+esc(q)+'</li>').join('') || '<li>Die offenen Leistungen stehen in der Übersicht. Ergänzen Sie vorhandene Angaben zum Leistungsumfang gesammelt.</li>';
        const apply = document.createElement('button');apply.type = 'button';apply.className = 'bk-primary';apply.textContent = 'Vergleichsentwurf in Kalkulation übernehmen';
        if(catalog)apply.textContent='Berechnete Leistungen und offene Teile übernehmen';
        apply.onclick = () => {
          if(pending || apply.disabled)return;
          const before = bridge.getLines();const lines = data.positions.flatMap((result,i)=>sourceLines(rows[Number(result.row_id)]||rows[i],result));
          if(lines.some(line=>!Number.isFinite(line.unit_price)||(!(line.unit_price>0)&&!(catalog&&line.comparison_basis==='kva_open'))||!(line.quantity>0))) {state.textContent='Übernahme gesperrt: Menge oder Preis fehlt.';return;}
          if(data.planning)for(const line of lines){line.bki_scope_checked=false;line.planning_assumptions=data.facts?.notes||'';if(line.comparison_basis==='bki')line.comparison_basis='bki_partial';}
          bridge.setLines(replaceGroup(before,group,lines));apply.disabled = true;
          state.textContent = catalog?`Nachkalkulation übernommen: ${money(proposed)} belegte Teilleistungen. ${open} Positionen enthalten noch nicht bepreiste Teile.`:`${rows.length} KVA-Leistungen übernommen. ${open} BKI-Zuordnungen bleiben offen; deren Angebotspreise sind erhalten.`;
          const undo = document.createElement('button');undo.type='button';undo.className='bk-secondary';undo.textContent='Vorherige Kalkulation wiederherstellen';undo.onclick=()=>{bridge.setLines(before);undo.disabled=true;apply.disabled=false;state.textContent='Vorherige Kalkulation wiederhergestellt.';};actions.append(undo);
          document.querySelector('.bk-protocol')?.scrollIntoView({behavior:'smooth',block:'start'});
        };actions.append(apply);
        state.textContent = catalog?`${rows.length} KVA-Leistungen geprüft · ${money(proposed)} ${data.planning?'vorläufiger':'berechneter'} Teilbetrag · weitere Leistungen noch nicht bepreist.`:`${rows.length} KVA-Leistungen gemeinsam abgeglichen · ${ready} BKI belegt · ${open} offen.`;
      } catch(error) {
        state.textContent = 'BKI-Abgleich fehlgeschlagen: '+error.message+'. Die bestehende Kalkulation wurde nicht verändert.';
        summary.textContent = 'Der Abgleich konnte nicht abgeschlossen werden.';results.replaceChildren();factsPanel.hidden=false;
      } finally {pending=false;factsPanel.querySelector('button').disabled=false;}
    };
    factsPanel.querySelector('button').onclick = run;
    await run();
  }
  window.SVNetBkiBatch = {prepare,replaceGroup,sourceLines};
})();
