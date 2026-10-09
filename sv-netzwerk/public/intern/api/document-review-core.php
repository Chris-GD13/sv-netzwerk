<?php
declare(strict_types=1);

function drMoney(mixed $value): ?float {
    if ($value === null || $value === '') return null;
    if (is_int($value) || is_float($value)) return round((float)$value, 2);
    $text = preg_replace('/(?:EUR|€|\s)/iu', '', trim((string)$value)) ?? '';
    if (str_contains($text, ',')) $text = str_replace(',', '.', str_replace('.', '', $text));
    return is_numeric($text) ? round((float)$text, 2) : null;
}

function drValidate(array $preview, array $input): array {
    $out = [];
    foreach (['company','number','date','reason','assessment','to','cc','bcc'] as $key) $out[$key] = trim((string)($input[$key] ?? ''));
    $out['decision'] = (string)($input['decision'] ?? '');
    $out['gross'] = drMoney($input['gross'] ?? null);
    $out['release_amount'] = drMoney($input['release_amount'] ?? null);
    $out['net'] = drMoney($input['net'] ?? null);
    $out['vat'] = drMoney($input['vat'] ?? null);
    if (!in_array($out['decision'], ['approved','rejected'], true)) throw new RuntimeException('Bitte freigegeben oder nicht freigegeben auswählen.');
    if ($out['company'] === '' || $out['number'] === '' || $out['gross'] === null || $out['gross'] <= 0) throw new RuntimeException('Aussteller, Belegnummer und Original-Bruttobetrag fehlen.');
    if (($preview['mode'] ?? '') === 'review') {
        if (empty($preview['analysis']) || ($input['review_confirmed'] ?? false) !== true || $out['assessment'] === '') throw new RuntimeException('Prüfergebnis bitte fachlich kontrollieren und bestätigen.');
        if ($out['decision']==='approved' && isset($preview['analysis']['currency']) && strtoupper((string)$preview['analysis']['currency'])!=='EUR') throw new RuntimeException('Die Originalwährung ist nicht EUR. Bitte die Währung und Umrechnung zuerst klären.');
    } elseif (($preview['mode'] ?? '') === 'direct') {
        if ($out['reason'] === '') throw new RuntimeException('Bitte den Grund für die Übernahme ohne erneute Prüfung angeben.');
    } else throw new RuntimeException('Ungültiger Prüfmodus.');
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
    if (($profile['name'] ?? '') === 'Christian Wächter') $profile['email'] = 'cw@sv-netzwerk.eu';
    return $profile;
}

function drSignature(string $name): string {
    if ($name === 'Christian Wächter') return $name."\nRegulierer und Bausachverständiger\nSV-Netzwerk\nDIN EN ISO/IEC 17024 zertifiziert\ncw@sv-netzwerk.eu\nhttps://www.sv-netzwerk.eu/";
    return $name."\nSV-Büro Marc Schütt e.K.";
}

function drBody(array $record): string {
    $v = $record['values'];
    $label = $record['kind'] === 'invoice' ? 'Rechnung' : 'Angebot';
    $status = $v['decision'] === 'approved' ? 'freigegeben' : 'nicht freigegeben';
    $body = "Sehr geehrte Damen und Herren,\n\nzur Schaden-Nr. {$record['case_no']} erhalten Sie anbei die {$label} von {$v['company']}, Nr. {$v['number']}";
    if ($v['date'] !== '') $body .= ' vom '.$v['date'];
    $body .= ' über '.number_format($v['gross'], 2, ',', '.')." EUR brutto.\n\nDer Beleg wird {$status}.";
    if ($v['decision'] === 'approved') $body .= '\nFreigabebetrag: '.number_format($v['release_amount'], 2, ',', '.').' EUR brutto.';
    $body = str_replace('\\n', "\n", $body);
    if ($record['mode'] === 'direct') $body .= "\n\nDie Übernahme erfolgt ohne erneute Prüfung. Grund: ".$v['reason'];
    else $body .= "\n\nPrüfergebnis: ".$v['assessment'].($v['reason'] !== '' ? "\nBegründung: ".$v['reason'] : '');
    $body .= "\n\nMit freundlichen Grüßen\n".drSignature($record['sender_name']);
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

function drMessage(array $record, string $bytes): array {
    $v = $record['values'];
    $to = drRecipients($v['to']); $cc = drRecipients($v['cc']); $bcc = drRecipients($v['bcc']);
    if (!$to) throw new RuntimeException('Bitte mindestens einen An-Empfänger auswählen.');
    $message = ['subject'=>$record['subject'],'body'=>['contentType'=>'Text','content'=>drBody($record)],'toRecipients'=>$to];
    if ($cc) $message['ccRecipients'] = $cc;
    if ($bcc) $message['bccRecipients'] = $bcc;
    $message['attachments'] = [['@odata.type'=>'#microsoft.graph.fileAttachment','name'=>$record['file_name'],'contentType'=>$record['mime'],'contentBytes'=>base64_encode($bytes)]];
    return $message;
}
