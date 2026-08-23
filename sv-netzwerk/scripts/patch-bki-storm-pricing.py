from pathlib import Path
import re

path = Path(__file__).resolve().parents[1] / 'src/pages/intern/kalkulation/index.astro'
text = path.read_text(encoding='utf-8')

if 'data-bki-storm-index="1"' not in text:
    print('storm quick index not present yet')
    raise SystemExit(0)

# Brand-/zubehoerspezifische Teile haben im BKI keine belastbare eigene Produktposition.
# Sie duerfen deshalb niemals ueber eine komplette Dachfenster-/Bauteilposition bepreist werden.
replacements = {
    "{c:'Dachdetails',l:'Eindeckrahmen Velux',u:'St',q:'Eindeckrahmen Dachfenster VELUX passend zu vorhandener Ziegel Dachdeckung'}": "{c:'Dachdetails',l:'Eindeckrahmen Velux',u:'St',q:'Eindeckrahmen Dachfenster VELUX passend zu vorhandener Ziegel Dachdeckung',mode:'material_labor'}",
    "{c:'Dachdetails',l:'Eindeckrahmen Roto',u:'St',q:'Eindeckrahmen Dachfenster ROTO passend zu vorhandener Ziegel Dachdeckung'}": "{c:'Dachdetails',l:'Eindeckrahmen Roto',u:'St',q:'Eindeckrahmen Dachfenster ROTO passend zu vorhandener Ziegel Dachdeckung',mode:'material_labor'}",
    "{c:'Dachdetails',l:'Dachfensterrollladen manuell',u:'St',q:'Dachfenster Rollladen außen manuell mechanisch'}": "{c:'Dachdetails',l:'Dachfensterrollladen manuell',u:'St',q:'Dachfenster Rollladen außen manuell mechanisch',mode:'material_labor'}",
    "{c:'Dachdetails',l:'Dachfensterrollladen Solar',u:'St',q:'Dachfenster Rollladen außen solar elektrisch'}": "{c:'Dachdetails',l:'Dachfensterrollladen Solar',u:'St',q:'Dachfenster Rollladen außen solar elektrisch',mode:'material_labor'}",
}
for old, new in replacements.items():
    text = text.replace(old, new)

old_css = ".bk-quick-item{display:grid;grid-template-columns:auto 1fr 88px 52px;align-items:center;gap:8px;"
new_css = ".bk-quick-item{display:grid;grid-template-columns:auto 1fr 88px 52px;align-items:center;gap:8px;"
text = text.replace(old_css, new_css)
text = text.replace(".bk-quick-note{display:block;font-size:.72rem;color:#718493;font-weight:500;margin-top:2px}", ".bk-quick-note{display:block;font-size:.72rem;color:#718493;font-weight:500;margin-top:2px}.bk-quick-extra{grid-column:1/-1;display:flex;gap:8px;align-items:center;padding-left:26px}.bk-quick-extra label{font-size:.72rem;color:#5c7182;font-weight:750}.bk-quick-extra input{width:100px;border:1px solid #bdcbd6;border-radius:7px;padding:6px;text-align:right}.bk-quick-extra small{color:#718493}")
mobile_css = """@media(max-width:700px){.bk-quick{min-width:0;max-width:100%;overflow-x:hidden}.bk-quick-list,.bk-quick-item,.bk-quick-extra,.bk-quick-extra label{min-width:0;max-width:100%;box-sizing:border-box}.bk-quick-extra{display:grid;grid-template-columns:1fr;padding-left:0}.bk-quick-extra input,.bk-quick-qty{width:100%;max-width:100%;box-sizing:border-box;font-size:16px!important}.bk-quick input[inputmode],.bk-table input,.bk-summary input,.bk-grid input,.bk-grid textarea,.bk-grid select,.bk-note textarea{font-size:16px!important}}"""
if mobile_css not in text:
    text = text.replace('</style>', mobile_css + '</style>', 1)

