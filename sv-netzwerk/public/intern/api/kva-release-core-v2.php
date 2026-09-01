<?php
declare(strict_types=1);

function krCaseInsurer(string $folder): string
{
    foreach (krList($folder) as $file) {
        $name = (string)($file['name'] ?? '');
        if (!preg_match('/falldaten.*\.json$/i', $name)) continue;
        try {
            $raw = krDrive('https://www.googleapis.com/drive/v3/files/'.rawurlencode((string)$file['id']).'?alt=media&supportsAllDrives=true');
            $data = json_decode($raw, true);
            $insurer = trim((string)($data['versicherer'] ?? $data['insurer'] ?? ''));
            if ($insurer !== '') return $insurer;
        } catch (Throwable) {
        }
    }
    return '';
}

function krCaseContacts(string $folder): array
{
    foreach (krList($folder) as $file) {
        if (!preg_match('/falldaten.*\.json$/i', (string)($file['name'] ?? ''))) continue;
        try {
            $data = json_decode(krDrive('https://www.googleapis.com/drive/v3/files/'.rawurlencode((string)$file['id']).'?alt=media&supportsAllDrives=true'), true);
            if (is_array($data)) return $data;
        } catch (Throwable) {
        }
    }
    return [];
}

function krMergeContacts(array $case, array $result): array
{
    $fields = ['company'=>'sanierer_firma','contact_person'=>'sanierer_ansprechpartner','street'=>'sanierer_strasse','postal_code'=>'sanierer_plz','city'=>'sanierer_ort','email'=>'sanierer_email','phone'=>'sanierer_telefon','fax'=>'sanierer_fax','website'=>'sanierer_website'];
    $updates = [];
    $hints = [];
    $contacts = [];
    foreach ($fields as $source=>$target) {
        $detected = trim((string)($result[$source] ?? ''));
        $existing = trim((string)($case[$target] ?? ''));
        $contacts[$source] = $existing !== '' ? $existing : $detected;
        if ($existing === '' && $detected !== '') $updates[$target] = $detected;
        if ($existing !== '' && $detected !== '' && krEvidenceId($existing) !== krEvidenceId($detected)) $hints[$target] = $detected;
    }
    return ['contacts'=>$contacts, 'case_contact_updates'=>$updates, 'contact_hints'=>$hints];
}

