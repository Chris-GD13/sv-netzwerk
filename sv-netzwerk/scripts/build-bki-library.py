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
        if kind == 'buildings' and 'Altbau' in document['name']:
            for page in document['pages']:
                text=re.sub(r'\n\s*\n','\n',page['text'].replace('\r','\n')).replace('\u00a0',' ')
                category=re.search(r'(?m)^(\d{3}\.\d{2})\s+[^\n]+',text)
                heading=re.search(r'KG\.OZ\s+(Abbrechen|Wiederherstellen|Herstellen)',text)
                if not category or not heading or 'inkl. 19% MwSt.' not in text: continue
                blocks=list(re.finditer(r'(?m)^(\d{2})\s+[^\W\d_][^\n]+',text))
                for i,header in enumerate(blocks):
                    block=text[header.start():blocks[i+1].start() if i+1<len(blocks) else len(text)]
                    price=re.search(r'([\d.]+,\d{2})\s+([\d.]+,\d{2})\s+([\d.]+,\d{2})',block)
                    unit=re.search(r'Einheit:\s*(\S+)([^\n]*)',block)
                    if not price or not unit: continue
                    gross=[float(n.replace('.','').replace(',','.')) for n in price.groups()];net=[n/1.19 for n in gross]
                    title=re.sub(r'\s+',' ',block[len(header[1]):price.start()]).strip()
                    code=category[1]+'/'+header[1]
                    positions.append({'id':key+':'+heading[1]+':'+code,'document_id':key,'position_code':code,'description':title+' · '+heading[1],
                        'unit':unit[1],'measure':unit[2].strip(),'price_low':net[0],'price_mid':net[1],'price_high':net[2],'gross_prices':gross,'source_vat':19,
                        'source_page':page['page'],'source_name':document['name'],'scope':block[price.end():].strip(),'inherited':[],
                        'source_quote':block.strip(),'source_code_tokens':[category[1],header[1]],'source_kind':'bki','source_action':heading[1]})
        if kind == 'rpa':
            for page in document['pages']:
                text=re.sub(r'\n\s*\n','\n',page['text'].replace('\r','\n'))
                blocks=list(re.finditer(r'(?m)^(\d{2}\.\d{2}\.\d{3})\s+',text))
                for i,header in enumerate(blocks):
                    block=text[header.end():blocks[i+1].start() if i+1<len(blocks) else len(text)]
                    price=re.search(r'\b(Pau\.?|Std\.?|m²|m2|m|Stk\.?|St\.?)\s+([\d.,]+)\s*€',block)
                    if not price: continue
                    value=float(price[2].replace('.','').replace(',','.'))
                    scope=block[:price.start()].strip()
                    positions.append({'id':key+':'+header[1], 'document_id':key,'position_code':header[1], 'description':re.sub(r'\s+',' ',scope)[:160],
                        'unit':{'Pau.':'psch','Pau':'psch','Std.':'h','Std':'h'}.get(price[1],price[1]), 'price_low':value,'price_mid':value,'price_high':value,
                        'source_page':page['page'],'source_name':document['name'],'scope':scope,'inherited':[],'source_quote':header[1]+' '+block[:price.end()], 'source_kind':'rpa'})
        if kind != 'positions': continue
        chapter, definitions = '', {}
        for page in document['pages']:
            text = re.sub(r'\n\s*\n', '\n', page['text'].replace('\r',''))
            lb = re.search(r'\bLB\s+(\d+)', text)
            if lb and chapter != lb[1]: chapter, definitions = lb[1], {}
            # Definitions occur between position blocks and can span several pages.
            headers = list(re.finditer(r'(?m)^(?:\d{1,3} [^\W\d_][^\n]+|A\s*\d+ [^\n]+Beschreibung für Pos[^\n]*)', text))
            price_pattern = r'((?:(?:[\d.,]+€?|[–-])\s+){4}(?:[\d.,]+€?|[–-]))\s*\[([^\]]+)\][^\n]*?(\d{3}\.\d{3}\.\d{3})'
            for i, header in enumerate(headers):
                block = text[header.start():headers[i+1].start() if i+1<len(headers) else len(text)]
                if header[0].startswith('A'):
                    number = re.match(r'A\s*(\d+)', header[0])[1]
                    definitions[number] = {'page':page['page'], 'text':block.strip()}
                    continue
                price = re.search(price_pattern, block)
                if not price: continue
                numbers = [float(n.replace('.','').replace(',','.')) if n not in ('–','-') else None for n in re.findall(r'[\d.,]+|[–-]', price[1])]
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
                    'scope':scope, 'inherited':inherited, 'source_quote':block[:price.end()].strip(), 'source_kind':'bki',
                    'labor_hours':float(clock[1].replace(',','.')) if clock else None})
    return {'schema':1, 'documents':documents, 'positions':positions}

if __name__ == '__main__':
    parser=argparse.ArgumentParser();parser.add_argument('directory');parser.add_argument('output');args=parser.parse_args()
    result=build(args.directory);Path(args.output).write_text(json.dumps(result,ensure_ascii=False,separators=(',',':')),encoding='utf-8')
    print(json.dumps({'documents':len(result['documents']), 'positions':len(result['positions'])}))
