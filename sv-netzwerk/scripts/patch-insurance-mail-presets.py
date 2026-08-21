from pathlib import Path

path = Path(__file__).resolve().parents[1] / 'src/pages/intern/versicherungsfaelle/index.astro'
source = path.read_text(encoding='utf-8')
updated = source

old_to = '<label>An<input id="vf-mail-to" type="email" placeholder="Empfänger"></label>'
new_to = '''<label>An<div class="vf-mail-recipient-row"><input id="vf-mail-to" type="email" placeholder="Empfänger"><select id="vf-mail-preset" aria-label="Standardempfänger auswählen"><option value="">Standardempfänger</option><option value="service.schaden@sparkassenversicherung.de">Sparkassenversicherung – Schadenservice</option></select></div></label>'''
if old_to in updated:
    updated = updated.replace(old_to, new_to, 1)
elif 'id="vf-mail-preset"' not in updated:
    raise SystemExit('E-Mail-Empfängerfeld nicht gefunden')

css_anchor = '.vf-mail-grid input,.vf-mail-grid textarea{width:100%;box-sizing:border-box;border:1px solid #bdcbd6;border-radius:9px;padding:10px 11px;font:inherit;margin-top:4px}'
css_add = '.vf-mail-recipient-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:7px;align-items:end}.vf-mail-recipient-row select{box-sizing:border-box;border:1px solid #bdcbd6;border-radius:9px;padding:10px 11px;font:inherit;margin-top:4px;background:#fff;color:#17324a;max-width:260px}'
if css_add not in updated:
    if css_anchor not in updated:
        raise SystemExit('E-Mail-CSS-Anker nicht gefunden')
    updated = updated.replace(css_anchor, css_anchor + css_add, 1)

mobile_anchor = '@media(max-width:760px){.vf-calendar-grid,.vf-mail-grid{grid-template-columns:1fr}'
mobile_new = '@media(max-width:760px){.vf-calendar-grid,.vf-mail-grid{grid-template-columns:1fr}.vf-mail-recipient-row{grid-template-columns:1fr}.vf-mail-recipient-row select{max-width:none}'
if mobile_anchor in updated and '.vf-mail-recipient-row{grid-template-columns:1fr}' not in updated:
    updated = updated.replace(mobile_anchor, mobile_new, 1)

hook = "['vf-schaden','vf-art','vf-email'].forEach(id=>$(id)?.addEventListener('input',updateMailFields));"
preset_hook = hook + "$('vf-mail-preset')?.addEventListener('change',event=>{const value=event.target.value;if(value)$('vf-mail-to').value=value});"
if hook in updated and "$('vf-mail-preset')?.addEventListener('change'" not in updated:
    updated = updated.replace(hook, preset_hook, 1)

if updated != source:
    path.write_text(updated, encoding='utf-8')
    print('Standardempfänger für Fall-E-Mails ergänzt.')
else:
    print('Standardempfänger bereits vorhanden.')
