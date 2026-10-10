<?php
declare(strict_types=1);
require_once __DIR__.'/review-correspondence.php';

function drMoney(mixed $value): ?float {
    if ($value === null || $value === '') return null;
    if (is_int($value) || is_float($value)) return round((float)$value, 2);
    $text = preg_replace('/(?:EUR|€|\s)/iu', '', trim((string)$value)) ?? '';
    if (str_contains($text, ',')) $text = str_replace(',', '.', str_replace('.', '', $text));
    return is_numeric($text) ? round((float)$text, 2) : null;
}

function drEnergyNumber(mixed $value): ?float {
    if ($value===null || $value==='') return null;
    $text=preg_replace('/(?:EUR|€|\s)/iu','',trim((string)$value))??'';
    if (str_contains($text,',')) $text=str_replace(',','.',str_replace('.','',$text));
    return is_numeric($text)?round((float)$text,4):null;
}

function drValidate(array $preview, array $input): array {
    $out = [];
    foreach (['company','number','date','reason','assessment','to','cc','bcc'] as $key) $out[$key] = trim((string)($input[$key] ?? ''));
    $out['decision'] = (string)($input['decision'] ?? '');
    $out['gross'] = drMoney($input['gross'] ?? null);
    $out['release_amount'] = drMoney($input['release_amount'] ?? null);
    $out['net'] = drMoney($input['net'] ?? null);
    $out['vat'] = drMoney($input['vat'] ?? null);
    $out['energy_kwh'] = drEnergyNumber($input['energy_kwh'] ?? null);
    if (trim((string)($input['energy_kwh'] ?? ''))!=='' && $out['energy_kwh']===null) throw new RuntimeException('Bitte einen gültigen Energieverbrauch in kWh eingeben.');
    $out['energy_rate'] = drEnergyNumber($input['energy_rate'] ?? '0,35');
    $out['energy_vn'] = trim((string)($input['energy_vn'] ?? ''));
    $out['energy_amount'] = null;
    if ($out['energy_kwh'] !== null) {
        if ($out['energy_kwh']<0 || $out['energy_rate']===null || $out['energy_rate']<=0) throw new RuntimeException('Verbrauch muss mindestens null und der Strompreis größer als null sein.');
        $out['energy_amount']=round($out['energy_kwh']*$out['energy_rate'],2);
    } elseif (isset($preview['analysis']['energy_kwh']) && is_numeric($preview['analysis']['energy_kwh'])) throw new RuntimeException('Den nachgewiesenen Energieverbrauch bitte separat übernehmen.');
    if (!in_array($out['decision'], ['approved','rejected'], true)) throw new RuntimeException('Bitte freigegeben oder nicht freigegeben auswählen.');
    if ($out['company'] === '' || $out['number'] === '' || $out['gross'] === null || $out['gross'] <= 0) throw new RuntimeException('Aussteller, Belegnummer und Original-Bruttobetrag fehlen.');
    if (($preview['mode'] ?? '') === 'review') {
        if (empty($preview['analysis']) || ($input['review_confirmed'] ?? false) !== true || $out['assessment'] === '') throw new RuntimeException('Prüfergebnis bitte fachlich kontrollieren und bestätigen.');
        if ($out['decision']==='approved' && isset($preview['analysis']['currency']) && strtoupper((string)$preview['analysis']['currency'])!=='EUR') throw new RuntimeException('Die Originalwährung ist nicht EUR. Bitte die Währung und Umrechnung zuerst klären.');
    } elseif (($preview['mode'] ?? '') === 'direct') {
        if ($out['reason'] === '') throw new RuntimeException('Bitte den Grund für die Übernahme ohne erneute Prüfung angeben.');
    } else throw new RuntimeException('Ungültiger Prüfmodus.');
    if ($out['reason'] === '') throw new RuntimeException('Bitte die eigene fachliche Stellungnahme für die Mail ergänzen oder einen passenden Standardtext auswählen.');
    reviewAssertCorrespondence($out['reason']);
    if ($out['decision'] === 'rejected') {
        if ($out['reason'] === '') throw new RuntimeException('Bitte die Nichtfreigabe begründen.');
        $out['release_amount'] = 0.0;
    } elseif ($out['release_amount'] === null || $out['release_amount'] <= 0 || $out['release_amount'] > $out['gross']) {
        throw new RuntimeException('Freigabebetrag muss größer als null sein und darf den Original-Bruttobetrag nicht überschreiten.');
    }
    if ($out['net'] !== null && $out['vat'] !== null && abs($out['net'] + $out['vat'] - $out['gross']) > 0.02) throw new RuntimeException('Netto, Umsatzsteuer und Brutto sind nicht stimmig. Bitte Originalwerte kontrollieren.');
    return $out;
}

