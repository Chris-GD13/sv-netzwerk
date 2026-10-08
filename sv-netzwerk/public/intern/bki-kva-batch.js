(() => {
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const money = value => new Intl.NumberFormat('de-DE', {style:'currency',currency:'EUR'}).format(Number(value) || 0);
  const amount = row => Number(row.offered_total) || (Number(row.offered_unit_price) || 0) * Number(row.quantity);
  const sourceLines = (row, result) => result.status === 'ready' ? result.components.map(component => ({
    ...component, description: component.description, source_position_code: component.position_code,
    regional_factor: 1, bki_scope_checked: true, bki_batch_checked: true,
    kva_source_position: row.source_position, kva_description: row.description,
    bki_note: result.reason, comparison_basis: 'bki',
  })) : [{
    description: row.description, quantity: Number(row.quantity), unit: row.unit,
    unit_price: Number(row.offered_unit_price) || amount(row) / Number(row.quantity),
    regional_factor: 1, source_name: 'KVA-Angebotspreis · BKI-Abgleich offen',
    kva_source_position: row.source_position, kva_description: row.description,
    bki_note: result.reason, comparison_basis: 'kva_open',
  }];
  function replaceGroup(existing, group, lines) {
    let skip = false;
    const retained = existing.filter(line => {
      if (line.type === 'section') skip =
        !!(group.kva_number && group.kva_number === line.kva_number && group.kva_company === line.kva_company) ||
        !!(group.description && String(line.description || '').includes(group.description));
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
    const actions = document.createElement('div');actions.className = 'bk-actions';host.append(actions);
    let data = null, pending = false;
    const run = async () => {
      pending = true;actions.replaceChildren();factsPanel.querySelector('button').disabled = true;
      state.textContent = 'Der gesamte KVA wird gemeinsam mit den gekauften BKI-Unterlagen abgeglichen …';summary.textContent = 'Leistungsumfang, Mengen und Preisbelege werden zusammen geprüft.';
      try {
        data = await request('/intern/api/bki-calculator.php?action=compare_kva', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({rows,location,case_meta:caseMeta,level,facts:{notes:factsPanel.querySelector('textarea').value.trim()}})});
        const ready = data.positions.filter(row => row.status === 'ready').length;
        const open = rows.length - ready;
        summary.textContent = `${ready} von ${rows.length} Leistungen vollständig aus BKI belegt. ${open} offen. ${data.cached ? 'Gespeichertes Ergebnis verwendet.' : ''}`;
        let proposed = 0, offered = 0;
        const rendered = data.positions.map((result, i) => {
          const row = rows[Number(result.row_id)] || rows[i];offered += amount(row);
          const total = result.status === 'ready' ? result.components.reduce((sum,c) => sum + c.quantity * c.unit_price, 0) : amount(row);proposed += total;
          return `<tr><td>${esc(row.source_position)}</td><td>${esc(row.description)}<details><summary>Begründung und Quelle</summary><p>${esc(result.reason)}</p>${(result.components||[]).map(c=>`<p><strong>${esc(c.position_code)} · ${esc(c.description)}</strong><br>${esc(c.quantity)} ${esc(c.unit)} × ${money(c.unit_price)}<br>${esc(c.source_name)}${c.source_page?' · '+esc(c.source_page):' · Originalauszug'}<br>${esc(c.source_quote)}</p>`).join('')}</details></td><td>${money(amount(row))}</td><td>${result.status==='ready'?'BKI belegt':'BKI offen · KVA bleibt'}</td><td>${money(total)}</td></tr>`;
        }).join('');
        results.innerHTML = `<div class="bk-batch-table"><table><thead><tr><th>KVA</th><th>Leistung</th><th>Angebot netto</th><th>Grundlage</th><th>Entwurf netto</th></tr></thead><tbody>${rendered}</tbody></table></div><p>Angebot: ${money(offered)} · Vergleichsentwurf: ${money(proposed)}. ${open?'Enthält Angebotspreise für offene Leistungen; noch keine vollständige BKI-Vergleichssumme.':'Alle ausgewählten Leistungen sind durch BKI-Fundstellen belegt.'} Regionalfaktor ist nicht belegt und wird nicht ergänzt.</p>`;
        const questions = new Map();for(const question of data.questions || [])if(question?.label)questions.set(question.key || question.label,question.label);
        factsPanel.hidden = open === 0;
        factsPanel.querySelector('[data-questions]').innerHTML = [...questions.values()].slice(0,8).map(q=>'<li>'+esc(q)+'</li>').join('') || '<li>Die offenen Leistungen stehen in der Übersicht. Ergänzen Sie vorhandene Angaben zum Leistungsumfang gesammelt.</li>';
        const apply = document.createElement('button');apply.type = 'button';apply.className = 'bk-primary';apply.textContent = 'Vergleichsentwurf in Kalkulation übernehmen';
        apply.onclick = () => {
          if(pending || apply.disabled)return;
          const before = bridge.getLines();const lines = data.positions.flatMap((result,i)=>sourceLines(rows[Number(result.row_id)]||rows[i],result));
          if(lines.some(line=>!Number.isFinite(line.unit_price)||!(line.unit_price>0)||!(line.quantity>0))) {state.textContent='Übernahme gesperrt: KVA-Menge oder Angebotspreis fehlt.';return;}
          bridge.setLines(replaceGroup(before,group,lines));apply.disabled = true;
          state.textContent = `${rows.length} KVA-Leistungen übernommen. ${open} BKI-Zuordnungen bleiben offen; deren Angebotspreise sind erhalten.`;
          const undo = document.createElement('button');undo.type='button';undo.className='bk-secondary';undo.textContent='Vorherige Kalkulation wiederherstellen';undo.onclick=()=>{bridge.setLines(before);undo.disabled=true;apply.disabled=false;state.textContent='Vorherige Kalkulation wiederhergestellt.';};actions.append(undo);
          document.querySelector('.bk-protocol')?.scrollIntoView({behavior:'smooth',block:'start'});
        };actions.append(apply);
        state.textContent = `${rows.length} KVA-Leistungen gemeinsam abgeglichen · ${ready} BKI belegt · ${open} offen.`;
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