pattern = re.compile(r"presets\.forEach\(\(p,i\)=>\{const e=document\.createElement\('div'\);e\.className='bk-quick-item';e\.dataset\.cat=p\.c;e\.innerHTML=`<label><input type=\\\"checkbox\\\" data-preset=\\\"\$\{i\}\\\"><span>\$\{esc\(p\.l\)\}<small class=\\\"bk-quick-note\\\">inkl\. Demontage, Entsorgung \+ Montage</small></span></label><span></span><input class=\\\"bk-quick-qty\\\" data-qty=\\\"\$\{i\}\\\" inputmode=\\\"decimal\\\" value=\\\"1\\\"><span class=\\\"bk-quick-unit\\\">\$\{esc\(p\.u\)\}</span>`;const cb=e\.querySelector\('input\[type=checkbox\]'\);cb\.onchange=\(\)=>e\.classList\.toggle\('checked',cb\.checked\);list\.appendChild\(e\)\}\);")
replacement = """const indexDefaults=p=>{const label=String(p.l||'').toLowerCase();const rules=[
          [/eindeckrahmen velux/,{material:240,hours:1.5,labor:72}],
          [/eindeckrahmen roto/,{material:250,hours:1.5,labor:72}],
          [/dachfensterrollladen manuell/,{material:620,hours:2.0,labor:72}],
          [/dachfensterrollladen solar/,{material:980,hours:2.5,labor:72}],
          [/rollladenpanzer aluminium/,{material:115,hours:1.0,labor:72}],
          [/rollladenpanzer pvc/,{material:72,hours:1.0,labor:72}],
          [/vorbaurollladen, panzer alu/,{material:690,hours:3.0,labor:72}],
          [/vorbaurollladen, panzer pvc/,{material:520,hours:3.0,labor:72}],
          [/pv-unterkonstruktion|dachhaken/,{material:75,hours:0.5,labor:78}],
          [/photovoltaikmodul/,{material:160,hours:1.0,labor:78}],
          [/außenleuchte|lampe/,{material:95,hours:1.0,labor:72}],
          [/feuchtraum|wannenleuchte/,{material:65,hours:0.75,labor:72}],
          [/paneel einzeln/,{material:480,hours:2.5,labor:76}],
          [/sektionaltor elektrisch/,{material:2450,hours:7.0,labor:76}],
          [/sektionaltor manuell/,{material:1850,hours:6.0,labor:76}],
          [/garagentor|sektionaltor/,{material:1950,hours:6.0,labor:76}]
        ];return rules.find(([rx])=>rx.test(label))?.[1]||{material:100,hours:1,labor:72}};
        const indexComplete=p=>{const label=String(p.l||'').toLowerCase();const rules=[
          [/wellplatten|faserzement.*dach/ ,95],[/doppelsteg|polycarbonat/,120],[/dachziegel|falzziegel/,110],[/biberschwanz/,180],[/betondachstein/,95],[/schieferdeckung/,220],[/trapez|profilblech/,105],
          [/firstdeckung|firstziegel/,95],[/ortgang/,85],[/dachfenster komplett/,1450],[/lichtkuppel/,1600],
          [/attika/,110],[/dachrinne titanzink/,85],[/dachrinne kupfer/,145],[/fallrohr titanzink/,75],[/fallrohr kupfer/,120],
          [/fensterbank/,70],[/isolierverglasung 2/,280],[/isolierverglasung 3/,360],[/esg/,300],[/vsg/,350],
          [/raffstore|außenjalousie/,900],[/außenleuchte|lampe/,180],[/feuchtraum|wannenleuchte/,140],
          [/photovoltaikmodul/,320],[/pv-unterkonstruktion|dachhaken/,115],[/markise/,1800],[/terrassenüberdachung|vordach/,450],
          [/außenputz hagel/,65],[/außenputz größere/,125],[/wdvs.*oberputz|armierung/,95],[/wdvs.*komplett/,210],[/riss|schlagstelle/,45],
          [/faserzement.*fassade/,190],[/holzfassade|stülpschalung/,210],[/holzschindel/,280],[/metall|wellblechfassade/,175],
          [/fassade reinigen.*streichen/,38],[/fassade nur streichen/,29],[/holzfenster.*streichen/,220],[/holztür außen/,85],[/holztür innen/,75],[/metalltür|metallfläche/,70],[/holzfassade reinigen/,55]
        ];const hit=rules.find(([rx])=>rx.test(label));if(hit)return hit[1];const unit=String(p.u||'').toLowerCase();return unit.includes('m²')?150:unit==='m'?85:unit==='st'?350:150};
        const addIndex=(p,qty)=>{const ep=indexComplete(p);bridge()?.addLine?.({position_code:'INDEX 2026',description:`${p.l} – Index-Komplettpreis 2026 inkl. Demontage, Entsorgung und Neumontage; EP veränderbar`,quantity:qty,recommended_quantity:qty,unit:p.u,unit_price:ep,regional_factor:1});return ep};
        presets.forEach((p,i)=>{const e=document.createElement('div');e.className='bk-quick-item';e.dataset.cat=p.c;const estimate=indexDefaults(p),extra=p.mode==='material_labor'?`<div class=\"bk-quick-extra\"><label>Index-Material EP netto <input data-mat=\"${i}\" inputmode=\"decimal\" value=\"${estimate.material}\"></label><label>Arbeitszeit h/St <input data-hours=\"${i}\" inputmode=\"decimal\" value=\"${estimate.hours}\"></label><small>Indexansatz 2026, veränderbar; Arbeitslohn vorrangig nach BKI-Facharbeiterstundensatz.</small></div>`:'';e.innerHTML=`<label><input type=\"checkbox\" data-preset=\"${i}\"><span>${esc(p.l)}<small class=\"bk-quick-note\">inkl. Demontage, Entsorgung + Montage</small></span></label><span></span><input class=\"bk-quick-qty\" data-qty=\"${i}\" inputmode=\"decimal\" value=\"1\"><span class=\"bk-quick-unit\">${esc(p.u)}</span>${extra}`;const cb=e.querySelector('input[type=checkbox]');cb.onchange=()=>e.classList.toggle('checked',cb.checked);list.appendChild(e)});"""
