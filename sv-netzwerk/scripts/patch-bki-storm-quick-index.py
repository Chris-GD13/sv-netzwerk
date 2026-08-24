from pathlib import Path

path = Path(__file__).resolve().parents[1] / 'src/pages/intern/kalkulation/index.astro'
text = path.read_text(encoding='utf-8')

MARKER = 'data-bki-storm-index="1"'
if MARKER in text:
    print('BKI storm quick index already present')
    raise SystemExit(0)

anchor = '    <section id="bk-results-card" class="bk-card" hidden><h2>Gefundene BKI-Positionen</h2><div id="bk-results"></div></section>'
quick = '''    <section class="bk-card bk-quick" data-bki-storm-index="1">
      <div class="bk-title-row bk-quick-head"><div><p class="bk-kicker">Sturm / Hagel</p><h2>Schnellkalkulation typische Schäden</h2><p>Bauteile anhaken, Menge eintragen und gemeinsam kalkulieren. Die Schnellposition wird als Wiederherstellung inklusive Demontage, Entsorgung und Neumontage angesetzt.</p></div><button id="bk-quick-clear" class="bk-secondary" type="button">Auswahl löschen</button></div>
      <div id="bk-quick-cats" class="bk-quick-cats"></div>
      <div id="bk-quick-list" class="bk-quick-list"></div>
      <div class="bk-actions"><button id="bk-quick-calc" class="bk-primary" type="button">Auswahl kalkulieren</button><span id="bk-quick-state"></span></div>
    </section>
'''
if anchor not in text:
    raise SystemExit('results anchor not found')
text = text.replace(anchor, quick + anchor, 1)

css_anchor = '@media print{.bk-back{display:none!important}}\n</style>'
css = '''@media print{.bk-back{display:none!important}}\n.bk-quick{border-radius:3px;padding:14px}.bk-quick-head{align-items:flex-start}.bk-quick-head h2{margin:.1rem 0}.bk-quick-head p{margin:.2rem 0 0;color:var(--bkm);max-width:920px}.bk-quick-cats{display:flex;flex-wrap:wrap;gap:5px;margin:10px 0}.bk-quick-cat{min-height:32px;border:1px solid #bdcbd6;background:#f7fafc;color:var(--bki);border-radius:3px;padding:5px 9px;font-size:.78rem;line-height:1.1;font-weight:800;cursor:pointer}.bk-quick-cat:hover{border-color:var(--bko);background:#fff8ee}.bk-quick-cat.on{background:var(--bki);color:#fff;border-color:var(--bki);box-shadow:inset 3px 0 0 var(--bko)}.bk-quick-list{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:6px}.bk-quick-item{display:grid;grid-template-columns:auto 1fr 78px 44px;align-items:center;gap:7px;border:1px solid var(--bkl);border-radius:3px;padding:7px 8px;background:#fbfdfe}.bk-quick-item.off{display:none}.bk-quick-item>label{display:flex;align-items:center;gap:7px;font-size:.82rem;font-weight:750;min-width:0}.bk-quick-item input[type=checkbox]{width:16px;height:16px;flex:0 0 auto}.bk-quick-qty{width:100%;box-sizing:border-box;border:1px solid #bdcbd6;border-radius:3px;padding:5px;text-align:right}.bk-quick-unit{font-size:.78rem;font-weight:800;color:#5c7182}.bk-quick-item.checked{border-color:#ff970f;background:#fff8ee}.bk-quick-note{display:block;font-size:.68rem;color:#718493;font-weight:500;margin-top:1px}.bk-quick-state-error{color:#a82929}.bk-quick-state-ok{color:#236e50}@media(max-width:1050px){.bk-quick-list{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:700px){.bk-quick{padding:10px}.bk-quick-list{grid-template-columns:1fr}.bk-quick-item{grid-template-columns:auto 1fr 70px 38px}}@media print{.bk-quick{display:none!important}}\n</style>'''
if css_anchor in text:
    text = text.replace(css_anchor, css, 1)
