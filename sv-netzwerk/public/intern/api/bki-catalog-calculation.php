<?php
declare(strict_types=1);
require_once __DIR__.'/bki-library.php';
function bkCatalogScopeIssue(array $component,array $row,array $facts):string {
  $issue=bkBatchScopeIssue($component,$row,$facts);
  if(preg_match('/Gussrohrleitung.*demontieren/iu',$component['description'])){
    $evidence=$row['scope'].' '.$row['description'];
    if(preg_match('/Guss[^.\n]{0,80}\bDN\s*\d+/iu',(string)($facts['notes']??''),$fact))$evidence.=' '.$fact[0];
    if(!preg_match('/\bDN\s*(\d+)/iu',$evidence,$diameter))return 'Durchmesser der vorhandenen Gussleitung fehlt; die neue HT-Nennweite belegt ihn nicht.';
    if(preg_match('/Durchmesser:\s*DN(\d+)\s*bis\s*DN(\d+)/iu',(string)($component['scope']??''),$range)&&((int)$diameter[1]<(int)$range[1]||(int)$diameter[1]>(int)$range[2]))return 'Die gewählte Guss-Variante passt nicht zum belegten Alt-Durchmesser.';
  }
  return $issue;
}
/** Retrieve every work item independently; prices come exclusively from the private catalog. */
function bkCatalogCalculate(array $rows,array $input):array {
  $status=bklStatus();if($status['positions']<1)throw new RuntimeException('Der geprüfte IONOS-Preisbestand fehlt. Bitte Originale und Preisindex einspielen.');
  foreach($status['documents'] as $doc)if(!$doc['on_ionos'])throw new RuntimeException('Original-PDF fehlt auf IONOS: '.$doc['name']);
  $level=in_array($input['level']??'mid',['low','mid','high'],true)?($input['level']??'mid'):'mid';$facts=is_array($input['facts']??null)?$input['facts']:[];
  $basis=($input['basis']??'bki')==='rpa'?'rpa':'bki';if($basis==='rpa'&&($input['rpa_confirmed']??false)!==true)throw new RuntimeException('Bitte die Gültigkeit der RPA-Höchstpreisliste für den Auftrag bestätigen.');
  $key='bki_catalog_v4_'.hash('sha256',json_encode([$status,$rows,$level,$facts,$input['location']??'',$basis],JSON_UNESCAPED_UNICODE));
  $cached=json_decode(bkSettingGet($key,'{}'),true);
  if(isset($cached['positions'])){
    foreach($cached['positions'] as &$position){$row=$rows[(int)$position['row_id']];foreach($position['source_candidates'] as &$component){$issue=bkCatalogScopeIssue($component,$row,$facts);if($issue!=='')$component['scope_issue']=$issue;}unset($component);$position['priced_components']=array_values(array_filter($position['source_candidates'],fn($c)=>$c['quantity_verified']&&$c['scope_issue']===''));$position['calculated_net']=$position['priced_components']?round(array_sum(array_map(fn($c)=>$c['quantity']*$c['unit_price'],$position['priced_components'])),2):null;if($position['status']==='ready'&&count($position['priced_components'])!==count($position['components'])){$position['components']=[];$position['status']=$position['priced_components']?'partial':'open';}elseif($position['status']!=='ready')$position['status']=$position['priced_components']?'partial':'open';}unset($position);
    $cached['calculated_net']=round(array_sum(array_map(fn($p)=>$p['calculated_net']??0,$cached['positions'])),2);return $cached+['cached'=>true];
  }
  $candidates=[];$pool=[];
  foreach($rows as $row){
    $query=$row['description'].' '.$row['scope'];
    $found=array_merge(bklSearch($row['description'],20),bklSearch($query,25));$selected=[];$seen=[];
    $hints=['/Guss/iu'=>'Gussrohrleitung demontieren','/Deckendurch|Betonplatte/iu'=>'Kernbohrung Beton Durchbruch Stahlbeton','/HT|Fallleitung|Hauptlüftung/iu'=>'Abwasser HT Rohrleitungen Formteile DN110','/Schall/iu'=>'Abwasserleitung gedämmt Rohrdämmung','/Dach|Lüftungsabschluss/iu'=>'Dunstrohr Durchgangsformstück','/Schutz/iu'=>'Staubschutzwand Schutzabdeckung','/Reinigungsstück/iu'=>'Reinigungsrohr Putzstück'];
    foreach($hints as $pattern=>$hint)if(preg_match($pattern,$query))$found=array_merge($found,bklSearch($hint,6));
    if($basis==='rpa')$found=array_merge($found,bklSearch($query,100));
    foreach($found as $p){if(($p['source_kind']??'bki')!==$basis||isset($seen[$p['id']]))continue;$seen[$p['id']]=true;$pool[$p['id']]=$p;$selected[]=['id'=>$p['id'],'code'=>$p['position_code'],'description'=>$p['description'],'unit'=>$p['unit'],'scope'=>$p['scope'],'inherited'=>$p['inherited'],'source_action'=>$p['source_action']??null,'measure'=>$p['measure']??null];}
    $candidates[]=['row_id'=>$row['row_id'],'candidates'=>$selected];
  }
  $instructions=<<<'PROMPT'
Erstelle eine echte Nachkalkulation des gesamten KVA aus dem vollständig auf IONOS gelesenen Originalkatalog. Jede Zeile hat unabhängig recherchierte Kandidaten; nutze alle Kandidaten gemeinsam auch über Zeilengrenzen. Wähle ausschließlich vorhandene candidate_id. Preise werden vom Server gebunden, keine Preise erfinden. Gleiche vollständigen Original-scope ab, nicht nur Kurztitel. Zerlege Bündelleistungen in passende Teilleistungen. Enthaltene Nebenleistungen und über andere Zeilen abgedeckte Leistungen nicht doppelt kalkulieren. Ausführungsbeschreibung inherited ist Teil des Original-Leistungsumfangs. Keine unpassenden Gewerke oder Durchmesser. DN/OD110 Kunststoff ist nicht automatisch alter Guss DN125; bei unbekanntem Durchmesser Varianten als offenen Punkt benennen. Keine neuen Öffnungsflächen, Wandstärken, Abfallgewichte, Stunden oder Zuschläge erfinden. Vorhandene Maße und Leistungsangaben nicht erneut fragen. Jede belegte Teilleistung mit belegter Menge dennoch berechnen, selbst wenn weitere Teile der KVA-Leistung offen bleiben: coverage=partial. Unvollständige Leistungen nicht vollständig bestätigen. Es ist ausdrücklich NICHT die Aufgabe, alle Zeilen aufgrund einer einzigen fehlenden Teilleistung leer zurückzugeben.
Für Komponenten quantity und quantity_source angeben: type=kva,row_id für ausdrücklich vorhandene KVA-Menge gleicher Einheit oder type=fact,quote für wörtliche Nutzerangabe gleicher Menge und Einheit. Bei Einheitenabweichung quantity=0 und konkrete fehlende Menge nennen. Angaben "bis" sind Höchstmaße, keine bestätigten Aufmaße; keine freien Umrechnungen. Vollständig dokumentierte mathematische Ableitungen gehören in den offenen Hinweis, nicht als tatsächliche Maße. Feuerwiderstandsklasse/Ringspalt/Abschottungssystem nicht aus allgemeinen Vorschriften erfinden. BKI-Bundesdurchschnitt separat von objektbezogenen Erschwernissen darstellen: im Preis nicht belegte Erschwernisse bleiben offen, kein vollständiger Vergleichspreis daraus. Bereits enthaltene Befestigungen und Entsorgung ausdrücklich berücksichtigen. Region unbekannt: kein Regionalfaktor erfinden.
Bei keinem passenden Kandidaten benenne konkret benötigte Leistung als missing_search, damit eine gezielte zweite lokale Suche möglich ist, statt Benutzer nach vorhandenen Quellen zu fragen. Nur tatsächlich noch fehlende Ausführungs-/Mengenangaben in gebündelten questions. Ausgabe nur JSON:
{"positions":[{"row_id":"0","coverage":"complete|partial|unmatched","reason":"berechnete Leistungsabdeckung, fehlende Teilleistungen und Doppelansätze","components":[{"candidate_id":"","quantity":0,"quantity_source":{"type":"kva|fact","row_id":"","quote":""}}],"missing_search":["konkrete fehlende Teilleistung"]}],"questions":[{"key":"","label":"konkrete fehlende Angabe","row_ids":["0"]}]}
PROMPT;
  $instructions.=' Bevorzuge passende Altbau-Gebäude-Leistungspakete einschließlich Formteilen, Befestigung und Dämmung, um den vollständigen Umfang zu berechnen. Die Bruttoquellen werden serverseitig netto umgerechnet. Enthaltene Leistungen nicht doppelt ansetzen. Ergänze jede Komponentenwahl um scope_compatible=true nur bei wirklich passenden Abmessungen, Material und Tätigkeit. Ein 30x15cm-Schlitz bei KVA bis40cm ohne Tiefenmaß ist nur ein Vergleichskandidat, KEINE belegte Teilmenge. Alle nicht kompatiblen Kandidaten scope_compatible=false. Eine kleine Variante darf keine andere größere/ungeklärte Ausführung als berechnete Teilleistung ersetzen. quantity_source.type=scope mit row_id und quote darf explizite tatsächliche Mengen im Langtext belegen, aber niemals bis/max-Mengen. Fehlende Preise oder Mengen nicht durch andere unpassende Gewerke ersetzen.';
  $instructions.=' Bei einer vorläufigen Planungsannahme in known_facts.notes darf die ausdrücklich vom Nutzer angenommene Ausführung als Planungsvariante gewählt werden, mit klarer Kennzeichnung im reason. Keine weiteren Annahmen ergänzen. Für Tätigkeiten ohne belegt passende Pauschalposition wähle den belegten fachlich passenden Stundenlohn als Preisgrundlage mit quantity=0; dabei keine Stunden erfinden. Auch offene Bauteile bekommen passende Einheitspreise als Prüf-/Planungsgrundlage, wenn sachlich vorhanden. quantities nur aus der exakten row_id (0-basierter Index), nicht aus gedruckter KVA-Positionsnummer. Wenn keine vollständige Quellenleistung verfügbar ist, benenne eine kurze konkret abzugrenzende Restleistung, keine langen pauschalen Warntexte.';
  if($basis==='rpa')$instructions.=' Für diesen Lauf gilt ausschließlich die vom Nutzer bestätigte RPA-Höchstpreisliste. Kandidatenpreise sind Höchstpreise, keine BKI-Mittelwerte. FAQ-Bedingungen, enthaltene Nebenleistungen, Exklusivpositionen und Grenzen der RPA-Positionen zwingend berücksichtigen. Keine BKI-Positionen ergänzen.';
  $response=bkOpenAIJson('POST','responses',['model'=>env('OPENAI_BKI_MODEL','gpt-5.4'),'instructions'=>$instructions,'input'=>json_encode(['rows'=>$rows,'candidate_groups'=>$candidates,'known_facts'=>$facts,'location'=>$input['location']??''],JSON_UNESCAPED_UNICODE),'max_output_tokens'=>14000],480);
  $raw=bkJson(bkOutputText($response));$positions=[];$used=[];
  foreach($rows as $row){
    $match=null;foreach(($raw['positions']??[])as $p)if((string)($p['row_id']??'')===$row['row_id']){$match=$p;break;}
    $match=$match??[];$components=[];$ready=($match['coverage']??'')==='complete';
    foreach(($match['components']??[])as $selection){
      $p=$pool[$selection['candidate_id']??'']??null;if(!$p){$ready=false;continue;}
      $component=$p+['quantity'=>(float)($selection['quantity']??0),'quantity_source'=>$selection['quantity_source']??[]];
      $component['unit_price']=(float)($p['price_'.$level]??$p['price_mid']);$component['source_page']='Seite '.$p['source_page'];$component['source_url']='/intern/api/bki-library-upload.php?action=pdf&id='.$p['document_id'].'#page='.$p['source_page'];
      $component['evidence_verified']=true;$component['quantity_verified']=bkBatchQuantity($component,$row,$rows,$facts);
      $quantitySource=$selection['quantity_source']??[];
      if(($quantitySource['type']??'')==='scope'){
        foreach($rows as $quantityRow)if($quantityRow['row_id']===(string)($quantitySource['row_id']??'')){
          $quote=bkBatchText((string)($quantitySource['quote']??''));$scope=bkBatchText($quantityRow['scope']);
          if(strlen($quote)>2&&str_contains($scope,$quote)&&!preg_match('/\b(bis|max|höchstens|maximal)\b/iu',$quote))$component['quantity_verified']=bkBatchQuantity(['quantity'=>$component['quantity'],'unit'=>$component['unit'],'quantity_source'=>['type'=>'fact','quote'=>$quote]],['unit'=>'','quantity'=>0],[],['notes'=>$quote]);
        }
      }
      $issue=bkCatalogScopeIssue($component,$row,$facts);if(($selection['scope_compatible']??false)!==true)$issue='Ausführung oder Abmessungen der gewählten Preisposition sind nicht bestätigt.';
      $component['scope_issue']=$issue;
      $duplicate=$p['id'].'|'.json_encode($selection['quantity_source']??[]).'|'.$component['quantity'];
      if(isset($used[$duplicate])){$ready=false;continue;}
      if($component['quantity_verified']&&$issue==='')$used[$duplicate]=true;else $ready=false;
      $components[]=$component;
    }
    $priced=array_values(array_filter($components,fn($c)=>$c['quantity_verified']&&$c['scope_issue']===''));
    $subtotal=round(array_sum(array_map(fn($c)=>$c['quantity']*$c['unit_price'],$priced)),2);
    $low=round(array_sum(array_map(fn($c)=>$c['quantity']*($c['price_low']??$c['unit_price']),$priced)),2);$high=round(array_sum(array_map(fn($c)=>$c['quantity']*($c['price_high']??$c['unit_price']),$priced)),2);
    $positions[]=['row_id'=>$row['row_id'],'source_position'=>$row['source_position'],'description'=>$row['description'],'status'=>$ready&&$priced?'ready':($priced?'partial':'open'),'reason'=>(string)($match['reason']??'Keine passende Preisgrundlage gefunden.'),'components'=>$ready?$priced:[],'source_candidates'=>$components,'priced_components'=>$priced,'calculated_net'=>$priced?$subtotal:null,'calculated_low'=>$priced?$low:null,'calculated_high'=>$priced?$high:null,'offered_total'=>$row['offered_total']??null];
  }
  $data=['positions'=>$positions,'questions'=>$raw['questions']??[],'search_mode'=>'ionos_catalog','basis'=>$basis,'regional_factor'=>null,'planning'=>preg_match('/Annahme|vorläufig|ungeprüft/iu',(string)($facts['notes']??''))===1,'facts'=>$facts,'calculated_net'=>round(array_sum(array_map(fn($p)=>$p['calculated_net']??0,$positions)),2),'catalog_positions'=>$status['positions'],'documents'=>$status['documents']];
  bkSettingSet($key,json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));return $data+['cached'=>false];
}
