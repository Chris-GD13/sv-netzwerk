from pathlib import Path

path = Path(__file__).resolve().parents[1] / 'src/pages/intern/versicherungsfaelle/index.astro'
source = path.read_text(encoding='utf-8')
updated = source

old_section = '''      <section class="vf-card"><header class="vf-step"><b>5</b><div><strong>Automatische Bearbeitung</strong><small>Verbindliche Prüfreihenfolge für jeden Fall</small></div></header><ol class="vf-pipeline"><li><b>01</b><strong>Fallzuordnung</strong><small>Schaden-Nr., VN, Objekt, Dokumentart und Fremdakten</small></li><li><b>02</b><strong>Belegprüfung</strong><small>Rechnung/KVA trennen, Bruttobeträge, Dubletten und Zahlungen</small></li><li><b>03</b><strong>Bewertung</strong><small>Schadenbezug, Abzüge, offene Punkte, Regress und Reserve</small></li><li><b>04</b><strong>Originalformulare</strong><small>Word und Excel auswählen, formatgetreu befüllen und speichern</small></li></ol><details class="vf-options"><summary>Ausgaben bei Bedarf anpassen</summary><div id="vf-outputs"><label><input type="checkbox" value="dokumentenindex" checked> Dokumentenindex</label><label><input type="checkbox" value="rechnungsregister" checked> Rechnungsregister</label><label><input type="checkbox" value="zwischenbericht" checked> Zwischenbericht</label><label><input type="checkbox" value="schlusserklaerung" checked> Schlusserklärung</label><label><input type="checkbox" value="schlussbericht"> Schlussbericht</label><label><input type="checkbox" value="nachtrag_stellungnahme"> Nachtrag / Stellungnahme</label><label><input type="checkbox" value="zahlungsbefuerwortung"> Zahlungsbefürwortung</label><label><input type="checkbox" value="query_form"> Rückfrageformular</label></div></details><button id="vf-excel-originals" class="vf-secondary vf-start" disabled>Excel-Originale im Fall öffnen</button><div id="vf-excel-links" class="vf-meta"></div><button id="vf-start" class="vf-primary vf-start" disabled>Fall automatisch bearbeiten</button><div id="vf-job" class="vf-job" hidden></div></section>'''

new_section = '''      <section class="vf-card vf-mail"><header class="vf-step"><b>5</b><div><strong>E-Mail schreiben</strong><small>Direkt aus dem aktiven Schadenfall senden</small></div></header><div class="vf-mail-grid"><label>An<input id="vf-mail-to" type="email" placeholder="Empfänger"></label><label>CC<input id="vf-mail-cc" type="text" placeholder="optional, mehrere mit ; trennen"></label><label class="wide">Betreff<input id="vf-mail-subject" type="text" readonly></label><label class="wide">Nachricht<textarea id="vf-mail-body" rows="5" placeholder="E-Mail-Text eingeben"></textarea></label><label class="wide">Anhänge<input id="vf-mail-files" type="file" multiple></label></div><div class="vf-mail-actions"><button id="vf-mail-send" class="vf-primary" disabled>E-Mail senden</button><span id="vf-mail-sender" class="vf-meta"></span></div><div id="vf-mail-state" class="vf-job" hidden></div></section>
      <details class="vf-card vf-processing"><summary>Bearbeitung & Ausgaben</summary><details class="vf-options"><summary>Ausgaben bei Bedarf anpassen</summary><div id="vf-outputs"><label><input type="checkbox" value="dokumentenindex" checked> Dokumentenindex</label><label><input type="checkbox" value="rechnungsregister" checked> Rechnungsregister</label><label><input type="checkbox" value="zwischenbericht" checked> Zwischenbericht</label><label><input type="checkbox" value="schlusserklaerung" checked> Schlusserklärung</label><label><input type="checkbox" value="schlussbericht"> Schlussbericht</label><label><input type="checkbox" value="nachtrag_stellungnahme"> Nachtrag / Stellungnahme</label><label><input type="checkbox" value="zahlungsbefuerwortung"> Zahlungsbefürwortung</label><label><input type="checkbox" value="query_form"> Rückfrageformular</label></div></details><button id="vf-excel-originals" class="vf-secondary vf-start" disabled>Excel-Originale im Fall öffnen</button><div id="vf-excel-links" class="vf-meta"></div><button id="vf-start" class="vf-primary vf-start" disabled>Fall automatisch bearbeiten</button><div id="vf-job" class="vf-job" hidden></div></details>'''