elif '</style>' in text:
    # Weitere Portal-Funktionen dürfen vor dem Style-Ende eigenes CSS ergänzen.
    # In diesem Fall die Schnellkalkulationsregeln unmittelbar vor dem ersten
    # Style-Ende einfügen, statt von der vorherigen letzten Regel abhängig zu sein.
    quick_css = css.split('\n', 1)[1]
    text = text.replace('</style>', quick_css, 1)
else:
    raise SystemExit('style closing tag not found')

script = r'''
<script is:inline>
(()=>{
  const root=document.querySelector('[data-bki-storm-index="1"]');
  if(!root)return;
  const bridge=()=>window.__bkiCalcBridge;
  const API='/intern/api/bki-calculator.php';
  const presets=[
    {c:'Dachdeckung',l:'Wellplatten / Faserzement',u:'m²',q:'Dachdeckung Faserzement Wellplatten auf vorhandener Unterkonstruktion'},
    {c:'Dachdeckung',l:'Doppelstegplatten / Polycarbonat',u:'m²',q:'Polycarbonat Doppelstegplatten Hohlkammerplatten Dachdeckung. Im BKI keine eigene PC-Stegplattenposition; als konstruktiven Vergleich für die Neumontage LB 320 Dachdeckung Wellplatte Pos. 86 / 320.001.189 prüfen.'},
    {c:'Dachdeckung',l:'Dachziegel / Falzziegel',u:'m²',q:'Dachdeckung Falzziegel Ton auf vorhandener Lattung'},
    {c:'Dachdeckung',l:'Biberschwanzziegel',u:'m²',q:'Dachdeckung Biberschwanz Flachziegel Ton Doppeldeckung auf vorhandener Lattung'},
    {c:'Dachdeckung',l:'Betondachsteine',u:'m²',q:'Dachdeckung Betondachsteine auf vorhandener Lattung'},
    {c:'Dachdeckung',l:'Schieferdeckung',u:'m²',q:'Dachdeckung Schiefer auf vorhandener Schalung'},
    {c:'Dachdeckung',l:'Trapez-/Profilblech',u:'m²',q:'Dachdeckung Profilblech Trapezblech Metall auf vorhandener Unterkonstruktion'},
    {c:'Dachdetails',l:'Firstdeckung / Firstziegel',u:'m',q:'Firstdeckung Firstanschluss Ziegel Formziegel'},
    {c:'Dachdetails',l:'Ortgang / Ortgangziegel',u:'m',q:'Ortgang Dachdeckung Formziegel Dachsteine'},
    {c:'Dachdetails',l:'Eindeckrahmen Velux',u:'St',q:'Eindeckrahmen Dachfenster VELUX passend zu vorhandener Ziegel Dachdeckung'},
    {c:'Dachdetails',l:'Eindeckrahmen Roto',u:'St',q:'Eindeckrahmen Dachfenster ROTO passend zu vorhandener Ziegel Dachdeckung'},
    {c:'Dachdetails',l:'Dachfenster komplett',u:'St',q:'Wohndachfenster Dachflächenfenster einschließlich Eindeckrahmen'},
    {c:'Dachdetails',l:'Lichtkuppel',u:'St',q:'Lichtkuppel Dach Oberlicht Kunststoff inklusive Anschluss'},
    {c:'Dachdetails',l:'Dachfensterrollladen manuell',u:'St',q:'Dachfenster Rollladen außen manuell mechanisch'},
    {c:'Dachdetails',l:'Dachfensterrollladen Solar',u:'St',q:'Dachfenster Rollladen außen solar elektrisch'},
    {c:'Klempner',l:'Attika-Abdeckung / Verblechung',u:'m',q:'Attika Abdeckung Mauerabdeckung Blech Titanzink Aluminium'},
    {c:'Klempner',l:'Dachrinne Titanzink',u:'m',q:'Dachrinne halbrund Titanzink inklusive Rinnenhalter'},
    {c:'Klempner',l:'Dachrinne Kupfer',u:'m',q:'Dachrinne halbrund Kupfer inklusive Rinnenhalter'},
    {c:'Klempner',l:'Fallrohr Titanzink',u:'m',q:'Regenfallrohr Titanzink inklusive Rohrschellen'},
    {c:'Klempner',l:'Fallrohr Kupfer',u:'m',q:'Regenfallrohr Kupfer inklusive Rohrschellen'},
    {c:'Fenster',l:'Fensterbank Aluminium',u:'m',q:'Außenfensterbank Aluminium Leichtmetall inklusive Endstücke und Befestigung'},
    {c:'Fenster',l:'Isolierverglasung 2-fach',u:'m²',q:'Isolierverglasung zweifach Fenster erneuern'},
    {c:'Fenster',l:'Isolierverglasung 3-fach',u:'m²',q:'Isolierverglasung dreifach Fenster erneuern'},
    {c:'Fenster',l:'ESG-Scheibe',u:'m²',q:'Verglasung Einscheibensicherheitsglas ESG erneuern'},
    {c:'Fenster',l:'VSG-Scheibe',u:'m²',q:'Verglasung Verbundsicherheitsglas VSG erneuern'},
    {c:'Rollladen',l:'Rollladenpanzer Aluminium',u:'m²',q:'Rollladenpanzer Aluminium erneuern vorhandener Rollladen'},
    {c:'Rollladen',l:'Rollladenpanzer PVC',u:'m²',q:'Rollladenpanzer Kunststoff PVC erneuern vorhandener Rollladen'},
    {c:'Rollladen',l:'Vorbaurollladen, Panzer Alu',u:'St',q:'Vorbaurollladen komplett Aluminium Rollladenpanzer Aluminium'},
    {c:'Rollladen',l:'Vorbaurollladen, Panzer PVC',u:'St',q:'Vorbaurollladen komplett Rollladenpanzer Kunststoff PVC'},
    {c:'Rollladen',l:'Raffstore / Außenjalousie',u:'m²',q:'Außenjalousie Raffstore Lamellen Aluminium'},
    {c:'Elektro',l:'Außenleuchte / Lampe',u:'St',q:'Außenleuchte Leuchte komplett einschließlich Befestigung und elektrischem Anschluss'},
    {c:'Elektro',l:'Feuchtraum-/Wannenleuchte',u:'St',q:'Wannenleuchte LED Feuchtraum Polycarbonat'},
    {c:'PV',l:'Photovoltaikmodul',u:'St',q:'Photovoltaik Solarmodul PV Modul Dach austauschen'},
    {c:'PV',l:'PV-Unterkonstruktion / Dachhaken',u:'St',q:'Photovoltaik Unterkonstruktion Dachhaken Schiene Dach'},
    {c:'Sonstiges',l:'Markise',u:'St',q:'Markise außen Gelenkarmmarkise komplett'},
    {c:'Sonstiges',l:'Terrassenüberdachung / Vordach',u:'m²',q:'Vordach Terrassenüberdachung Dachplatten Kunststoff Glas'},
    {c:'Sonstiges',l:'Sektionaltor / Garagentor',u:'St',q:'Sektionaltor Garagentor außen komplett'}
  ];
  const cats=['Alle',...new Set(presets.map(x=>x.c))], catBox=document.getElementById('bk-quick-cats'),list=document.getElementById('bk-quick-list'),state=document.getElementById('bk-quick-state');
  function esc(s){return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}
  cats.forEach((c,i)=>{const b=document.createElement('button');b.type='button';b.className='bk-quick-cat'+(i===0?' on':'');b.textContent=c;b.dataset.cat=c;b.onclick=()=>{catBox.querySelectorAll('.bk-quick-cat').forEach(x=>x.classList.toggle('on',x===b));list.querySelectorAll('.bk-quick-item').forEach(x=>x.classList.toggle('off',c!=='Alle'&&x.dataset.cat!==c));};catBox.appendChild(b)});
  presets.forEach((p,i)=>{const e=document.createElement('div');e.className='bk-quick-item';e.dataset.cat=p.c;e.innerHTML=`<label><input type="checkbox" data-preset="${i}"><span>${esc(p.l)}<small class="bk-quick-note">inkl. Demontage, Entsorgung + Montage</small></span></label><span></span><input class="bk-quick-qty" data-qty="${i}" inputmode="decimal" value="1"><span class="bk-quick-unit">${esc(p.u)}</span>`;const cb=e.querySelector('input[type=checkbox]');cb.onchange=()=>e.classList.toggle('checked',cb.checked);list.appendChild(e)});
  document.getElementById('bk-quick-clear').onclick=()=>{list.querySelectorAll('input[type=checkbox]').forEach(x=>x.checked=false);list.querySelectorAll('.bk-quick-item').forEach(x=>x.classList.remove('checked'));state.textContent='';};
  const number=v=>{const n=Number(String(v??'').replace(',','.'));return Number.isFinite(n)?n:0};
  const choosePrice=p=>{const level=document.getElementById('bk-level')?.value||'mid';return number(level==='low'?p.price_low:level==='high'?p.price_high:p.price_mid)};
  async function api(payload){const r=await fetch(API+'?action=search',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)}),j=await r.json().catch(()=>({}));if(!r.ok)throw new Error(j.error||`HTTP ${r.status}`);return j}
  document.getElementById('bk-quick-calc').onclick=async()=>{
    const selected=[...list.querySelectorAll('input[type=checkbox]:checked')];if(!selected.length){state.textContent='Bitte mindestens ein Bauteil anhaken.';state.className='bk-quick-state-error';return}
    const btn=document.getElementById('bk-quick-calc');btn.disabled=true;let added=0,failed=[];state.className='';
    try{
      for(let n=0;n<selected.length;n++){
        const idx=+selected[n].dataset.preset,p=presets[idx],qty=number(list.querySelector(`[data-qty="${idx}"]`)?.value)||1;
        state.textContent=`${n+1}/${selected.length}: ${p.l} wird im BKI ermittelt …`;
        const fullQuery=`STURM-/HAGEL-SCHNELLKALKULATION. Gesucht ist eine Komplett-Wiederherstellung für: ${p.q}. Gib NUR die notwendigen BKI-Teilleistungen für (1) Demontage/Ausbau und Entsorgung des beschädigten Altbauteils und (2) Lieferung/Einbau bzw. Montage der gleichartigen Wiederherstellung zurück. Keine Alternativpositionen, keine bloßen Varianten. Die Summe soll die vollständige Demontage + Entsorgung + Neumontage abbilden.`;
        try{
          const d=await api({query:fullQuery,quantity:String(qty),unit:p.u,location:document.getElementById('bk-location')?.value||'',case_meta:bridge()?.getMeta?.()||{}}), positions=Array.isArray(d.positions)?d.positions:[];
          if(!positions.length){failed.push(p.l);continue}
          const usable=positions.filter(x=>x&&Number.isFinite(choosePrice(x))&&choosePrice(x)>0);
          if(!usable.length){failed.push(p.l);continue}
          const ep=usable.reduce((s,x)=>s+choosePrice(x),0),rf=number(d.regional_factor)||number(usable.find(x=>number(x.regional_factor))?.regional_factor)||1,codes=usable.map(x=>x.position_code).filter(Boolean).join(' + ');
          bridge()?.addLine?.({position_code:codes||'BKI',description:`${p.l} – Wiederherstellung inkl. Demontage, Entsorgung und Neumontage`,quantity:qty,recommended_quantity:qty,unit:p.u,unit_price:ep,regional_factor:rf});added++;
        }catch(e){failed.push(p.l)}
      }
      state.className=added?'bk-quick-state-ok':'bk-quick-state-error';state.textContent=added?`${added} Schnellposition(en) übernommen.${failed.length?` Ohne belastbaren Treffer: ${failed.join(', ')}.`:''}`:`Keine belastbaren BKI-Treffer. ${failed.join(', ')}`;
    }finally{btn.disabled=false}
  };
})();
</script>
'''
text = text + '\n' + script
path.write_text(text, encoding='utf-8')
print('BKI storm quick index patched')