function drReviewSender(array $profile): array {
    $profile['mailbox'] = $profile['email'];
    return $profile;
}

function drSignature(string $name): string {
    if ($name === 'Christian Wächter') return $name."\nRegulierer und Bausachverständiger\nSV-Netzwerk\nDIN EN ISO/IEC 17024 zertifiziert\ncw@sv-schuett.eu\nhttps://www.sv-netzwerk.eu/";
    return $name."\nSV-Büro Marc Schütt e.K.";
}

function drBody(array $record): string {
    $v = $record['values'];
    $label = $record['kind'] === 'invoice' ? 'Rechnung' : 'Angebot';
    $status = $v['decision'] === 'approved' ? 'freigegeben' : 'nicht freigegeben';
    $article=$record['kind']==='invoice'?'die':'das';
    $reference=str_starts_with($record['case_no'],'Freie Prüfung ')?'zum Vorgang':'zur Schaden-Nr.';
    $body = "Sehr geehrte Damen und Herren,\n\n{$reference} {$record['case_no']} erhalten Sie anbei {$article} {$label} von {$v['company']}, Nr. {$v['number']}";
    if ($v['date'] !== '') $body .= ' vom '.$v['date'];
    $body .= ' über '.number_format($v['gross'], 2, ',', '.')." EUR brutto.\n\nDer Beleg wird {$status}.";
    if ($v['decision'] === 'approved') $body .= '\nFreigabebetrag: '.number_format($v['release_amount'], 2, ',', '.').' EUR brutto.';
    $body = str_replace('\\n', "\n", $body);
    // The assessment contains internal evidence checks, not an outgoing statement.
    if ($record['mode'] === 'direct') $body .= "\n\nDie Übernahme erfolgt ohne erneute Prüfung.";
    if ($v['reason'] !== '') $body .= "\n\n".$v['reason'];
    if (($v['energy_kwh']??null)!==null) {
        $rate=rtrim(rtrim(number_format($v['energy_rate'],4,',','.'),'0'),',');
        $kwh=rtrim(rtrim(number_format($v['energy_kwh'],4,',','.'),'0'),',');
        $body .= "\n\nStromkosten – separat zur Erstattung an den VN".(!empty($v['energy_vn'])?' ('.$v['energy_vn'].')':'').":\n".$kwh.' kWh × '.$rate.' EUR/kWh = '.number_format($v['energy_amount'],2,',','.')." EUR.\nDieser Betrag ist nicht im Freigabebetrag für den Auftragnehmer enthalten. Die Auszahlung an den VN erfolgt separat.";
    }
    $body .= "\n\nMit freundlichen Grüßen\n".drSignature($record['sender_name']);
    reviewAssertCorrespondence($body);
    return $body;
}

function drRecipients(string $value): array {
    $result = [];
    foreach (preg_split('/[;,\s]+/', trim($value)) ?: [] as $address) {
        if ($address === '') continue;
        if (!filter_var($address, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Ungültige Empfängeradresse: '.$address);
        $result[strtolower($address)] = ['emailAddress'=>['address'=>$address]];
    }
    return array_values($result);
}

function drSubject(array $record): string {
    $caseNo=trim((string)($record['case_no']??''));
    if ($caseNo==='' || str_starts_with($caseNo,'Freie Prüfung ')) throw new RuntimeException('Bitte die Schadennummer der Versicherung für den Mailbetreff angeben.');
    return $caseNo.' · '.($record['kind']==='invoice'?'Rechnungsprüfung':'Angebotsprüfung').' · '.$record['values']['number'].' · '.($record['values']['decision']==='approved'?'freigegeben':'nicht freigegeben');
}

function drMessage(array $record, string $bytes): array {
    $v = $record['values'];
    $to = drRecipients($v['to']); $cc = drRecipients($v['cc']); $bcc = drRecipients($v['bcc']);
    if (!$to) throw new RuntimeException('Bitte mindestens einen An-Empfänger auswählen.');
    $message = ['subject'=>drSubject($record),'body'=>['contentType'=>'Text','content'=>drBody($record)],'toRecipients'=>$to];
    if (!empty($record['sender'])) $message['from'] = ['emailAddress'=>['address'=>$record['sender'],'name'=>$record['sender_name']]];
    if ($cc) $message['ccRecipients'] = $cc;
    if ($bcc) $message['bccRecipients'] = $bcc;
    $message['attachments'] = [['@odata.type'=>'#microsoft.graph.fileAttachment','name'=>$record['file_name'],'contentType'=>$record['mime'],'contentBytes'=>base64_encode($bytes)]];
    return $message;
}
