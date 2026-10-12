<?php
declare(strict_types=1);

require_once __DIR__.'/bki-settlement-core.php';

function paSettlementPayload(array $calculation,array $meta): array {
    if(!is_numeric($calculation['vat_rate']??null)||!is_finite((float)$calculation['vat_rate'])||(float)$calculation['vat_rate']<0||(float)$calculation['vat_rate']>100)throw new RuntimeException('Umsatzsteuersatz der Kalkulation fehlt.');
    $lines=json_decode((string)($calculation['items_json']??''),true,512,JSON_THROW_ON_ERROR);
    if(!is_array($lines)||!$lines)throw new RuntimeException('Die gespeicherte Kalkulation enthält keine Positionen.');
    $sections=[];$position=0;$payout=0.0;
    foreach($lines as$line){
        if(($line['type']??'')==='section')continue;
        ++$position;$mode=(string)($line['settlement_mode']??'restore');
        if(!in_array($mode,['restore','full','percent'],true))throw new RuntimeException('Ungültige Abgeltungsart in Position '.$position.'.');
        foreach(['quantity','unit_price']as$field)if(!isset($line[$field])||!is_numeric($line[$field])||!is_finite((float)$line[$field])||(float)$line[$field]<0)throw new RuntimeException('Unvollständige Kalkulationsposition '.$position.'.');
        $factor=$line['regional_factor']??1;
        if(!is_numeric($factor)||!is_finite((float)$factor)||(float)$factor<=0)throw new RuntimeException('Regionalfaktor fehlt in Position '.$position.'.');
        $net=(float)$line['quantity']*(float)$line['unit_price']*(float)$factor;
        if($mode==='restore')continue;
        if($net<=0||trim((string)($line['description']??''))==='')throw new RuntimeException('Betrag oder Leistung der Abgeltungsposition '.$position.' fehlt.');
        if($mode==='percent'){
            $rate=$line['settlement_percent']??null;
            if(!is_numeric($rate)||!is_finite((float)$rate)||(float)$rate<=0||(float)$rate>100)throw new RuntimeException('Abgeltungssatz fehlt in Position '.$position.'.');
            $amount=$net*(float)$rate/100;
            $sections[]='Position '.$position.' ('.$line['description'].'): '.number_format((float)$rate,2,',','.').' % des Nettobetrags '.number_format($net,2,',','.').' EUR, Abgeltung '.number_format($amount,2,',','.').' EUR.';
        }else{
            $amount=$net*(1+(float)$calculation['vat_rate']/100);
            $sections[]='Position '.$position.' ('.$line['description'].'): Auszahlung zu 100 % brutto, '.number_format($amount,2,',','.').' EUR.';
        }
        $payout+=$amount;
    }
    if($payout<=0)throw new RuntimeException('In der gespeicherten Kalkulation ist keine Position zur Abgeltung ausgewählt. Bitte unter Kalkulation die Abgeltungsart und gegebenenfalls den Prozentsatz festlegen und speichern.');
    $number=trim((string)($meta['schaden_nr']??''));$vn=trim((string)($meta['vn_objekt']??''));
    if($number===''||$vn==='')throw new RuntimeException('Schadennummer oder Versicherungsnehmer fehlt.');
    if(trim((string)($calculation['case_no']??''))!==$number)throw new RuntimeException('Die Kalkulation gehört zu einer anderen Schadennummer.');
    $location=trim(implode(', ',array_filter([$meta['schaden_strasse']??'',$meta['schaden_plz']??'',$meta['schaden_ort']??''])));
    if($location==='')throw new RuntimeException('Schadenort fehlt.');
    $note=trim((string)($calculation['note']??''));
    if(preg_match('/\[(?:Betrag|Positionen|Prozentsatz)[^\]]*\]/u',$note))throw new RuntimeException('Der gespeicherte Abgeltungsvermerk enthält noch Platzhalter. Bitte in der Kalkulation vervollständigen.');
    return ['document_type'=>'settlement','case_no'=>$number,'vn'=>$vn,'location'=>$location,
        'date'=>(new DateTimeImmutable('now',new DateTimeZone('Europe/Berlin')))->format('Y-m-d'),
        'regulator'=>'Christian Wächter · SV-Netzwerk','vat'=>(float)$calculation['vat_rate'],'lines'=>$lines,
        'note'=>'Abgeltung gemäß gespeicherter Kalkulation Nr. '.(int)$calculation['id']."\n\n".implode("\n\n",$sections)."\n\n".
            'Mit Auszahlung des vereinbarten Abgeltungsbetrages sind ausschließlich die ausdrücklich als abgegolten bezeichneten Schadenpositionen abschließend reguliert.'.($note!==''?"\n\n".$note:'')];
}

