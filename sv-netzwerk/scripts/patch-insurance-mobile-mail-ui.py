from pathlib import Path

path = Path(__file__).resolve().parents[1] / 'src/pages/intern/versicherungsfaelle/index.astro'
source = path.read_text(encoding='utf-8')
updated = source

# 1) Upload-Karte auf Mobil kompakter machen.
needle = '<section class="vf-card"><header class="vf-step"><b>2</b><div><strong>Unterlagen hochladen</strong>'
if needle in updated:
    updated = updated.replace(needle, '<section class="vf-card vf-upload-card"><header class="vf-step"><b>2</b><div><strong>Unterlagen hochladen</strong>', 1)

mobile_css = '@media(max-width:760px){.vf-upload-card{padding:12px}.vf-upload-card .vf-step{margin-bottom:8px}.vf-upload-card .vf-drop{padding:9px 10px;min-height:0;gap:2px}.vf-upload-card .vf-drop>span{width:26px;height:26px;font-size:.95rem}.vf-upload-card .vf-drop>strong{font-size:.94rem;line-height:1.2}.vf-upload-card .vf-drop small{font-size:.78rem}.vf-upload-card .vf-drop em{font-size:.72rem}.vf-upload-card .vf-file{padding:6px 8px}'
if mobile_css not in updated:
    updated = updated.replace('@media(max-width:760px){', mobile_css, 1)

# 2) "Verbindung und Vorlagen" aus der linken Spalte entfernen und ganz ans Seitenende verschieben.
start = updated.find('<details class="vf-card vf-system">')
if start >= 0:
    end = updated.find('</details>', start)
    if end >= 0:
        end += len('</details>')
        system_block = updated[start:end].replace('class="vf-card vf-system"', 'class="vf-card vf-system vf-system-bottom"', 1)
        updated = updated[:start] + updated[end:]
        marker = '<input id="vf-folder" type="hidden">'
        if marker in updated:
            updated = updated.replace(marker, system_block + marker, 1)
elif '<details class="vf-card vf-system vf-system-bottom">' in updated:
    pass

# Systemblock am Seitenende bewusst deutlich schmaler als die Hauptarbeitsfläche.
system_css = '.vf-system-bottom{width:min(100%,640px);margin:16px auto 0;box-sizing:border-box}'
updated = updated.replace('.vf-system-bottom{width:min(100%,900px);margin:16px auto 0;box-sizing:border-box}', system_css)
if system_css not in updated:
    updated = updated.replace('.vf-system summary,.vf-options summary{cursor:pointer;font-weight:750}', '.vf-system summary,.vf-options summary{cursor:pointer;font-weight:750}'+system_css, 1)

# 3) Preset-Auswahl in additive Mehrfachauswahl umstellen.
select_start = updated.find('<select id="vf-mail-preset"')
if select_start >= 0:
    select_end = updated.find('</select>', select_start)
    if select_end >= 0:
        select_end += len('</select>')
        multi = '''<div id="vf-mail-presets" class="vf-mail-presets" aria-label="Standardempfänger auswählen">
<label><input type="checkbox" value="service.schaden@sparkassenversicherung.de"> Schadenservice</label>
<label><input type="checkbox" value="archiv@sparkassenversicherung.de"> Archiv</label>
<label><input type="checkbox" value="backoffice@meygeneralbau.de"> Mey Generalbau</label>
<label><input type="checkbox" value="info@rainbow-sanierungen.de"> Rainbow</label>
<label><input type="checkbox" value="service@polygon-deutschland.de"> POLYGON</label>
</div>'''
        updated = updated[:select_start] + multi + updated[select_end:]

# Alten Single-Select-Hook entfernen/ersetzen.
old_hook = "$('vf-mail-preset')?.addEventListener('change',event=>{const value=event.target.value;if(value)$('vf-mail-to').value=value});"
updated = updated.replace(old_hook, '', 1)

if "function syncMailPresetRecipients()" not in updated:
    anchor = "['vf-schaden','vf-art','vf-email'].forEach(id=>$(id)?.addEventListener('input',updateMailFields));"
    hook = anchor + "function syncMailPresetRecipients(){const input=$('vf-mail-to');if(!input)return;const manual=input.value.split(/[;,]/).map(v=>v.trim()).filter(Boolean).filter(v=>!['service.schaden@sparkassenversicherung.de','archiv@sparkassenversicherung.de','backoffice@meygeneralbau.de','info@rainbow-sanierungen.de','service@polygon-deutschland.de'].includes(v));const selected=[...document.querySelectorAll('#vf-mail-presets input:checked')].map(el=>el.value);input.value=[...manual,...selected].filter((v,i,a)=>a.indexOf(v)===i).join('; ')}document.querySelectorAll('#vf-mail-presets input').forEach(el=>el.addEventListener('change',syncMailPresetRecipients));"
    if anchor in updated:
        updated = updated.replace(anchor, hook, 1)

css_anchor = '.vf-mail-recipient-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:7px;align-items:end}'
css_new = '.vf-mail-recipient-row{display:grid;grid-template-columns:1fr;gap:7px}.vf-mail-presets{display:flex;flex-wrap:wrap;gap:6px 10px;margin-top:6px}.vf-mail-presets label{display:flex;align-items:center;gap:5px;border:1px solid #d5e0e7;border-radius:8px;padding:6px 8px;background:#f8fafb;font-size:.78rem;font-weight:700}.vf-mail-presets input{width:auto;margin:0}'
if css_anchor in updated:
    updated = updated.replace(css_anchor, css_new, 1)

if updated != source:
    path.write_text(updated, encoding='utf-8')
    print('Mobile Upload, Mehrfach-Empfänger und Systemblock aktualisiert.')
else:
    print('Keine Änderungen erforderlich.')
