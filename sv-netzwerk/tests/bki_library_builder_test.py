import importlib.util, json, tempfile
from pathlib import Path
spec=importlib.util.spec_from_file_location('builder',Path(__file__).parent.parent/'scripts/build-bki-library.py');module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
with tempfile.TemporaryDirectory() as directory:
    # Synthetic rows test category switches, footer continuation and VAT conversion.
    text='inkl. 19% MwSt.\nKG.OZ Herstellen\n04 Beispiel alt\n119,00 238,00 357,00\nBeschreibung\nEinheit: m Länge\n311.30 Neue Kategorie\n04 Beispiel neu\n59,50 119,00 178,50\nBeschreibung\nEinheit: St\n311.20 Vorige Kategorie'
    document={'name':'BKI_Gebaeude_Altbau_fixture.pdf','sha256':'a'*64,'size':100,'pages':[{'page':1,'text':text}]}
    Path(directory,'fixture.json').write_text(json.dumps(document),encoding='utf8');data=module.build(directory)
    positions=data['positions'];assert len(positions)==2;assert positions[0]['position_code']=='311.20/04';assert positions[1]['position_code']=='311.30/04';assert positions[0]['price_mid']==200;assert positions[1]['price_mid']==100
print('Original table categories and gross-to-net conversion passed.')
