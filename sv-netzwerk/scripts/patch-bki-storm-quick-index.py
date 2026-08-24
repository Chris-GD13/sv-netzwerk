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
