from pathlib import Path

path = Path(__file__).resolve().parents[1] / 'src/pages/intern/kalkulation/index.astro'
text = path.read_text(encoding='utf-8')

MARKER = 'data-bki-rollladen-pricing="1"'
if MARKER in text:
    print('BKI Rollladen pricing patch already present')
    raise SystemExit(0)

# Mark the affected quick-index presets. The BKI 2026 source contains a complete
# aluminium Vorbaurollladen, but no separate PVC counterpart and no standalone
# Rollladenpanzer positions. Therefore these variants must not be priced by
# blindly summing semantically similar BKI hits.
replacements = {
    "{c:'Rollladen',l:'Rollladenpanzer Aluminium',u:'m²',q:'Rollladenpanzer Aluminium erneuern vorhandener Rollladen'}": "{c:'Rollladen',l:'Rollladenpanzer Aluminium',u:'m²',q:'Rollladenpanzer Aluminium erneuern vorhandener Rollladen',ml:true,h:0.55}",
    "{c:'Rollladen',l:'Rollladenpanzer PVC',u:'m²',q:'Rollladenpanzer Kunststoff PVC erneuern vorhandener Rollladen'}": "{c:'Rollladen',l:'Rollladenpanzer PVC',u:'m²',q:'Rollladenpanzer Kunststoff PVC erneuern vorhandener Rollladen',ml:true,h:0.55}",
    "{c:'Rollladen',l:'Vorbaurollladen, Panzer Alu',u:'St',q:'Vorbaurollladen komplett Aluminium Rollladenpanzer Aluminium'}": "{c:'Rollladen',l:'Vorbaurollladen, Panzer Alu',u:'St',q:'Vorbaurollladen komplett Aluminium Rollladenpanzer Aluminium',ml:true,h:1.10}",
    "{c:'Rollladen',l:'Vorbaurollladen, Panzer PVC',u:'St',q:'Vorbaurollladen komplett Rollladenpanzer Kunststoff PVC'}": "{c:'Rollladen',l:'Vorbaurollladen, Panzer PVC',u:'St',q:'Vorbaurollladen komplett Rollladenpanzer Kunststoff PVC',ml:true,h:1.10}",
}
for old, new in replacements.items():
    if old in text:
        text = text.replace(old, new, 1)

# Add material/labour input fields to the quick items. Existing non-ML items are
# unchanged. Material price is deliberately user-editable because BKI 2026 does
# not provide material-separated prices for these exact variants.
old_render = "presets.forEach((p,i)=>{const e=document.createElement('div');e.className='bk-quick-item';e.dataset.cat=p.c;e.innerHTML=`<label><input type=\"checkbox\" data-preset=\"${i}\"><span>${esc(p.l)}<small class=\"bk-quick-note\">inkl. Demontage, Entsorgung + Montage</small></span></label><span></span><input class=\"bk-quick-qty\" data-qty=\"${i}\" inputmode=\"decimal\" value=\"1\"><span class=\"bk-quick-unit\">${esc(p.u)}</span>`;const cb=e.querySelector('input[type=checkbox]');cb.onchange=()=>e.classList.toggle('checked',cb.checked);list.appendChild(e)});"
new_render = "presets.forEach((p,i)=>{const e=document.createElement('div');e.className='bk-quick-item';e.dataset.cat=p.c;const extra=p.ml?`<div class=\"bk-ml\" data-ml=\"${i}\" data-bki-rollladen-pricing=\"1\"><label>Material netto / ${esc(p.u)}<input class=\"bk-ml-material\" data-mat=\"${i}\" inputmode=\"decimal\" placeholder=\"z. B. Angebot / Marktpreis\"></label><label>Arbeitszeit h / ${esc(p.u)}<input class=\"bk-ml-hours\" data-hours=\"${i}\" inputmode=\"decimal\" value=\"${p.h||0.5}\"></label></div>`:'';e.innerHTML=`<label><input type=\"checkbox\" data-preset=\"${i}\"><span>${esc(p.l)}<small class=\"bk-quick-note\">inkl. Demontage, Entsorgung + Montage</small>${p.ml?'<small class=\"bk-quick-note\">Materialpreis + BKI-Facharbeiterlohn; keine unpassende Ersatzposition</small>':''}</span></label><span></span><input class=\"bk-quick-qty\" data-qty=\"${i}\" inputmode=\"decimal\" value=\"1\"><span class=\"bk-quick-unit\">${esc(p.u)}</span>${extra}`;const cb=e.querySelector('input[type=checkbox]');cb.onchange=()=>e.classList.toggle('checked',cb.checked);list.appendChild(e)});"
if old_render in text:
    text = text.replace(old_render, new_render, 1)
