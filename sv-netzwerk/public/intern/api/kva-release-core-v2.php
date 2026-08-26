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

function krV2Handle(array $user): void
{
    $action = (string)($_GET['action'] ?? 'status');
    try {
        if ($action === 'status') apiJson(['ok'=>true,'sender'=>KR_SENDER,'sparkassen_bcc'=>KR_ARCHIVE]);
        $folder = trim((string)($_REQUEST['folder_id'] ?? ''));
        requireCaseFolderAccess($folder, $user);
        if ($action === 'files') apiJson(['ok'=>true,'files'=>krKvas($folder)]);
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
            $net = krMoney($result['net_total'] ?? null);
            $vat = krMoney($result['vat_total'] ?? null);
            $gross = krMoney($result['gross_total'] ?? null);
            if ($net === null && $gross !== null && $vat !== null) $net = round($gross - $vat, 2);
            if ($gross === null && $net !== null && $vat !== null) $gross = round($net + $vat, 2);
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
            $email = trim((string)($result['email'] ?? ''));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $email = '';
                $warnings[] = 'Die geschäftliche E-Mail-Adresse des KVA-Absenders wurde nicht sicher erkannt.';
            }
            $preview = [
                'folder'=>$folder,
                'case_no'=>krCaseNo($folder),
                'source'=>$name,
                'company'=>trim((string)($result['company'] ?? '')),
                'email'=>$email,
                'quote_number'=>trim((string)($result['quote_number'] ?? '')),
                'net'=>$net,
                'gross'=>$gross,
                'insurer'=>$insurer,
                'sparkasse'=>$sparkasse,
                'issued'=>time(),
            ];
            $missing = [];
            foreach (['case_no'=>'Schaden-Nr.','company'=>'Firma','email'=>'E-Mail-Adresse','quote_number'=>'KVA-Nummer','insurer'=>'Versicherer','net'=>'Netto-Gesamtbetrag','gross'=>'Brutto-Gesamtbetrag'] as $key=>$label) {
                if ($preview[$key] === null || $preview[$key] === '') $missing[] = $label;
            }
            apiJson(['ok'=>true,'draft'=>$preview+[
                'subject'=>'KVA-Freigabe · Schaden-Nr. '.$preview['case_no'],
                'body'=>krDraftBody($preview),
                'sender'=>KR_SENDER,
                'bcc'=>$sparkasse ? KR_ARCHIVE : '',
                'warnings'=>$warnings,
                'missing'=>$missing,
                'token'=>krSign($preview),
            ]]);
        }
        if ($action === 'send') {
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') apiError(405, 'POST erforderlich.');
            $input = requestBody();
            $preview = krVerify((string)($input['token'] ?? ''));
            if (!hash_equals($folder, (string)$preview['folder']) || !hash_equals(krCaseNo($folder), (string)$preview['case_no'])) throw new RuntimeException('Aktiver Fall hat sich geändert.');
            if ($preview['company'] === '' || !filter_var((string)$preview['email'], FILTER_VALIDATE_EMAIL) || $preview['quote_number'] === '' || $preview['insurer'] === '' || !is_numeric($preview['net']) || !is_numeric($preview['gross'])) throw new RuntimeException('Pflichtangaben fehlen.');
            $to = trim((string)($input['to'] ?? $preview['email']));
            $body = trim((string)($input['body'] ?? ''));
            if ($body === '') throw new RuntimeException('E-Mail-Text fehlt.');
            $subject = 'KVA-Freigabe · Schaden-Nr. '.$preview['case_no'];
            $message = ['subject'=>$subject,'body'=>['contentType'=>'HTML','content'=>'<p>'.nl2br(htmlspecialchars($body,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8')).'</p>'],'toRecipients'=>[krRec($to)]];
            if ($preview['sparkasse']) $message['bccRecipients'] = [krRec(KR_ARCHIVE)];
            $response = krHttp('POST','https://graph.microsoft.com/v1.0/users/'.rawurlencode(KR_SENDER).'/sendMail',['Authorization: Bearer '.krMs(),'Content-Type: application/json'],json_encode(['message'=>$message,'saveToSentItems'=>true],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            if ($response['status'] < 200 || $response['status'] >= 300) throw new RuntimeException('KVA-Freigabe konnte nicht versendet werden.');
            apiJson(['ok'=>true,'subject'=>$subject,'sender'=>KR_SENDER,'recipient'=>$to,'bcc'=>$preview['sparkasse']?KR_ARCHIVE:'']);
        }
        apiError(404, 'Unbekannte Aktion.');
    } catch (Throwable $error) {
        apiError(500, $error->getMessage());
    }
}
