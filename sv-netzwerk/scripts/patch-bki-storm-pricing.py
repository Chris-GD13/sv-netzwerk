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

pattern = re.compile(r"presets\.forEach\(\(p,i\)=>\{const e=document\.createElement\('div'\);e\.className='bk-quick-item';e\.dataset\.cat=p\.c;e\.innerHTML=`<label><input type=\\\"checkbox\\\" data-preset=\\\"\$\{i\}\\\"><span>\$\{esc\(p\.l\)\}<small class=\\\"bk-quick-note\\\">inkl\. Demontage, Entsorgung \+ Montage</small></span></label><span></span><input class=\\\"bk-quick-qty\\\" data-qty=\\\"\$\{i\}\\\" inputmode=\\\"decimal\\\" value=\\\"1\\\"><span class=\\\"bk-quick-unit\\\">\$\{esc\(p\.u\)\}</span>`;const cb=e\.querySelector\('input\[type=checkbox\]'\);cb\.onchange=\(\)=>e\.classList\.toggle\('checked',cb\.checked\);list\.appendChild\(e\)\}\);")
replacement = """presets.forEach((p,i)=>{const e=document.createElement('div');e.className='bk-quick-item';e.dataset.cat=p.c;const extra=p.mode==='material_labor'?`<div class=\"bk-quick-extra\"><label>Material EP netto <input data-mat=\"${i}\" inputmode=\"decimal\" placeholder=\"0,00\"></label><label>Arbeitszeit h/St <input data-hours=\"${i}\" inputmode=\"decimal\" placeholder=\"z. B. 1,5\"></label><small>Materialpreis nach Beleg/Marktpreis; Arbeitslohn nach BKI-Facharbeiterstundensatz. Kein kompletter Dachfensterpreis.</small></div>`:'';e.innerHTML=`<label><input type=\"checkbox\" data-preset=\"${i}\"><span>${esc(p.l)}<small class=\"bk-quick-note\">inkl. Demontage, Entsorgung + Montage</small></span></label><span></span><input class=\"bk-quick-qty\" data-qty=\"${i}\" inputmode=\"decimal\" value=\"1\"><span class=\"bk-quick-unit\">${esc(p.u)}</span>${extra}`;const cb=e.querySelector('input[type=checkbox]');cb.onchange=()=>e.classList.toggle('checked',cb.checked);list.appendChild(e)});"""
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
            const d=await api({query:laborQuery,quantity:String(hours),unit:'h',location:document.getElementById('bk-location')?.value||'',case_meta:bridge()?.getMeta?.()||{}}),positions=Array.isArray(d.positions)?d.positions:[],labor=positions.find(x=>/stundensatz|facharbeiter/i.test(positionText(x))&&unitNorm(x.unit)==='h');
            if(!labor||choosePrice(labor)<=0){failed.push(`${p.l} (BKI-Arbeitslohn fehlt)`);continue}
            const rf=number(d.regional_factor)||1,ep=material+(hours*choosePrice(labor));
            bridge()?.addLine?.({position_code:(labor.position_code?`Material + ${labor.position_code}`:'Material + BKI-Lohn'),description:`${p.l} – Material zzgl. ${hours} h Arbeitslohn; Demontage und Montage enthalten`,quantity:qty,recommended_quantity:qty,unit:p.u,unit_price:ep,regional_factor:rf});added++;continue
          }
          const fullQuery=`STURM-/HAGEL-SCHNELLKALKULATION. Gesucht ist eine Komplett-Wiederherstellung für: ${p.q}. Gib NUR die notwendigen BKI-Teilleistungen für (1) Demontage/Ausbau und Entsorgung des beschädigten Altbauteils und (2) Lieferung/Einbau bzw. Montage der gleichartigen Wiederherstellung zurück. Keine Alternativpositionen, keine bloßen Varianten. Die Summe soll die vollständige Demontage + Entsorgung + Neumontage abbilden.`;
          const d=await api({query:fullQuery,quantity:String(qty),unit:p.u,location:document.getElementById('bk-location')?.value||'',case_meta:bridge()?.getMeta?.()||{}}),positions=Array.isArray(d.positions)?d.positions:[];
          if(!positions.length){failed.push(p.l);continue}
          const matched=positions.filter(x=>x&&Number.isFinite(choosePrice(x))&&choosePrice(x)>0&&semanticMatch(x));
          const complete=best(matched.filter(x=>/(abbruch|demont|ausbau|entfern|entsorg|aufnehmen)/.test(positionText(x))&&/(liefer|einbau|montage|erneu|herstell|einsetzen)/.test(positionText(x))));
          const remove=best(matched.filter(x=>phase(x)==='remove')),install=best(matched.filter(x=>phase(x)==='install'));
          const usable=remove&&install?[remove,install]:complete?[complete]:[];
          if(!usable.length){failed.push(p.l);continue}
          const ep=usable.reduce((s,x)=>s+choosePrice(x),0),rf=number(d.regional_factor)||number(usable.find(x=>number(x.regional_factor))?.regional_factor)||1,codes=usable.map(x=>x.position_code).filter(Boolean).join(' + ');
          bridge()?.addLine?.({position_code:codes||'BKI',description:`${p.l} – Wiederherstellung inkl. Demontage, Entsorgung und Neumontage`,quantity:qty,recommended_quantity:qty,unit:p.u,unit_price:ep,regional_factor:rf});added++;
        }catch(e){failed.push(p.l)}"""
if old in text:
    text = text.replace(old, new, 1)
else:
    print('pricing block already changed or exact block not found')

path.write_text(text, encoding='utf-8')
print('BKI storm accessory pricing corrected')