function paRetireWrongForms(array $children): int {
    $count=0;
    foreach($children as$file){
        if(!str_contains((string)($file['name']??''),'_Pauschale-Abgeltung_Formular-Entwurf_'))continue;
        if(hash('sha256',ionosBytes((string)$file['id']))!=='8684e79c02b54fa82fc3423c44611e6411ee7985556f3425d02e0635c77311b4')continue;
        ionosWrite(['name'=>'NICHT-VERWENDEN_Falsches-Regulierer-Formular_'.$file['name'],
            'appProperties'=>array_merge((array)($file['appProperties']??[]),['svSuperseded'=>'wrong-template'])],null,(string)$file['id']);++$count;
    }
    return $count;
}

/** Fill the existing SV-Netzwerk calculation form; signatures remain unsigned. */
function paPrepareForm(string $folder,array $user,bool $retireOnly=false): array {
    requireCaseFolderAccess($folder,$user);
    $children=ionosList(['q'=>"'".str_replace("'","\\'",$folder)."' in parents and trashed=false",'pageSize'=>1000])['files'];
    $parent='';$meta=[];
    foreach($children as$file){
        if(($file['mimeType']??'')==='application/vnd.google-apps.folder'&&str_starts_with((string)$file['name'],'08_'))$parent=(string)$file['id'];
        if(($file['name']??'')==='00_Falldaten.json')$meta=json_decode(ionosBytes((string)$file['id']),true,512,JSON_THROW_ON_ERROR);
    }
    $retired=$parent!==''?paRetireWrongForms(ionosList(['q'=>"'".$parent."' in parents and trashed=false",'pageSize'=>1000])['files']):0;
    if($retireOnly)return ['retired'=>$retired];
    $statement=db()->prepare('SELECT * FROM bki_calculations WHERE folder_id=:folder ORDER BY id DESC LIMIT 1');
    $statement->execute([':folder'=>$folder]);$calculation=$statement->fetch(PDO::FETCH_ASSOC);
    if(!is_array($calculation))throw new RuntimeException('Keine gespeicherte Kalkulation für diesen Fall vorhanden. Bitte die Abgeltung in der Kalkulation festlegen und speichern.');
    $payload=paSettlementPayload($calculation,$meta);$document=\SvNetSettlement\build($payload);$bytes=$document['bytes'];
    if($parent==='')$parent=(string)ionosWrite(['name'=>'08_Abschluss','mimeType'=>'application/vnd.google-apps.folder','parents'=>[$folder]])['id'];
    $prefix=preg_replace('/[^\p{L}\p{N}._-]/u','_',$payload['case_no']);
    $name=$prefix.'_SV-Netzwerk_Abgeltungsvereinbarung_Entwurf_Kalkulation-'.(int)$calculation['id'].'_'.date('Y-m-d_His').'.pdf';
    $file=ionosWrite(['name'=>$name,'mimeType'=>'application/pdf','parents'=>[$parent]],$bytes);
    if(!hash_equals(hash('sha256',$bytes),hash('sha256',ionosBytes((string)$file['id']))))throw new RuntimeException('Gespeichertes Originalformular konnte nicht verifiziert werden.');
    return ['id'=>$file['id'],'name'=>$name,'type'=>'pauschale_abgeltung','template_used'=>'SV-Netzwerk Kalkulation / PDF Abgeltungsvereinbarung',
        'calculation_id'=>(int)$calculation['id'],'payout'=>$document['payout'],
        'webViewLink'=>'/intern/api/case-file-browser.php?action=file&folder_id='.rawurlencode($folder).'&file_id='.rawurlencode((string)$file['id']),
        'status'=>'filled_unsigned_draft'];
}