else:
    raise SystemExit('quick item render anchor not found')

# For ML items, obtain only the BKI Facharbeiter hourly rate and calculate
# material + labour. This prevents the previous incorrect combinations such as
# 330.000.017 + unrelated complete systems, and ensures PVC/Alu are not forced
# to the same value.
old_loop = "try{\n          const d=await api({query:fullQuery,quantity:String(qty),unit:p.u,location:document.getElementById('bk-location')?.value||'',case_meta:bridge()?.getMeta?.()||{}}), positions=Array.isArray(d.positions)?d.positions:[];"
new_loop = "try{\n          if(p.ml){\n            const material=number(list.querySelector(`[data-mat=\\\"${idx}\\\"]`)?.value);\n            const hours=number(list.querySelector(`[data-hours=\\\"${idx}\\\"]`)?.value);\n            if(material<=0){failed.push(`${p.l} (Materialpreis fehlt)`);continue}\n            const labor=await api({query:'LB 330 Rollladenarbeiten Stundensatz Facharbeiter Position 330.000.034. Gib ausschließlich diese Position zurück.',quantity:String(hours||1),unit:'h',location:document.getElementById('bk-location')?.value||'',case_meta:bridge()?.getMeta?.()||{}});\n            const lp=(Array.isArray(labor.positions)?labor.positions:[]).find(x=>String(x.position_code||'').includes('330.000.034'))||(Array.isArray(labor.positions)?labor.positions[0]:null);\n            const hourly=lp?choosePrice(lp):0;\n            if(hourly<=0){failed.push(`${p.l} (BKI-Facharbeiterlohn fehlt)`);continue}\n            const rf=number(labor.regional_factor)||1, ep=material+(hours*hourly);\n            bridge()?.addLine?.({position_code:'Material + 330.000.034',description:`${p.l} – Material sowie Demontage, Entsorgung und Montage`,quantity:qty,recommended_quantity:qty,unit:p.u,unit_price:ep,regional_factor:rf});added++;continue\n          }\n          const d=await api({query:fullQuery,quantity:String(qty),unit:p.u,location:document.getElementById('bk-location')?.value||'',case_meta:bridge()?.getMeta?.()||{}}), positions=Array.isArray(d.positions)?d.positions:[];"
if old_loop in text:
    text = text.replace(old_loop, new_loop, 1)
else:
    raise SystemExit('quick calculation loop anchor not found')

css_anchor = '@media print{.bk-quick{display:none!important}}'
css_extra = ".bk-ml{grid-column:1/-1;display:grid;grid-template-columns:1fr 1fr;gap:8px;padding:8px 0 0 26px}.bk-ml label{font-size:.72rem;color:#607687}.bk-ml input{display:block;width:100%;box-sizing:border-box;border:1px solid #bdcbd6;border-radius:8px;padding:7px;margin-top:3px}.bk-quick-item:not(.checked) .bk-ml{opacity:.6}@media(max-width:700px){.bk-ml{grid-template-columns:1fr;padding-left:0}}"
if css_anchor in text:
    text = text.replace(css_anchor, css_extra + css_anchor, 1)
else:
    raise SystemExit('quick css anchor not found')

path.write_text(text, encoding='utf-8')
print('BKI Rollladen pricing patch applied')
