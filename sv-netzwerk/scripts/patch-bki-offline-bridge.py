from pathlib import Path

root = Path(__file__).resolve().parents[1]
page_path = root / 'src/pages/intern/kalkulation/index.astro'
page = page_path.read_text(encoding='utf-8')
original = page

needle = "  let active=null, lines=[];"
bridge = """  let active=null, lines=[];\n  window.__bkiCalcBridge={\n    getLines:()=>JSON.parse(JSON.stringify(lines)),\n    setLines:(value)=>{lines=Array.isArray(value)?JSON.parse(JSON.stringify(value)):[];renderLines()},\n    addLine:(value)=>addLine(value||{}),\n    getMeta:()=>meta,\n    getActive:()=>active\n  };"""
if needle in page and 'window.__bkiCalcBridge' not in page:
    page = page.replace(needle, bridge, 1)
elif "let active=null,lines=[];" in page and 'window.__bkiCalcBridge' not in page:
    compact_bridge = "let active=null,lines=[];window.__bkiCalcBridge={getLines:()=>JSON.parse(JSON.stringify(lines)),setLines:value=>{lines=Array.isArray(value)?JSON.parse(JSON.stringify(value)):[];render()},addLine:value=>add(value||{}),getMeta:()=>meta,getActive:()=>active};"
    page = page.replace("let active=null,lines=[];", compact_bridge, 1)
elif 'window.__bkiCalcBridge' not in page:
    raise SystemExit('BKI-Kalkulationszustand konnte nicht gefunden werden')

if page != original:
    page_path.write_text(page, encoding='utf-8')
    print('BKI-Offline-Bridge aktiviert.')
else:
    print('BKI-Offline-Bridge bereits aktiv.')
