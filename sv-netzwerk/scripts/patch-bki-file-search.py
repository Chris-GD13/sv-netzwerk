from pathlib import Path

path = Path(__file__).resolve().parents[1] / 'public/intern/api/bki-calculator.php'
source = path.read_text(encoding='utf-8')
updated = source

helpers = r'''
function bkOpenAIJson(string $method,string $path,?array $payload=null,int $timeout=360):array{
  $key=trim(env('OPENAI_API_KEY',''));
  if($key==='')throw new RuntimeException('OpenAI API-Key ist nicht konfiguriert.');
  $headers=['Authorization: Bearer '.$key];
  $body=null;
  if($payload!==null){$headers[]='Content-Type: application/json';$body=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
  $r=bkHttp($method,'https://api.openai.com/v1/'.ltrim($path,'/'),$headers,$body,$timeout);
  $j=$r['body']!==''?json_decode($r['body'],true):[];
  if($r['status']<200||$r['status']>=300){
    $message=is_array($j)&&!empty($j['error']['message'])?(string)$j['error']['message']:'HTTP '.$r['status'];
    throw new RuntimeException('OpenAI-Dateisuche fehlgeschlagen: '.$message);
  }
  return is_array($j)?$j:[];
}
function bkVectorStore():string{
  $configured=trim(env('OPENAI_BKI_VECTOR_STORE_ID',''));
  $cached=json_decode(bkSettingGet('openai_bki_vector_store','{}'),true);
  $storeId=$configured!==''?$configured:trim((string)($cached['vector_store_id']??''));
  if($storeId==='')throw new RuntimeException('Der vorbereitete BKI-Suchindex ist nicht konfiguriert.');
  $existing=bkOpenAIJson('GET','vector_stores/'.rawurlencode($storeId),null,90);
  if(empty($existing['id'])||($existing['status']??'completed')==='expired')throw new RuntimeException('Der vorbereitete BKI-Suchindex ist nicht verfügbar.');
  return $storeId;
}
'''

if 'function bkVectorStore()' not in updated:
    marker = '\nfunction bkOutputText('
    if marker not in updated:
        raise SystemExit('Einfügepunkt vor bkOutputText nicht gefunden')
    updated = updated.replace(marker, '\n' + helpers.strip() + marker, 1)

start = updated.find('function bkSearch(array $in):array{')
if start < 0:
    raise SystemExit('bkSearch nicht gefunden')

new_search = r'''function bkSearch(array $in):array{
  $q=trim((string)($in['query']??''));
  if($q==='')throw new RuntimeException('Leistungsbeschreibung fehlt.');
  $location=trim((string)($in['location']??''));
  $qty=trim((string)($in['quantity']??''));
  $unit=trim((string)($in['unit']??''));
  $case=is_array($in['case_meta']??null)?$in['case_meta']:[];
  $vectorStoreId=bkVectorStore();
  $instructions=<<<'PROMPT'
Du arbeitest ausschließlich mit den über die Dateisuche zugänglichen lizenzierten BKI-Unterlagen "BKI Baukosten Positionen Altbau 2026" und "BKI Baukosten Gebäude Altbau 2026". Nutze die Dateisuche gezielt, um für die konkrete Schaden- oder Instandsetzungsleistung passende BKI-Altbau-Positionen und – soweit erforderlich – den Regionalfaktor zu finden. Erfinde keine Positionsnummern, Einheiten, Seiten oder Preise. Wenn eine Angabe nicht belastbar aus den BKI-Dateien auffindbar ist, lasse das Feld leer bzw. null. Verwende keine allgemeinen Marktpreise außerhalb der BKI-Dateien.

Antworte ausschließlich als JSON:
{"regional_factor":null,"regional_factor_note":"","positions":[{"position_code":"","description":"","unit":"","price_low":null,"price_mid":null,"price_high":null,"recommended_quantity":null,"source_page":"","source_name":"BKI Baukosten Positionen Altbau 2026","note":""}]}

Regeln:
- maximal 6 wirklich passende Positionen.
- BKI-Preise als Netto-Einheitspreise in EUR numerisch zurückgeben.
- Wenn mehrere notwendige Teilleistungen bestehen, z. B. Ausbau/Entsorgung und Neueinbau, dürfen mehrere Positionen vorgeschlagen werden.
- recommended_quantity aus der Nutzereingabe ableiten, aber nur bei eindeutiger Umrechnung. Beispiel: 5 Elemente à 2 m² und BKI-Einheit m² => 10; BKI-Einheit Stück => 5.
- regional_factor nur aus den BKI-Regionalfaktoren bestimmen, wenn Ort/PLZ eindeutig zuordenbar; sonst null und kurze Erläuterung.
- source_page muss die BKI-Seite enthalten, soweit sie aus dem Suchtreffer belastbar hervorgeht.
PROMPT;
  $text="Leistung: {$q}\nOrt/Region: {$location}\nExplizite Menge: {$qty}\nExplizite Einheit: {$unit}\nSchadenart: ".trim((string)($case['schadenart']??''));
  $payload=[
    'model'=>env('OPENAI_MODEL','gpt-5.4-mini'),
    'instructions'=>$instructions,
    'input'=>$text,
    'tools'=>[[
      'type'=>'file_search',
      'vector_store_ids'=>[$vectorStoreId],
      'max_num_results'=>12
    ]],
    'max_output_tokens'=>4500
  ];
  $d=bkOpenAIJson('POST','responses',$payload,360);
  $out=bkJson(bkOutputText($d));
  $rf=is_numeric($out['regional_factor']??null)?(float)$out['regional_factor']:null;
  $positions=[];
  foreach(($out['positions']??[])as$p){
    if(!is_array($p))continue;
    $positions[]=[
      'position_code'=>trim((string)($p['position_code']??'')),
      'description'=>trim((string)($p['description']??'')),
      'unit'=>trim((string)($p['unit']??'')),
      'price_low'=>is_numeric($p['price_low']??null)?(float)$p['price_low']:null,
      'price_mid'=>is_numeric($p['price_mid']??null)?(float)$p['price_mid']:null,
      'price_high'=>is_numeric($p['price_high']??null)?(float)$p['price_high']:null,
      'recommended_quantity'=>is_numeric($p['recommended_quantity']??null)?(float)$p['recommended_quantity']:null,
      'regional_factor'=>$rf,
      'source_page'=>trim((string)($p['source_page']??'')),
      'source_name'=>trim((string)($p['source_name']??'BKI Baukosten Positionen Altbau 2026')),
      'note'=>trim((string)($p['note']??''))
    ];
  }
  return[
    'positions'=>$positions,
    'regional_factor'=>$rf,
    'regional_factor_note'=>trim((string)($out['regional_factor_note']??'')),
    'search_mode'=>'file_search'
  ];
}'''

if "'search_mode'=>'file_search'" not in updated[start:]:
    end = updated.find('\n}\n', start)
    if end < 0:
        raise SystemExit('Ende von bkSearch nicht gefunden')
    end += len('\n}')
    updated = updated[:start] + new_search + updated[end:]

if updated != source:
    path.write_text(updated, encoding='utf-8')
    print('BKI-Suche auf File Search umgestellt.')
else:
    print('BKI-Suche bereits auf File Search.')