function krAnalyzeCalculation(string $name, string $mime, string $bytes): array
{
    $key = env('OPENAI_API_KEY', '');
    if ($key === '') throw new RuntimeException('OpenAI API-Key ist nicht konfiguriert.');
    $tmp = tempnam(sys_get_temp_dir(), 'kva-calc-');
    file_put_contents($tmp, $bytes);
    try {
        $upload = krHttp('POST', 'https://api.openai.com/v1/files', ['Authorization: Bearer '.$key], ['purpose'=>'user_data', 'file'=>new CURLFile($tmp, $mime, $name)]);
        $uploaded = json_decode($upload['body'], true);
        $fileId = (string)($uploaded['id'] ?? '');
        if ($upload['status'] < 200 || $upload['status'] >= 300 || $fileId === '') throw new RuntimeException('KVA konnte nicht für die Nachkalkulation vorbereitet werden.');
        $result = krOpenAiJson(
            $key,
            $fileId,
            'Lies den Kostenvoranschlag vollständig und positionsgenau. Erfinde keine Leistungen, Mengen, Einheiten oder Preise. Erfasse ausschließlich tatsächlich angebotene Hauptpositionen. Unterpositionen dürfen nur separat erscheinen, wenn sie einen eigenen Preis haben. Alternativ-, Eventual- und Bedarfspositionen kennzeichnest du und rechnest sie nicht in den angebotenen Gesamtbetrag ein. Geldbeträge werden als Dezimalzahlen ohne Währungszeichen ausgegeben. Prüfe quantity mal unit_price gegen line_total und nenne bei Abweichungen eine Warnung. Positionstexte bleiben fachlich vollständig, werden aber ohne Kopf-/Fußzeilen übernommen.',
            'Datei: '.$name.'. Antworte als JSON mit company, quote_number, quote_date, net_total, vat_rate, vat_total, gross_total, positions (Array mit position_no, description, quantity, unit, unit_price, line_total, optional als Boolean und confidence zwischen 0 und 1), warnings. Unbekannte Werte als null.'
        );
        $positions = [];
        foreach (is_array($result['positions'] ?? null) ? $result['positions'] : [] as $index=>$row) {
            if (!is_array($row)) continue;
            $description = trim((string)($row['description'] ?? ''));
            $quantity = krMoney($row['quantity'] ?? null);
            $unitPrice = krMoney($row['unit_price'] ?? null);
            $lineTotal = krMoney($row['line_total'] ?? null);
            if ($description === '' || $quantity === null || $quantity <= 0) continue;
            if ($unitPrice === null && $lineTotal !== null) $unitPrice = round($lineTotal / $quantity, 2);
            if ($lineTotal === null && $unitPrice !== null) $lineTotal = round($quantity * $unitPrice, 2);
            $positions[] = [
                'position_no'=>trim((string)($row['position_no'] ?? '')) ?: (string)($index + 1),
                'description'=>$description,
                'quantity'=>$quantity,
                'unit'=>trim((string)($row['unit'] ?? '')) ?: 'St',
                'unit_price'=>$unitPrice,
                'line_total'=>$lineTotal,
                'optional'=>(bool)($row['optional'] ?? false),
                'confidence'=>max(0, min(1, (float)($row['confidence'] ?? 0))),
            ];
        }
        if ($positions === []) throw new RuntimeException('Im KVA konnten keine belastbaren, bepreisten Positionen erkannt werden.');
        return [
            'company'=>trim((string)($result['company'] ?? '')),
            'quote_number'=>trim((string)($result['quote_number'] ?? '')),
            'quote_date'=>trim((string)($result['quote_date'] ?? '')),
            'net_total'=>krMoney($result['net_total'] ?? null),
            'vat_rate'=>krMoney($result['vat_rate'] ?? null),
            'vat_total'=>krMoney($result['vat_total'] ?? null),
            'gross_total'=>krMoney($result['gross_total'] ?? null),
            'positions'=>$positions,
            'warnings'=>array_values(array_map('strval', is_array($result['warnings'] ?? null) ? $result['warnings'] : [])),
        ];
    } finally {
        @unlink($tmp);
    }
}

function krVerifiedKva(string $folder, string $bytes): array
{
    $hash = hash('sha256', $bytes);
    foreach (krList($folder) as $file) {
        $name = (string)($file['name'] ?? '');
        if (!preg_match('/kva.*(?:pruef|prüf|verif).*\.json$/iu', $name)) continue;
        try {
            $raw = krDrive('https://www.googleapis.com/drive/v3/files/'.rawurlencode((string)$file['id']).'?alt=media&supportsAllDrives=true');
            $data = json_decode($raw, true);
            if (!is_array($data) || !hash_equals($hash, strtolower(trim((string)($data['sha256'] ?? ''))))) continue;
            return $data;
        } catch (Throwable) {
        }
    }
    return [];
}