if old_section in updated:
    updated = updated.replace(old_section, new_section, 1)
elif 'id="vf-mail-send"' not in updated:
    raise SystemExit('Automatische-Bearbeitung-Abschnitt nicht gefunden')

updated = updated.replace(
    '.vf-drop{border:2px dashed #b9c9d5;border-radius:14px;padding:34px 20px;text-align:center;background:#f5f8fa;display:flex;flex-direction:column;align-items:center;gap:5px;cursor:pointer}',
    '.vf-drop{border:2px dashed #b9c9d5;border-radius:14px;padding:16px 18px;text-align:center;background:#f5f8fa;display:flex;flex-direction:column;align-items:center;gap:3px;cursor:pointer}',
    1,
)
updated = updated.replace(
    '.vf-drop>span{display:grid;place-items:center;width:42px;height:42px;border-radius:50%;background:#fff;color:var(--vfo);font-size:1.5rem}',
    '.vf-drop>span{display:grid;place-items:center;width:32px;height:32px;border-radius:50%;background:#fff;color:var(--vfo);font-size:1.15rem}',
    1,
)

mail_css = '.vf-mail-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.vf-mail-grid label{font-size:.78rem;font-weight:750;color:#536a7d}.vf-mail-grid input,.vf-mail-grid textarea{width:100%;box-sizing:border-box;border:1px solid #bdcbd6;border-radius:9px;padding:10px 11px;font:inherit;margin-top:4px}.vf-mail-grid .wide{grid-column:1/-1}.vf-mail-grid input[readonly]{background:#f3f6f8;color:#405568}.vf-mail-actions{display:flex;align-items:center;gap:12px;margin-top:12px}.vf-processing>summary{cursor:pointer;font-weight:800}.vf-processing .vf-options{margin-top:12px}'
anchor = '.vf-calendar-actions{display:flex;gap:9px;margin-top:12px}'
if mail_css not in updated:
    updated = updated.replace(anchor, anchor + mail_css, 1)

updated = updated.replace(
    '@media(max-width:760px){.vf-calendar-grid{grid-template-columns:1fr}',
    '@media(max-width:760px){.vf-calendar-grid,.vf-mail-grid{grid-template-columns:1fr}.vf-mail-grid .wide{grid-column:1}.vf-calendar-grid{grid-template-columns:1fr}',
    1,
)

old_consts = "const API='/intern/api/google-drive-sync.php',EX='/intern/api/insurance-case-extract.php',AI='/intern/api/gf-ai-generate.php',CAL='/intern/api/outlook-case-calendar.php',$=id=>document.getElementById(id);"
new_consts = "const API='/intern/api/google-drive-sync.php',EX='/intern/api/insurance-case-extract.php',AI='/intern/api/gf-ai-generate.php',CAL='/intern/api/outlook-case-calendar.php',MAIL='/intern/api/outlook-case-mail.php',$=id=>document.getElementById(id);"
if old_consts in updated:
    updated = updated.replace(old_consts, new_consts, 1)

old_show_tail = "$('vf-cal-check').disabled=!active?.folder_id;$('vf-cal-create').disabled=!active?.folder_id;$('vf-cal-address').value=[m.strasse,m.plz,m.ort].filter(Boolean).join(' ');"
new_show_tail = "$('vf-cal-check').disabled=!active?.folder_id;$('vf-cal-create').disabled=!active?.folder_id;$('vf-mail-send').disabled=!active?.folder_id;$('vf-cal-address').value=[m.strasse,m.plz,m.ort].filter(Boolean).join(' ');updateMailFields();"
if old_show_tail in updated:
    updated = updated.replace(old_show_tail, new_show_tail, 1)

