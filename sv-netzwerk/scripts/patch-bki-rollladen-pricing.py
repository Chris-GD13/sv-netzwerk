from pathlib import Path

path = Path(__file__).resolve().parents[1] / 'src/pages/intern/kalkulation/index.astro'
text = path.read_text(encoding='utf-8')

if 'data-bki-storm-index="1"' not in text:
    raise SystemExit('storm quick index not found')

# Die gemeinsame Material-/Lohn-Oberfläche und Berechnung wird bereits durch
# patch-bki-storm-pricing.py eingebaut. Dieser Patch kennzeichnet deshalb nur
# die vier Rollladenvarianten, für die keine exakt passende Komplettposition
# verwendet werden darf. PVC und Aluminium bleiben getrennt bepreist.
replacements = {
    "{c:'Rollladen',l:'Rollladenpanzer Aluminium',u:'m²',q:'Rollladenpanzer Aluminium erneuern vorhandener Rollladen'}": "{c:'Rollladen',l:'Rollladenpanzer Aluminium',u:'m²',q:'Rollladenpanzer Aluminium erneuern vorhandener Rollladen',mode:'material_labor'}",
    "{c:'Rollladen',l:'Rollladenpanzer PVC',u:'m²',q:'Rollladenpanzer Kunststoff PVC erneuern vorhandener Rollladen'}": "{c:'Rollladen',l:'Rollladenpanzer PVC',u:'m²',q:'Rollladenpanzer Kunststoff PVC erneuern vorhandener Rollladen',mode:'material_labor'}",
    "{c:'Rollladen',l:'Vorbaurollladen, Panzer Alu',u:'St',q:'Vorbaurollladen komplett Aluminium Rollladenpanzer Aluminium'}": "{c:'Rollladen',l:'Vorbaurollladen, Panzer Alu',u:'St',q:'Vorbaurollladen komplett Aluminium Rollladenpanzer Aluminium',mode:'material_labor'}",
    "{c:'Rollladen',l:'Vorbaurollladen, Panzer PVC',u:'St',q:'Vorbaurollladen komplett Rollladenpanzer Kunststoff PVC'}": "{c:'Rollladen',l:'Vorbaurollladen, Panzer PVC',u:'St',q:'Vorbaurollladen komplett Rollladenpanzer Kunststoff PVC',mode:'material_labor'}",
}

changed = 0
for old, new in replacements.items():
    if old in text:
        text = text.replace(old, new, 1)
        changed += 1
    elif new not in text:
        raise SystemExit('Rollladen-Preset nicht gefunden: ' + old)

required = ["l:'Rollladenpanzer Aluminium'", "l:'Rollladenpanzer PVC'", "l:'Vorbaurollladen, Panzer Alu'", "l:'Vorbaurollladen, Panzer PVC'"]
for label in required:
    start = text.find(label)
    end = text.find('}', start)
    if start < 0 or end < 0 or "mode:'material_labor'" not in text[start:end]:
        raise SystemExit('Material-/Lohn-Modus fehlt für ' + label)

path.write_text(text, encoding='utf-8')
print(f'BKI Rollladen pricing active ({changed} Presets aktualisiert)')