text, count = pattern.subn(replacement, text, count=1)
if count == 0:
    old_render = '''presets.forEach((p,i)=>{const e=document.createElement('div');e.className='bk-quick-item';e.dataset.cat=p.c;e.innerHTML=`<label><input type="checkbox" data-preset="${i}"><span>${esc(p.l)}<small class="bk-quick-note">inkl. Demontage, Entsorgung + Montage</small></span></label><span></span><input class="bk-quick-qty" data-qty="${i}" inputmode="decimal" value="1"><span class="bk-quick-unit">${esc(p.u)}</span>`;const cb=e.querySelector('input[type=checkbox]');cb.onchange=()=>e.classList.toggle('checked',cb.checked);list.appendChild(e)});'''
    if old_render in text:
        text = text.replace(old_render, replacement, 1)
        count = 1
if count == 0 and 'data-mat="${i}"' not in text:
    raise SystemExit('quick item renderer anchor not found')

old = """const fullQuery=`STURM-/HAGEL-SCHNELLKALKULATION. Gesucht ist eine Komplett-Wiederherstellung für: ${p.q}. Gib NUR die notwendigen BKI-Teilleistungen für (1) Demontage/Ausbau und Entsorgung des beschädigten Altbauteils und (2) Lieferung/Einbau bzw. Montage der gleichartigen Wiederherstellung zurück. Keine Alternativpositionen, keine bloßen Varianten. Die Summe soll die vollständige Demontage + Entsorgung + Neumontage abbilden.`;
        try{
          const d=await api({query:fullQuery,quantity:String(qty),unit:p.u,location:document.getElementById('bk-location')?.value||'',case_meta:bridge()?.getMeta?.()||{}}), positions=Array.isArray(d.positions)?d.positions:[];
          if(!positions.length){failed.push(p.l);continue}
          const usable=positions.filter(x=>x&&Number.isFinite(choosePrice(x))&&choosePrice(x)>0);
          if(!usable.length){failed.push(p.l);continue}
          const ep=usable.reduce((s,x)=>s+choosePrice(x),0),rf=number(d.regional_factor)||number(usable.find(x=>number(x.regional_factor))?.regional_factor)||1,codes=usable.map(x=>x.position_code).filter(Boolean).join(' + ');
          bridge()?.addLine?.({position_code:codes||'BKI',description:`${p.l} – Wiederherstellung inkl. Demontage, Entsorgung und Neumontage`,quantity:qty,recommended_quantity:qty,unit:p.u,unit_price:ep,regional_factor:rf});added++;
        }catch(e){failed.push(p.l)}"""
