from pathlib import Path

root = Path(__file__).resolve().parents[1]
calc = root / 'src/pages/intern/kalkulation/index.astro'
portal = root / 'src/pages/intern/versicherungsfaelle/index.astro'

source = calc.read_text(encoding='utf-8')
calc_tag = '<script is:inline src="/intern/bki-pdf-export.js?v=20260901-1"></script>'
anchor = '<script is:inline src="/intern/calculation-note-helper.js?v=20260827-1"></script>'
if calc_tag not in source:
    if anchor not in source:
        raise SystemExit('BKI helper anchor not found')
    source = source.replace(anchor, anchor + '\n' + calc_tag, 1)
    calc.write_text(source, encoding='utf-8')

source = portal.read_text(encoding='utf-8')
mail_tag = '<script is:inline src="/intern/bki-mail-attachment.js?v=20260827-2"></script>'
if mail_tag not in source:
    marker = '</InternalLayout>'
    if marker not in source:
        raise SystemExit('Portal layout anchor not found')
    source = source.replace(marker, mail_tag + '\n' + marker, 1)
    portal.write_text(source, encoding='utf-8')

print('BKI PDF export and mail handoff wired.')
