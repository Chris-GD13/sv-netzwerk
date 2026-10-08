"""Build a private source-bound library from PDF text exports; never commit its output."""
import argparse, json, re
from pathlib import Path

def build(directory):
    documents, positions = [], []
    for path in sorted(Path(directory).glob('*.json')):
        document = json.loads(path.read_text(encoding='utf-8'))
        if 'pages' not in document: continue
        key = document['sha256']
        kind = 'positions' if 'Positionen' in document['name'] else 'buildings' if 'Gebaeude' in document['name'] else 'rpa' if 'RPA' in document['name'] else 'lifetime'
        documents.append({**document, 'id':key, 'kind':kind})
        if kind != 'positions': continue
        chapter, definitions = '', {}
        for page in document['pages']:
            text = re.sub(r'\n\s*\n', '\n', page['text'].replace('\r',''))
            lb = re.search(r'\bLB\s+(\d+)', text)
            if lb and chapter != lb[1]: chapter, definitions = lb[1], {}
            # Definitions occur between position blocks and can span several pages.
            headers = list(re.finditer(r'(?m)^(?:\d+ [^\n]+ KG \d+|A\s*\d+ [^\n]+Beschreibung für Pos[^\n]*)', text))
            price_pattern = r'((?:(?:[\d.,]+€|[–-])\s+){4}(?:[\d.,]+€|[–-]))\s*\[([^\]]+)\][^\n]*?(\d{3}\.\d{3}\.\d{3})'
            for i, header in enumerate(headers):
                block = text[header.start():headers[i+1].start() if i+1<len(headers) else len(text)]
                if header[0].startswith('A'):
                    number = re.match(r'A\s*(\d+)', header[0])[1]
                    definitions[number] = {'page':page['page'], 'text':block.strip()}
                    continue
                price = re.search(price_pattern, block)
                if not price: continue
                numbers = [float(n.replace('.','').replace(',','.')) if n not in ('–','-') else None for n in re.findall(r'[\d.,]+(?=€)|[–-]', price[1])]
                if len(numbers)!=5 or numbers[2] is None: continue
                description = re.sub(r'^\d+\s+|\s+KG\s+\d+$','',header[0])
                scope = block[:price.start()].strip()
                inherited = []
                for reference in re.findall(r'Ausführungsbeschreibung A\s*(\d+)',scope):
                    if reference in definitions: inherited.append(definitions[reference])
                clock = re.search(r'⏱\s*([\d,]+)\s*h/',price[0])
                positions.append({'id':key+':'+price[3], 'document_id':key, 'position_code':price[3], 'description':description,
                    'unit':price[2], 'price_low':numbers[1], 'price_mid':numbers[2], 'price_high':numbers[3],
                    'price_min':numbers[0], 'price_max':numbers[4], 'source_page':page['page'], 'source_name':document['name'],
                    'scope':scope, 'inherited':inherited, 'source_quote':block[:price.end()].strip(),
                    'labor_hours':float(clock[1].replace(',','.')) if clock else None})
    return {'schema':1, 'documents':documents, 'positions':positions}

if __name__ == '__main__':
    parser=argparse.ArgumentParser();parser.add_argument('directory');parser.add_argument('output');args=parser.parse_args()
    result=build(args.directory);Path(args.output).write_text(json.dumps(result,ensure_ascii=False,separators=(',',':')),encoding='utf-8')
    print(json.dumps({'documents':len(result['documents']), 'positions':len(result['positions'])}))