new = r"""const norm=s=>String(s??'').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/ß/g,'ss');
        const positionText=x=>norm([x.description,x.short_text,x.long_text,x.title,x.position_name,x.service_description].filter(Boolean).join(' '));
        const unitNorm=u=>norm(u).replace(/²/g,'2').replace(/stuck|stück|stk\.?/g,'st').replace(/lfdm|lfd\. ?m/g,'m').replace(/std\.?|stunden?/g,'h').replace(/\s/g,'');
        const termRules=[
          [/photovoltaikmodul/i,/photovolta|pv.?modul|solarmodul/], [/markise/i,/markis|gelenkarm/], [/terrassenüberdachung/i,/terrassenuber|vordach|dachplatte/],
          [/lichtkuppel/i,/lichtkuppel|oberlicht/], [/isolierverglasung 3/i,/isolierglas|3.?fach|dreifach/], [/isolierverglasung 2/i,/isolierglas|2.?fach|zweifach/],
          [/fensterbank/i,/fensterbank/], [/raffstore/i,/raffstore|aussenjalous/], [/rollladenpanzer aluminium/i,/rollladenpanzer.*(alu|aluminium)/],
          [/rollladenpanzer pvc/i,/rollladenpanzer.*(pvc|kunststoff)/], [/vorbaurollladen, panzer alu/i,/vorbaurollladen.*(alu|aluminium)/],
          [/vorbaurollladen, panzer pvc/i,/vorbaurollladen.*(pvc|kunststoff)/], [/dachfensterrollladen/i,/dachfenster.*rollladen|rollladen.*dachfenster/],
          [/eindeckrahmen/i,/eindeckrahmen/], [/dachfenster komplett/i,/dachfenster|dachflachenfenster|wohndachfenster/], [/sektionaltor|garagentor/i,/sektionaltor|garagentor|schwingtor/],
          [/faserzement/i,/faserzement|eternit/], [/holzfassade|stulpschalung/i,/holzfassade|holzbekleidung|stulpschalung/], [/holzschindel/i,/holzschindel/],
          [/metall-.wellblechfassade/i,/metallfassade|wellblechfassade|profilblechfassade/], [/aussenputz/i,/aussenputz|fassadenputz/], [/wdvs/i,/wdvs|warmedammverbund/],
          [/holzfenster/i,/holzfenster/], [/holztur/i,/holztur|tur.*holz/], [/metalltur/i,/metalltur|stahltur/], [/fassade.*streichen|fassade reinigen/i,/fassadenfarbe|fassadenbeschichtung|fassadenflache/],
          [/wellplatten/i,/wellplatte|faserzement/], [/doppelsteg/i,/doppelsteg|polycarbonat|hohlkammer/], [/dachziegel/i,/dachziegel|falzziegel/], [/biberschwanz/i,/biberschwanz/],
          [/betondachstein/i,/betondachstein/], [/schiefer/i,/schiefer/], [/trapez/i,/trapez|profilblech/], [/first/i,/firstziegel|firstdeckung/], [/ortgang/i,/ortgang/],
          [/attika/i,/attika|mauerabdeckung/], [/dachrinne/i,/dachrinne/], [/fallrohr/i,/fallrohr|regenfallrohr/], [/esg/i,/esg|einscheibensicherheitsglas/], [/vsg/i,/vsg|verbundsicherheitsglas/],
          [/leuchte|lampe/i,/leuchte|lampe/], [/pv-unterkonstruktion/i,/pv.*unterkonstruktion|dachhaken|montageschiene/]
        ];
        const requestedRule=termRules.find(([label])=>label.test(norm(p.l)))?.[1];
        const explicitCodes=[...String(p.q).matchAll(/\b\d{3}\.\d{3}\.\d{3}\b/g)].map(m=>m[0]);
        const semanticMatch=x=>{const txt=positionText(x),code=String(x.position_code||'');return (explicitCodes.includes(code)||(!requestedRule||requestedRule.test(txt)))&&unitNorm(x.unit)===unitNorm(p.u)};
        const phase=x=>{const txt=positionText(x);if(/abbruch|demont|ausbau|entfern|entsorg|aufnehmen/.test(txt))return'remove';if(/liefer|einbau|montage|erneu|herstell|einsetzen|beschicht|streichen|ausbessern/.test(txt))return'install';return'other'};
        const best=a=>a.sort((x,y)=>((explicitCodes.includes(String(y.position_code||''))?20:0)+(requestedRule?.test(positionText(y))?5:0))-((explicitCodes.includes(String(x.position_code||''))?20:0)+(requestedRule?.test(positionText(x))?5:0)))[0];
        try{
          if(p.mode==='material_labor'){
            const material=number(list.querySelector(`[data-mat=\"${idx}\"]`)?.value),hours=number(list.querySelector(`[data-hours=\"${idx}\"]`)?.value);
            if(material<=0||hours<=0){failed.push(`${p.l} (Materialpreis/Arbeitszeit fehlt)`);continue}
            const laborQuery=`BKI Altbau 2026: Ermittle ausschließlich den Netto-Stundensatz Facharbeiter für das fachlich passende Gewerk zur Montage von ${p.q}. Gib nur die eine Stundensatzposition zurück, keine Produkt-, Fenster-, Rollladen- oder Komplettposition.`;
            let d={positions:[]};try{d=await api({query:laborQuery,quantity:String(hours),unit:'h',location:document.getElementById('bk-location')?.value||'',case_meta:bridge()?.getMeta?.()||{}})}catch{}
            const positions=Array.isArray(d.positions)?d.positions:[],labor=positions.find(x=>/stundensatz|facharbeiter/i.test(positionText(x))&&unitNorm(x.unit)==='h'),laborRate=labor&&choosePrice(labor)>0?choosePrice(labor):indexDefaults(p).labor;
            const rf=number(d.regional_factor)||1,ep=material+(hours*laborRate),basis=labor?'Index-Material + BKI-Lohn':'Index 2026';
            bridge()?.addLine?.({position_code:(labor?.position_code?`INDEX + ${labor.position_code}`:'INDEX 2026'),description:`${p.l} – ${basis}, Material ${material.toFixed(2)} EUR zzgl. ${hours} h × ${laborRate.toFixed(2)} EUR; veränderbar; Demontage und Montage enthalten`,quantity:qty,recommended_quantity:qty,unit:p.u,unit_price:ep,regional_factor:rf});added++;continue
          }
          const fullQuery=`STURM-/HAGEL-SCHNELLKALKULATION. Gesucht ist eine Komplett-Wiederherstellung für: ${p.q}. Gib NUR die notwendigen BKI-Teilleistungen für (1) Demontage/Ausbau und Entsorgung des beschädigten Altbauteils und (2) Lieferung/Einbau bzw. Montage der gleichartigen Wiederherstellung zurück. Keine Alternativpositionen, keine bloßen Varianten. Die Summe soll die vollständige Demontage + Entsorgung + Neumontage abbilden.`;
          let d=await api({query:fullQuery,quantity:String(qty),unit:p.u,location:document.getElementById('bk-location')?.value||'',case_meta:bridge()?.getMeta?.()||{}}),positions=Array.isArray(d.positions)?d.positions:[];
          if(!positions.length){d=await api({query:p.q,quantity:String(qty),unit:p.u,location:document.getElementById('bk-location')?.value||'',case_meta:bridge()?.getMeta?.()||{}});positions=Array.isArray(d.positions)?d.positions:[]}
          if(!positions.length){addIndex(p,qty);added++;continue}
          const label=norm(p.l),cap=/photovoltaikmodul/.test(label)?1500:/pv-unterkonstruktion|dachhaken/.test(label)?750:/leuchte|lampe/.test(label)?1500:unitNorm(p.u)==='st'?6000:unitNorm(p.u)==='m2'?1500:1000;
          const positive=positions.filter(x=>x&&Number.isFinite(choosePrice(x))&&choosePrice(x)>0),plausible=positive.filter(x=>unitNorm(x.unit)===unitNorm(p.u)&&choosePrice(x)<=cap),matched=plausible.filter(x=>semanticMatch(x));
          const explicit=best(matched.filter(x=>explicitCodes.includes(String(x.position_code||''))&&phase(x)==='install'));
          const complete=best(matched.filter(x=>/(abbruch|demont|ausbau|entfern|entsorg|aufnehmen)/.test(positionText(x))&&/(liefer|einbau|montage|erneu|herstell|einsetzen)/.test(positionText(x))));
          const remove=best(matched.filter(x=>phase(x)==='remove')),install=best(matched.filter(x=>phase(x)==='install'));
          const usable=explicit?[explicit]:remove&&install?[remove,install]:complete?[complete]:matched.length?[best(matched)]:[];
          if(!usable.length){addIndex(p,qty);added++;continue}
          const ep=usable.reduce((s,x)=>s+choosePrice(x),0),rf=number(d.regional_factor)||number(usable.find(x=>number(x.regional_factor))?.regional_factor)||1,codes=usable.map(x=>x.position_code).filter(Boolean).join(' + ');
          bridge()?.addLine?.({position_code:codes||'BKI',description:`${p.l} – Wiederherstellung inkl. Demontage, Entsorgung und Neumontage`,quantity:qty,recommended_quantity:qty,unit:p.u,unit_price:ep,regional_factor:rf});added++;
        }catch(e){failed.push(p.l)}"""
if old in text:
    text = text.replace(old, new, 1)
else:
    print('pricing block already changed or exact block not found')

path.write_text(text, encoding='utf-8')
print('BKI storm accessory pricing corrected')
