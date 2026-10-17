"""Offline inventory. Originals are read only; output may be confidential.

Text extraction is not legal/factual validation or layout verification.
Usage: python inventory.py --root DIR [--root DIR] --out PRIVATE_DIR
Optional packages: pypdf, openpyxl. No network or AI calls.
"""
import argparse
import hashlib
import json
import re
import zipfile
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path
from xml.etree import ElementTree as ET


def extract(path):
    suffix = path.suffix.lower()
    if suffix == '.gdoc':
        return [], ['google_native_requires_connector_export']
    if suffix in {'.md', '.txt', '.json', '.csv'}:
        return [{'locator': 'lines:1', 'text': path.read_text(encoding='utf-8-sig')}], []
    if suffix == '.pdf':
        from pypdf import PdfReader
        reader = PdfReader(path)
        chunks, gaps = [], []
        for i, page in enumerate(reader.pages, 1):
            text = page.extract_text() or ''
            chunks.append({'locator': f'page:{i}', 'text': text})
            if len(text.strip()) < 60 or '\ufffd' in text:
                gaps.append(f'page:{i}:ocr_or_visual_review_required')
        return chunks, gaps
    if suffix in {'.docx', '.docm', '.dotx', '.pptx', '.odt'}:
        with zipfile.ZipFile(path) as z:
            names = sorted(n for n in z.namelist() if
                           re.match(r'word/(document|header\d+|footer\d+)\.xml$', n) or
                           re.match(r'ppt/(slides/slide\d+|notesSlides/notesSlide\d+)\.xml$', n) or
                           n == 'content.xml')
            chunks = []
            for name in names:
                root = ET.fromstring(z.read(name))
                text = '\n'.join(''.join(e.itertext()) for e in root.iter()
                                 if e.tag.rsplit('}', 1)[-1] in {'p'})
                chunks.append({'locator': name, 'text': text})
        return chunks, ['layout_and_embedded_objects_not_verified']
    if suffix in {'.xlsx', '.xlsm'}:
        from openpyxl import load_workbook
        wb = load_workbook(path, read_only=True, data_only=False)
        chunks = []
        try:
            for sheet in wb:
                rows = []
                for row in sheet:
                    populated = [f'{c.coordinate}={c.value}' for c in row if c.value is not None]
                    if populated:
                        rows.append(' | '.join(populated))
                chunks.append({'locator': 'sheet:'+sheet.title, 'text': '\n'.join(rows)})
        finally:
            wb.close()
        return chunks, ['formulas_not_calculated_macros_not_executed_layout_not_verified']
    return [], ['unsupported_format_requires_review']


def inventory(roots, out):
    roots = [root.resolve(strict=True) for root in roots]
    for root in roots:
        if out.resolve().is_relative_to(root):
            raise ValueError('Output must be outside all source roots')
    out.mkdir(parents=True, exist_ok=True)
    corpus = out / 'extracted'
    corpus.mkdir(exist_ok=True)
    rows, hashes = [], {}
    for number, root in enumerate(roots):
        for path in sorted(root.rglob('*')):
            if not path.is_file():
                continue
            row = {'root': str(root), 'path': path.relative_to(root).as_posix(),
                   'validation': 'not_fachlich_reviewed', 'binding_status': 'unclassified'}
            if path.suffix.lower() == '.gdoc':
                row.update(extraction='connector_required', gaps=['google_native_requires_connector_export'])
                rows.append(row)
                continue
            try:
                raw = path.read_bytes()
                digest = hashlib.sha256(raw).hexdigest()
                row.update(sha256=digest, bytes=len(raw), modified_at=datetime.fromtimestamp(
                    path.stat().st_mtime, timezone.utc).isoformat())
                row['duplicate_of'] = hashes.get(digest)
                hashes.setdefault(digest, f'{number}:{row["path"]}')
                chunks, gaps = extract(path)
                row.update(extraction='text_extracted' if any(c['text'].strip() for c in chunks)
                           else 'unresolved', gaps=gaps, chunks=len(chunks),
                           characters=sum(len(c['text']) for c in chunks))
                if chunks:
                    target = corpus / (digest+'.json')
                    target.write_text(json.dumps(chunks, ensure_ascii=False, indent=2), encoding='utf-8')
                    row['extracted_file'] = 'extracted/'+target.name
            except Exception as error:
                row.update(extraction='failed', gaps=[type(error).__name__+': '+str(error)])
            rows.append(row)
    result = {'generated_at': datetime.now(timezone.utc).isoformat(),
              'scope': 'local_sync_snapshot_not_cloud_completeness_proof',
              'files': rows, 'summary': dict(Counter(r['extraction'] for r in rows))}
    (out/'inventory.json').write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding='utf-8')
    print(json.dumps({'files': len(rows), 'unique_hashes': len(hashes),
                      'summary': result['summary'], 'with_gaps': sum(bool(r.get('gaps')) for r in rows)}))
    return result


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, action='append', required=True)
    parser.add_argument('--out', type=Path, required=True)
    args = parser.parse_args()
    inventory(args.root, args.out)