old_fill = "function fill(m){Object.entries(map).forEach(([k,id])=>$(id).value=m[k]||'');$('vf-instructions').value=m.anweisung||'';grow()}"
new_fill = "function fill(m){Object.entries(map).forEach(([k,id])=>$(id).value=m[k]||'');$('vf-instructions').value=m.anweisung||'';grow();updateMailFields()}"
if old_fill in updated:
    updated = updated.replace(old_fill, new_fill, 1)

old_data = "function data(){const o={...(active?.meta||{}),fallart:type||active?.meta?.fallart||'',anweisung:$('vf-instructions').value.trim()};Object.entries(map).forEach(([k,id])=>o[k]=$(id).value.trim());return o}"
new_data = old_data + "function updateMailFields(){const m={...(active?.meta||{}),schaden_nr:$('vf-schaden')?.value?.trim()||active?.meta?.schaden_nr||'',schadenart:$('vf-art')?.value?.trim()||active?.meta?.schadenart||'',email:$('vf-email')?.value?.trim()||active?.meta?.email||''};if($('vf-mail-subject'))$('vf-mail-subject').value=[m.schaden_nr,m.schadenart].filter(Boolean).join(' – ');if($('vf-mail-to')&&!$('vf-mail-to').value&&m.email)$('vf-mail-to').value=m.email}"
if old_data in updated and 'function updateMailFields()' not in updated:
    updated = updated.replace(old_data, new_data, 1)

hook_anchor = "$('vf-new').onclick=()=>$('vf-form').hidden=!$('vf-form').hidden;"
mail_hooks = "['vf-schaden','vf-art','vf-email'].forEach(id=>$(id)?.addEventListener('input',updateMailFields));$('vf-mail-send').onclick=sendCaseMail;async function sendCaseMail(){if(!active?.folder_id)return;const state=$('vf-mail-state'),button=$('vf-mail-send'),m=data();state.hidden=false;state.className='vf-job';state.textContent='E-Mail wird gesendet …';button.disabled=true;const fd=new FormData();fd.append('to',$('vf-mail-to').value.trim());fd.append('cc',$('vf-mail-cc').value.trim());fd.append('case_no',m.schaden_nr||'');fd.append('damage_type',m.schadenart||'');fd.append('body',$('vf-mail-body').value.trim());[...$('vf-mail-files').files].forEach((f,i)=>fd.append('attachment_'+i,f));try{const d=await api(MAIL+'?action=send',{method:'POST',body:fd});state.className='vf-job ok';state.innerHTML='<strong>E-Mail gesendet.</strong><br>'+e(d.subject||'');$('vf-mail-body').value='';$('vf-mail-files').value=''}catch(x){state.className='vf-job bad';state.textContent=x.message}finally{button.disabled=!active?.folder_id}}async function mailStatus(){try{const d=await api(MAIL+'?action=status');$('vf-mail-sender').textContent='Absender: '+(d.sender||'Outlook');return d}catch(x){$('vf-mail-sender').textContent=x.message;return null}};"
if hook_anchor in updated and 'async function sendCaseMail()' not in updated:
    updated = updated.replace(hook_anchor, mail_hooks + hook_anchor, 1)

old_init = "calendarStatus().catch(()=>{});active=read();"
new_init = "calendarStatus().catch(()=>{});mailStatus().catch(()=>{});active=read();"
if old_init in updated:
    updated = updated.replace(old_init, new_init, 1)

if updated != source:
    path.write_text(updated, encoding='utf-8')
    print('Versicherungsfall-UI aktualisiert.')
else:
    print('Versicherungsfall-UI bereits aktuell.')