function krReviewedKva(array $preview, array $input): array
{
    $value = static fn(string $key): string => trim((string)($input[$key] ?? $preview[$key] ?? ''));
    $reviewed = [
        'company'=>$value('company'),
        'email'=>$value('email'),
        'quote_number'=>$value('quote_number'),
        'insurer'=>$value('insurer'),
        'net'=>krMoney($input['net'] ?? $preview['net'] ?? null),
        'gross'=>krMoney($input['gross'] ?? $preview['gross'] ?? null),
        'subject'=>trim((string)($input['subject'] ?? ('KVA-Freigabe · Schaden-Nr. '.($preview['case_no'] ?? '')))),
        'body'=>trim((string)($input['body'] ?? '')),
    ];
    $missing = [];
    foreach (['company'=>'Firma','email'=>'E-Mail-Adresse','quote_number'=>'KVA-Nummer','insurer'=>'Versicherer','net'=>'Netto-Gesamtbetrag','gross'=>'Brutto-Gesamtbetrag','subject'=>'Betreff','body'=>'Freigabetext'] as $key=>$label) {
        if ($reviewed[$key] === null || $reviewed[$key] === '') $missing[] = $label;
    }
    if ($missing !== []) throw new RuntimeException('Bitte die manuell prüfbaren Pflichtfelder ergänzen: '.implode(', ', $missing).'.');
    if (!filter_var($reviewed['email'], FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Die E-Mail-Adresse des KVA-Absenders ist ungültig.');
    if ((float)$reviewed['net'] <= 0 || (float)$reviewed['gross'] <= 0 || (float)$reviewed['gross'] < (float)$reviewed['net']) throw new RuntimeException('Netto- und Brutto-Gesamtbetrag sind nicht plausibel.');
    foreach (['company','quote_number','insurer'] as $key) if (mb_strlen((string)$reviewed[$key]) > 300) throw new RuntimeException('Eine manuell geprüfte KVA-Angabe ist zu lang.');
    if (mb_strlen($reviewed['subject']) > 500) throw new RuntimeException('Der Betreff ist zu lang.');
    $reviewed['sparkasse'] = preg_match('/sparkassen.?versicherung|SV SparkassenVersicherung/i', $reviewed['insurer']) === 1;
    return $reviewed;
}

function krV2Handle(array $user): void
{
    $action = (string)($_GET['action'] ?? 'status');
    $senderProfile = krSenderProfile($user);
    $sender = (string)$senderProfile['email'];
    try {
        if ($action === 'status') apiJson(['ok'=>true,'sender'=>$sender,'sender_name'=>$senderProfile['name'],'sparkassen_bcc'=>KR_ARCHIVE]);
        $folder = trim((string)($_REQUEST['folder_id'] ?? ''));
        requireCaseFolderAccess($folder, $user);
        if ($action === 'files') apiJson(['ok'=>true,'files'=>krKvas($folder)]);
        if ($action === 'calculation_analyze') {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') apiError(405, 'POST erforderlich.');
            if (isset($_FILES['file']) && is_uploaded_file((string)($_FILES['file']['tmp_name'] ?? ''))) {
                $file = $_FILES['file'];
                if ((int)($file['size'] ?? 0) > 30 * 1024 * 1024) throw new RuntimeException('Die Datei darf höchstens 30 MB groß sein.');
                $name = basename((string)$file['name']);
                $mime = (string)(mime_content_type((string)$file['tmp_name']) ?: ($file['type'] ?? 'application/octet-stream'));
                $bytes = (string)file_get_contents((string)$file['tmp_name']);
            } else {
                ['name'=>$name,'mime'=>$mime,'bytes'=>$bytes] = krSelected($folder, trim((string)($_POST['file_id'] ?? '')));
            }
            apiJson(['ok'=>true,'source'=>$name,'analysis'=>krAnalyzeCalculation($name, $mime, $bytes)]);
        }
        if ($action === 'refresh_contacts') {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') apiError(405, 'POST erforderlich.');
            $kvas = krKvas($folder);
            if ($kvas === []) throw new RuntimeException('Im aktiven Schadenfall wurde kein KVA oder Angebot gefunden.');
            $selected = $kvas[0];
            ['name'=>$name,'mime'=>$mime,'bytes'=>$bytes] = krSelected($folder, (string)$selected['id']);
            $detectedContacts = kvaDetectedCaseContacts(krAnalyzeContacts($name, $mime, $bytes));
            if ($detectedContacts === []) throw new RuntimeException('Aus dem neuesten KVA konnten keine Saniererdaten sicher erkannt werden.');
            $contactMerge = kvaMergeCaseContacts(krCaseContacts($folder), $detectedContacts, $name, '');
            apiJson(['ok'=>true,'source'=>$name,'contacts'=>$detectedContacts,'case_contact_updates'=>$contactMerge['applied'],'contact_hints'=>$contactMerge['conflicts']]);
        }
        if ($action === 'sent_status') {
            $caseNo = krCaseNo($folder);
            db()->exec('CREATE TABLE IF NOT EXISTS kva_send_log (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, folder_id VARCHAR(160) NOT NULL, case_no VARCHAR(100) NOT NULL, subject VARCHAR(500) NOT NULL, recipient VARCHAR(320) NOT NULL, cc_json TEXT NOT NULL, bcc VARCHAR(320) NOT NULL, sent_at DATETIME NOT NULL, INDEX idx_kva_send_folder (folder_id, sent_at))');
            $query = db()->prepare('SELECT subject, recipient, cc_json, bcc, sent_at FROM kva_send_log WHERE folder_id=:folder ORDER BY sent_at DESC LIMIT 1');
            $query->execute([':folder'=>$folder]);
            $message = $query->fetch();
            if (is_array($message)) apiJson(['ok'=>true,'found'=>true,'subject'=>(string)$message['subject'],'sent_at'=>(string)$message['sent_at'],'to'=>[(string)$message['recipient']],'cc'=>json_decode((string)$message['cc_json'],true)?:[],'bcc'=>(string)$message['bcc']!==''?[(string)$message['bcc']]:[]]);
            apiJson(['ok'=>true,'found'=>false,'case_no'=>$caseNo]);
        }
        if ($action === 'analyze') {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') apiError(405, 'POST erforderlich.');
            if (isset($_FILES['file']) && is_uploaded_file((string)($_FILES['file']['tmp_name'] ?? ''))) {
                $file = $_FILES['file'];
                if ((int)($file['size'] ?? 0) > 30 * 1024 * 1024) throw new RuntimeException('Die Datei darf höchstens 30 MB groß sein.');
                $name = basename((string)$file['name']);
                $mime = (string)(mime_content_type((string)$file['tmp_name']) ?: ($file['type'] ?? 'application/octet-stream'));
                $bytes = (string)file_get_contents((string)$file['tmp_name']);
            } else {
                ['name'=>$name,'mime'=>$mime,'bytes'=>$bytes] = krSelected($folder, trim((string)($_POST['file_id'] ?? '')));
            }
            $result = krAnalyze($name, $mime, $bytes);
            $detectedContacts = kvaDetectedCaseContacts(krAnalyzeContacts($name, $mime, $bytes));
            $net = krMoney($result['net_total'] ?? null);
            $vat = krMoney($result['vat_total'] ?? null);
            $gross = krMoney($result['gross_total'] ?? null);
            $warnings = array_values(array_map('strval', is_array($result['warnings'] ?? null) ? $result['warnings'] : []));
            if (!krTotalsValid($net, $vat, $gross, $result)) {
                $net = null;
                $gross = null;
                $warnings[] = 'Die endgültigen Gesamtbeträge konnten nicht zweifelsfrei und rechnerisch bestätigt werden.';
            }
            $caseInsurer = krCaseInsurer($folder);
            $insurer = $caseInsurer !== '' ? $caseInsurer : trim((string)($result['insurer'] ?? ''));
            $sparkasse = (bool)($result['sparkassenversicherung'] ?? false)
                || preg_match('/sparkassen.?versicherung|SV SparkassenVersicherung/i', $insurer) === 1;
            if ($sparkasse && $insurer === '') $insurer = 'SV SparkassenVersicherung';
            $contactMerge = kvaMergeCaseContacts(krCaseContacts($folder), $detectedContacts, $name, trim((string)($result['quote_number'] ?? '')));
            $email = trim((string)($detectedContacts['sanierer_email'] ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $email = '';
                $warnings[] = 'Die geschäftliche E-Mail-Adresse des KVA-Absenders wurde nicht sicher erkannt.';
            }
            $preview = [
                'folder'=>$folder,
                'case_no'=>krCaseNo($folder),
                'source'=>$name,
                'company'=>trim((string)($detectedContacts['sanierer_firma'] ?? $result['company'] ?? '')),
                'email'=>$email,
                'quote_number'=>trim((string)($result['quote_number'] ?? '')),
                'net'=>$net,
                'gross'=>$gross,
                'insurer'=>$insurer,
                'sparkasse'=>$sparkasse,
                'contacts'=>$detectedContacts,
                'case_contact_updates'=>$contactMerge['applied'],
                'contact_hints'=>$contactMerge['conflicts'],
                'sender'=>$sender,
                'sender_name'=>(string)$senderProfile['name'],
                'issued'=>time(),
            ];
            $missing = [];
            foreach (['case_no'=>'Schaden-Nr.','company'=>'Firma','email'=>'E-Mail-Adresse','quote_number'=>'KVA-Nummer','insurer'=>'Versicherer','net'=>'Netto-Gesamtbetrag','gross'=>'Brutto-Gesamtbetrag'] as $key=>$label) {
                if ($preview[$key] === null || $preview[$key] === '') $missing[] = $label;
            }
            apiJson(['ok'=>true,'draft'=>$preview+[
                'subject'=>'KVA-Freigabe · Schaden-Nr. '.$preview['case_no'],
                'body'=>krDraftBody($preview),
                'sender'=>$sender,
                'bcc'=>$sparkasse ? KR_ARCHIVE : '',
                'warnings'=>$warnings,
                'contact_hints'=>$contactMerge['conflicts'],
                'missing'=>$missing,
                'token'=>krSign($preview),
            ]]);
        }
        if ($action === 'send') {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') apiError(405, 'POST erforderlich.');
            $input = requestBody();
            $preview = krVerify((string)($input['token'] ?? ''));
            if (!hash_equals($folder, (string)$preview['folder']) || !hash_equals(krCaseNo($folder), (string)$preview['case_no'])) throw new RuntimeException('Aktiver Fall hat sich geändert.');
            if (!hash_equals($sender, (string)($preview['sender'] ?? ''))) throw new RuntimeException('Der angemeldete Benutzer hat sich geändert. Bitte KVA erneut auslesen.');
            $reviewed = krReviewedKva($preview, $input);
            $to = $reviewed['email'];
            $cc = [];
            foreach (preg_split('/[;,\s]+/', trim((string)($input['cc'] ?? ''))) ?: [] as $address) {
                if ($address === '') continue;
                if (!filter_var($address, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Ungültige CC-E-Mail-Adresse: '.$address);
                $cc[strtolower($address)] = krRec($address);
            }
            $body = $reviewed['body'];
            $subject = preg_replace('/[\r\n]+/u', ' ', $reviewed['subject']) ?? $reviewed['subject'];
            $message = ['subject'=>$subject,'body'=>['contentType'=>'HTML','content'=>'<p>'.nl2br(htmlspecialchars($body,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')).'</p>'],'toRecipients'=>[krRec($to)]];
            if ($cc !== []) $message['ccRecipients'] = array_values($cc);
            if ($reviewed['sparkasse']) $message['bccRecipients'] = [krRec(KR_ARCHIVE)];
            $response = krHttp('POST','https://graph.microsoft.com/v1.0/users/'.rawurlencode($sender).'/sendMail',['Authorization: Bearer '.krMs(),'Content-Type: application/json'],json_encode(['message'=>$message,'saveToSentItems'=>true],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            if ($response['status'] < 200 || $response['status'] >= 300) {
                $graph = json_decode($response['body'], true);
                $code = trim((string)($graph['error']['code'] ?? ''));
                $detail = trim((string)($graph['error']['message'] ?? ''));
                throw new RuntimeException('KVA-Freigabe konnte über '.$sender.' nicht versendet werden'.($code !== '' ? ' (Microsoft: '.$code.')' : '.').($detail !== '' ? ' '.$detail : ''));
            }
            db()->exec('CREATE TABLE IF NOT EXISTS kva_send_log (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, folder_id VARCHAR(160) NOT NULL, case_no VARCHAR(100) NOT NULL, subject VARCHAR(500) NOT NULL, recipient VARCHAR(320) NOT NULL, cc_json TEXT NOT NULL, bcc VARCHAR(320) NOT NULL, sent_at DATETIME NOT NULL, INDEX idx_kva_send_folder (folder_id, sent_at))');
            $log = db()->prepare('INSERT INTO kva_send_log (folder_id, case_no, subject, recipient, cc_json, bcc, sent_at) VALUES (:folder,:case_no,:subject,:recipient,:cc,:bcc,NOW())');
            $log->execute([':folder'=>$folder,':case_no'=>$preview['case_no'],':subject'=>$subject,':recipient'=>$to,':cc'=>json_encode(array_keys($cc),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),':bcc'=>$reviewed['sparkasse']?KR_ARCHIVE:'']);
            apiJson(['ok'=>true,'subject'=>$subject,'sender'=>$sender,'recipient'=>$to,'cc'=>array_keys($cc),'bcc'=>$reviewed['sparkasse']?KR_ARCHIVE:'']);
        }
        apiError(404, 'Unbekannte Aktion.');
    } catch (Throwable $error) {
        apiError(500, $error->getMessage());
    }
}
