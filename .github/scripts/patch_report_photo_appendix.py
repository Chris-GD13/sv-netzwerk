from pathlib import Path

p = Path('sv-netzwerk/src/lib/internal/client.ts')
s = p.read_text(encoding='utf-8-sig')
start = s.find('function buildAssessmentReport(')
end = s.find('function wrapWordDocument(', start)
if start < 0 or end < 0:
    raise SystemExit('buildAssessmentReport/wrapWordDocument nicht gefunden')

block = s[start:end]
block = block.replace("  const windowsHtml = items.map(({ record, photos }, index) => {", """  const roomSortKey = (record: WindowRecord): number => { const m = String(record.room_number || record.room_label || '').match(/\\d+/); return m ? Number(m[0]) : Number.MAX_SAFE_INTEGER; };
  const windowSortKey = (record: WindowRecord): number => { const m = String(record.window_number || record.record_id || '').match(/\\d+/); return m ? Number(m[0]) : Number.MAX_SAFE_INTEGER; };
  const sortedItems = [...items].sort((a, b) => roomSortKey(a.record) - roomSortKey(b.record) || windowSortKey(a.record) - windowSortKey(b.record));
  const windowsHtml = sortedItems.map(({ record }, index) => {""")

photo_start = block.find("    const photoHtml = photos.length ?")
photo_end_marker = "  }).join('');\n  const attachments = documents.length"
photo_end = block.find(photo_end_marker, photo_start)
if photo_start >= 0 and photo_end >= 0:
    replacement = """    return `<section class=\"window-report\"><h2>${index + 1}. Fenster ${escapeHtml(record.window_number || record.record_id)}</h2><p><strong>Standort:</strong> ${escapeHtml([record.building_label, record.section_label, record.floor_label, record.room_number || record.room_label].filter(Boolean).join(' · '))}</p>${sections}</section>`;
  }).join('');

  const appendixPhotos = sortedItems.flatMap(({ photos }) => photos.map((photo) => photoSources.get(photo.id))).filter(Boolean);
  const photoAppendix = appendixPhotos.length
    ? `<div class=\"photo-appendix\">${appendixPhotos.map((source) => `<figure><img src=\"${source}\" alt=\"\"></figure>`).join('')}</div>`
    : '<p>Keine Fotos vorhanden.</p>';
  const attachments = documents.length"""
    block = block[:photo_start] + replacement + block[photo_end + len(photo_end_marker):]

old_end = "</section>`;\n  const safeBuilding"
if '4. Anlage Fotodokumentation' not in block:
    block = block.replace(old_end, "</section><section class=\"photo-appendix-section\"><h2>4. Anlage Fotodokumentation</h2>${photoAppendix}</section>`;\n  const safeBuilding")

if '<h3>Fotodokumentation</h3>' in block or '<figcaption>' in block:
    raise SystemExit('Alte Fotodokumentation konnte nicht entfernt werden')
if 'sortedItems' not in block or '4. Anlage Fotodokumentation' not in block:
    raise SystemExit('Sortierung oder Fotoanhang fehlt')
s = s[:start] + block + s[end:]

ws = s.find('function wrapWordDocument(')
we = s.find('async function archiveAssessmentReport(', ws)
if ws < 0 or we < 0:
    raise SystemExit('wrapWordDocument nicht gefunden')
wrap = '''function wrapWordDocument(bodyHtml: string): string {
  return `<!DOCTYPE html><html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" lang="de"><head><meta charset="utf-8"><title>Gutachten Fensterprüfung</title><style>@page{size:A4;margin:18mm}body{font-family:Arial,sans-serif;color:#071a2e;font-size:10pt;line-height:1.4}h1{font-size:20pt}h2{font-size:14pt;border-bottom:2px solid #f59b16;padding-bottom:4px}h3{font-size:11pt;background:#eaf1f6;padding:5px}table{width:100%;border-collapse:collapse;margin:0 0 10px}th,td{border:1px solid #bcc9d3;padding:5px;text-align:left;vertical-align:top}th{width:36%;background:#eef4f8}.window-report{page-break-before:always}.photo-appendix-section{page-break-before:always}.photo-appendix{display:grid;grid-template-columns:repeat(3,1fr);gap:7px}.photo-appendix figure{margin:0;page-break-inside:avoid;text-align:center}.photo-appendix img{height:4.5cm;max-height:4.5cm;width:auto;max-width:100%;object-fit:contain;border:0}</style></head><body>${bodyHtml}</body></html>`;
}

'''
s = s[:ws] + wrap + s[we:]
p.write_text(s, encoding='utf-8')
