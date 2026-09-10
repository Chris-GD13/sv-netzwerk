<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/profile-routing.php';

function waEnv(string $key, string $default = ''): string
{
    $value = getenv($key);
    return $value === false || trim($value) === '' ? $default : trim($value);
}

function waProfiles(): array
{
    return [
        'christian' => ['name'=>'Christian Wächter','number'=>'+4973673103045','phone_id'=>waEnv('WHATSAPP_CHRISTIAN_PHONE_NUMBER_ID','1282747221593843'),'waba_id'=>waEnv('WHATSAPP_CHRISTIAN_WABA_ID','2484676038720950'),'access_token'=>waEnv('WHATSAPP_CHRISTIAN_ACCESS_TOKEN',waEnv('WHATSAPP_ACCESS_TOKEN'))],
        'holger' => ['name'=>'Holger Roth','number'=>'+491731645162','phone_id'=>waEnv('WHATSAPP_HOLGER_PHONE_NUMBER_ID'),'waba_id'=>waEnv('WHATSAPP_HOLGER_WABA_ID'),'access_token'=>waEnv('WHATSAPP_HOLGER_ACCESS_TOKEN')],
        'marc' => ['name'=>'Marc Schütt','number'=>'+4923926592751','phone_id'=>waEnv('WHATSAPP_MARC_PHONE_NUMBER_ID'),'waba_id'=>waEnv('WHATSAPP_MARC_WABA_ID'),'access_token'=>waEnv('WHATSAPP_MARC_ACCESS_TOKEN')],
    ];
}

function waTokenKey(): string
{
    $secret = waEnv('WHATSAPP_TOKEN_ENCRYPTION_KEY', waEnv('WHATSAPP_APP_SECRET'));
    if ($secret === '') throw new RuntimeException('Der sichere WhatsApp-Schlüssel ist noch nicht eingerichtet.');
    return hash('sha256', 'sv-netzwerk:whatsapp:v1:' . $secret, true);
}

function waEncryptToken(string $token): string
{
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($token, 'aes-256-gcm', waTokenKey(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) throw new RuntimeException('WhatsApp-Verbindung konnte nicht sicher gespeichert werden.');
    return base64_encode($iv . $tag . $cipher);
}

function waDecryptToken(string $payload): string
{
    $raw = base64_decode($payload, true);
    if ($raw === false || strlen($raw) < 29) return '';
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', waTokenKey(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $plain === false ? '' : $plain;
}

function waEnsureSchema(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS whatsapp_profile_connections (
        profile_key VARCHAR(32) PRIMARY KEY,
        phone_number_id VARCHAR(190) NOT NULL,
        waba_id VARCHAR(190) NOT NULL,
        access_token_ciphertext TEXT NOT NULL,
        display_phone_number VARCHAR(64) NOT NULL,
        verified_name VARCHAR(255) NULL,
        connected_by VARCHAR(255) NOT NULL,
        connected_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS whatsapp_case_links (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        profile_key VARCHAR(32) NOT NULL,
        contact_phone VARCHAR(32) NOT NULL,
        contact_type VARCHAR(24) NOT NULL,
        folder_id VARCHAR(190) NOT NULL,
        case_no VARCHAR(190) NULL,
        last_outbound_wamid VARCHAR(190) NULL,
        valid_until DATETIME NOT NULL,
        created_by VARCHAR(255) NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        UNIQUE KEY uq_wa_case_contact (profile_key,contact_phone,folder_id),
        KEY idx_wa_context (profile_key,last_outbound_wamid),
        KEY idx_wa_contact (profile_key,contact_phone,valid_until)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    db()->exec("CREATE TABLE IF NOT EXISTS whatsapp_messages (
        wamid VARCHAR(190) PRIMARY KEY,
        profile_key VARCHAR(32) NOT NULL,
        sender_phone VARCHAR(32) NOT NULL,
        direction VARCHAR(12) NOT NULL,
        message_type VARCHAR(32) NOT NULL,
        original_name VARCHAR(500) NULL,
        caption TEXT NULL,
        media_id VARCHAR(190) NULL,
        folder_id VARCHAR(190) NULL,
        case_no VARCHAR(190) NULL,
        contact_type VARCHAR(24) NULL,
        drive_file_id VARCHAR(190) NULL,
        status VARCHAR(32) NOT NULL,
        error_text VARCHAR(1000) NULL,
        received_at DATETIME NOT NULL,
        KEY idx_wa_messages_profile (profile_key,received_at),
        KEY idx_wa_messages_folder (folder_id,received_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function waConnection(string $profileKey): array
{
    $profile = waProfiles()[$profileKey] ?? null;
    if (!$profile) return ['phone_id'=>'','waba_id'=>'','token'=>''];
    $stmt = db()->prepare('SELECT phone_number_id,waba_id,access_token_ciphertext,display_phone_number,verified_name FROM whatsapp_profile_connections WHERE profile_key=:profile LIMIT 1');
    $stmt->execute([':profile'=>$profileKey]);
    $row = $stmt->fetch();
    if (is_array($row)) {
        $token = waDecryptToken((string)$row['access_token_ciphertext']);
        if ($token !== '') return ['phone_id'=>(string)$row['phone_number_id'],'waba_id'=>(string)$row['waba_id'],'token'=>$token,'display_phone_number'=>(string)$row['display_phone_number'],'verified_name'=>(string)($row['verified_name']??'')];
    }
    return ['phone_id'=>(string)$profile['phone_id'],'waba_id'=>(string)($profile['waba_id']??''),'token'=>(string)($profile['access_token']??''),'display_phone_number'=>(string)$profile['number'],'verified_name'=>(string)$profile['name']];
}

function waHttp(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>120]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($response === false || $error !== '') throw new RuntimeException('Externe WhatsApp-Verbindung fehlgeschlagen.');
    return ['status'=>$status,'body'=>(string)$response,'content_type'=>$contentType];
}

function waProfileByPhoneId(string $phoneId): ?array
{
    foreach (waProfiles() as $key => $profile) {
        $connection = waConnection($key);
        if ($connection['phone_id'] !== '' && hash_equals($connection['phone_id'], $phoneId)) return ['key'=>$key,'connection'=>$connection] + $profile;
    }
    return null;
}

function waTargetProfile(array $user): string
{
    $selected = svnetSelectedProfile($user, (string)($_SESSION['svnet_selected_expert'] ?? ''));
    if (!array_key_exists($selected, waProfiles())) throw new RuntimeException('Für dieses Bearbeiterprofil ist keine WhatsApp-Anbindung vorgesehen.');
    return $selected;
}

function waConfigured(array $connection): bool
{
    return $connection['phone_id'] !== '' && $connection['token'] !== '' && waEnv('WHATSAPP_APP_SECRET') !== '' && waEnv('WHATSAPP_VERIFY_TOKEN') !== '' && waEnv('WHATSAPP_APPOINTMENT_TEMPLATE') !== '';
}

function waReadiness(array $connection): array
{
    if (!waConfigured($connection)) return ['ready'=>false,'phone_status'=>'','template_status'=>'','state'=>'WhatsApp ist technisch noch nicht eingerichtet.'];
    $phone = waGraph('GET', rawurlencode((string)$connection['phone_id']) . '?fields=status', null, (string)$connection['token']);
    $phoneStatus = strtoupper(trim((string)($phone['status'] ?? '')));
    $templateStatus = '';
    if ((string)$connection['waba_id'] !== '') {
        $templateName = waEnv('WHATSAPP_APPOINTMENT_TEMPLATE');
        $templates = waGraph('GET', rawurlencode((string)$connection['waba_id']) . '/message_templates?' . http_build_query([
            'fields'=>'name,status,language',
            'name'=>$templateName,
            'limit'=>50,
        ]), null, (string)$connection['token']);
        $language = waEnv('WHATSAPP_TEMPLATE_LANGUAGE', 'de');
        foreach (($templates['data'] ?? []) as $template) {
            if ((string)($template['name'] ?? '') === $templateName && (string)($template['language'] ?? '') === $language) {
                $templateStatus = strtoupper(trim((string)($template['status'] ?? '')));
                break;
            }
        }
    }
    $ready = $phoneStatus === 'CONNECTED' && $templateStatus === 'APPROVED';
    $state = $ready ? 'versandbereit' : ($phoneStatus !== 'CONNECTED'
        ? 'Meta-Telefonnummer noch ausstehend'
        : ($templateStatus !== 'APPROVED' ? 'WhatsApp-Vorlage noch in Prüfung' : 'noch nicht versandbereit'));
    return ['ready'=>$ready,'phone_status'=>$phoneStatus,'template_status'=>$templateStatus,'state'=>$state];
}

function waNormalizePhone(string $value): string
{
    $value = preg_replace('/[^0-9+]/', '', trim($value)) ?? '';
    if (str_starts_with($value, '00')) $value = '+' . substr($value, 2);
    if (str_starts_with($value, '0')) $value = '+49' . substr($value, 1);
    if (!str_starts_with($value, '+')) $value = '+' . $value;
    return preg_match('/^\+[1-9][0-9]{7,14}$/', $value) ? $value : '';
}

function waGraph(string $method, string $path, ?array $json = null, string $accessToken = ''): array
{
    $token = $accessToken !== '' ? $accessToken : waEnv('WHATSAPP_ACCESS_TOKEN');
    if ($token === '') throw new RuntimeException('WhatsApp-Zugriff ist noch nicht eingerichtet.');
    $headers = ['Authorization: Bearer ' . $token];
    $body = null;
    if ($json !== null) {
        $headers[] = 'Content-Type: application/json';
        $body = json_encode($json, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }
    $version = waEnv('WHATSAPP_GRAPH_VERSION', 'v25.0');
    $response = waHttp($method, 'https://graph.facebook.com/' . rawurlencode($version) . '/' . ltrim($path, '/'), $headers, $body);
    $data = json_decode($response['body'], true);
    if ($response['status'] < 200 || $response['status'] >= 300) {
        $message = (string)($data['error']['message'] ?? 'WhatsApp-Anfrage wurde nicht angenommen.');
        throw new RuntimeException($message);
    }
    return is_array($data) ? $data : [];
}

function waSendAppointment(array $profile, array $connection, string $recipient, array $appointment): string
{
    $template = waEnv('WHATSAPP_APPOINTMENT_TEMPLATE');
    $result = waGraph('POST', rawurlencode((string)$connection['phone_id']) . '/messages', [
        'messaging_product'=>'whatsapp',
        'to'=>ltrim($recipient, '+'),
        'type'=>'template',
        'template'=>[
            'name'=>$template,
            'language'=>['code'=>waEnv('WHATSAPP_TEMPLATE_LANGUAGE', 'de')],
            'components'=>[['type'=>'body','parameters'=>[
                ['type'=>'text','text'=>(string)$appointment['case_no']],
                ['type'=>'text','text'=>(string)$appointment['date_time']],
                ['type'=>'text','text'=>(string)$appointment['address']],
                ['type'=>'text','text'=>(string)$profile['name']],
            ]]],
        ],
    ], (string)$connection['token']);
    $wamid = (string)($result['messages'][0]['id'] ?? '');
    if ($wamid === '') throw new RuntimeException('WhatsApp hat keine Versandbestätigung geliefert.');
    return $wamid;
}

function waSendText(array $connection, string $recipient, string $message): string
{
    $result = waGraph('POST', rawurlencode((string)$connection['phone_id']) . '/messages', [
        'messaging_product'=>'whatsapp',
        'to'=>ltrim($recipient, '+'),
        'type'=>'text',
        'text'=>['preview_url'=>false, 'body'=>$message],
    ], (string)$connection['token']);
    $wamid = (string)($result['messages'][0]['id'] ?? '');
    if ($wamid === '') throw new RuntimeException('WhatsApp hat keine Versandbestätigung geliefert.');
    return $wamid;
}

function waLinkCase(string $profile, string $phone, string $type, string $folderId, string $caseNo, string $wamid, string $createdBy): void
{
    $stmt = db()->prepare("INSERT INTO whatsapp_case_links(profile_key,contact_phone,contact_type,folder_id,case_no,last_outbound_wamid,valid_until,created_by,created_at,updated_at)
        VALUES(:p,:phone,:type,:folder,:case_no,:wamid,DATE_ADD(NOW(),INTERVAL 30 DAY),:by,NOW(),NOW())
        ON DUPLICATE KEY UPDATE contact_type=VALUES(contact_type),case_no=VALUES(case_no),last_outbound_wamid=IF(VALUES(last_outbound_wamid)='',last_outbound_wamid,VALUES(last_outbound_wamid)),valid_until=VALUES(valid_until),created_by=VALUES(created_by),updated_at=NOW()");
    $stmt->execute([':p'=>$profile,':phone'=>$phone,':type'=>$type,':folder'=>$folderId,':case_no'=>$caseNo,':wamid'=>$wamid,':by'=>$createdBy]);
}

function waRecordMessage(array $row): bool
{
    $stmt = db()->prepare("INSERT IGNORE INTO whatsapp_messages(wamid,profile_key,sender_phone,direction,message_type,original_name,caption,media_id,folder_id,case_no,contact_type,drive_file_id,status,error_text,received_at)
        VALUES(:wamid,:profile,:sender,:direction,:type,:name,:caption,:media,:folder,:case_no,:contact_type,:drive_file,:status,:error,NOW())");
    $stmt->execute([
        ':wamid'=>$row['wamid'],':profile'=>$row['profile'],':sender'=>$row['sender'],':direction'=>$row['direction'],':type'=>$row['type'],
        ':name'=>$row['name']??null,':caption'=>$row['caption']??null,':media'=>$row['media']??null,':folder'=>$row['folder']??null,
        ':case_no'=>$row['case_no']??null,':contact_type'=>$row['contact_type']??null,':drive_file'=>$row['drive_file']??null,
        ':status'=>$row['status'],':error'=>$row['error']??null,
    ]);
    return $stmt->rowCount() > 0;
}

function waResolveCase(string $profile, string $phone, string $contextId): ?array
{
    if ($contextId !== '') {
        $stmt = db()->prepare("SELECT folder_id,case_no,contact_type FROM whatsapp_case_links WHERE profile_key=:p AND contact_phone=:phone AND last_outbound_wamid=:context AND valid_until>=NOW() LIMIT 2");
        $stmt->execute([':p'=>$profile,':phone'=>$phone,':context'=>$contextId]);
        $rows = $stmt->fetchAll();
        if (count($rows) === 1) return $rows[0];
    }
    $stmt = db()->prepare("SELECT folder_id,case_no,contact_type FROM whatsapp_case_links WHERE profile_key=:p AND contact_phone=:phone AND valid_until>=NOW() ORDER BY updated_at DESC LIMIT 3");
    $stmt->execute([':p'=>$profile,':phone'=>$phone]);
    $rows = $stmt->fetchAll();
    $folders = array_values(array_unique(array_map(static fn(array $row): string => (string)$row['folder_id'], $rows)));
    return count($folders) === 1 ? $rows[0] : null;
}

function waGoogleToken(): string
{
    static $token = null;
    if ($token !== null) return $token;
    $serviceJson = waEnv('GOOGLE_DRIVE_SERVICE_ACCOUNT_JSON');
    if ($serviceJson !== '') {
        if (!str_starts_with($serviceJson, '{')) {
            $decoded = base64_decode($serviceJson, true);
            if ($decoded !== false) $serviceJson = $decoded;
        }
        $service = json_decode($serviceJson, true);
        if (is_array($service) && !empty($service['client_email']) && !empty($service['private_key'])) {
            $b64 = static fn(string $data): string => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
            $now = time();
            $head = $b64(json_encode(['alg'=>'RS256','typ'=>'JWT']));
            $claims = $b64(json_encode(['iss'=>$service['client_email'],'scope'=>'https://www.googleapis.com/auth/drive','aud'=>'https://oauth2.googleapis.com/token','iat'=>$now,'exp'=>$now+3500]));
            $input = $head . '.' . $claims;
            $signature = '';
            if (openssl_sign($input, $signature, $service['private_key'], OPENSSL_ALGO_SHA256)) {
                $response = waHttp('POST', 'https://oauth2.googleapis.com/token', ['Content-Type: application/x-www-form-urlencoded'], http_build_query(['grant_type'=>'urn:ietf:params:oauth-type:jwt-bearer','assertion'=>$input.'.'.$b64($signature)]));
                $data = json_decode($response['body'], true);
                if ($response['status'] === 200 && !empty($data['access_token'])) return $token = (string)$data['access_token'];
            }
        }
    }
    $client = waEnv('GOOGLE_DRIVE_CLIENT_ID');
    $secret = waEnv('GOOGLE_DRIVE_CLIENT_SECRET');
    $refresh = waEnv('GOOGLE_DRIVE_REFRESH_TOKEN');
    if ($client !== '' && $secret !== '' && $refresh !== '') {
        $response = waHttp('POST', 'https://oauth2.googleapis.com/token', ['Content-Type: application/x-www-form-urlencoded'], http_build_query(['client_id'=>$client,'client_secret'=>$secret,'refresh_token'=>$refresh,'grant_type'=>'refresh_token']));
        $data = json_decode($response['body'], true);
        if ($response['status'] === 200 && !empty($data['access_token'])) return $token = (string)$data['access_token'];
    }
    throw new RuntimeException('Google Drive ist für WhatsApp-Dateien nicht verbunden.');
}

function waDriveRequest(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $headers[] = 'Authorization: Bearer ' . waGoogleToken();
    $response = waHttp($method, $url, $headers, $body);
    if ($response['status'] < 200 || $response['status'] >= 300) throw new RuntimeException('WhatsApp-Datei konnte nicht in Google Drive gespeichert werden.');
    return $response;
}

function waDriveFolder(string $parentId, string $name): string
{
    $query = "'" . str_replace("'", "\\'", $parentId) . "' in parents and trashed=false and name='" . str_replace("'", "\\'", $name) . "' and mimeType='application/vnd.google-apps.folder'";
    $response = waDriveRequest('GET', 'https://www.googleapis.com/drive/v3/files?' . http_build_query(['q'=>$query,'fields'=>'files(id)','pageSize'=>2,'supportsAllDrives'=>'true']));
    $data = json_decode($response['body'], true);
    if (!empty($data['files'][0]['id'])) return (string)$data['files'][0]['id'];
    $response = waDriveRequest('POST', 'https://www.googleapis.com/drive/v3/files?supportsAllDrives=true&fields=id', ['Content-Type: application/json'], json_encode(['name'=>$name,'mimeType'=>'application/vnd.google-apps.folder','parents'=>[$parentId]], JSON_UNESCAPED_SLASHES));
    $data = json_decode($response['body'], true);
    if (empty($data['id'])) throw new RuntimeException('Zielordner für WhatsApp-Datei konnte nicht angelegt werden.');
    return (string)$data['id'];
}

function waDriveUpload(string $folderId, string $name, string $mime, string $bytes, string $classificationHint = ''): string
{
    $category = str_starts_with($mime, 'image/') ? '02_Fotos' : (preg_match('/\b(?:kva|kostenvoranschlag|angebot|rechnung)\b/ui', $name . ' ' . $classificationHint) ? '04_Rechnungen_KVA' : '07_Korrespondenz');
    $target = waDriveFolder($folderId, $category);
    $boundary = 'svnetwa' . bin2hex(random_bytes(8));
    $meta = ['name'=>$name,'mimeType'=>$mime,'parents'=>[$target],'appProperties'=>['svSource'=>'whatsapp']];
    $body = '--'.$boundary."\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n".json_encode($meta,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\r\n--".$boundary."\r\nContent-Type: ".$mime."\r\n\r\n".$bytes."\r\n--".$boundary.'--';
    $response = waDriveRequest('POST', 'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&supportsAllDrives=true&fields=id', ['Content-Type: multipart/related; boundary='.$boundary], $body);
    $data = json_decode($response['body'], true);
    if (empty($data['id'])) throw new RuntimeException('Google Drive hat die WhatsApp-Datei nicht bestätigt.');
    return (string)$data['id'];
}

function waDownloadMedia(string $mediaId, string $accessToken): array
{
    $meta = waGraph('GET', rawurlencode($mediaId), null, $accessToken);
    $url = (string)($meta['url'] ?? '');
    if ($url === '') throw new RuntimeException('WhatsApp-Mediendatei besitzt keine Download-Adresse.');
    $response = waHttp('GET', $url, ['Authorization: Bearer '.$accessToken]);
    if ($response['status'] !== 200) throw new RuntimeException('WhatsApp-Mediendatei konnte nicht geladen werden.');
    if (strlen($response['body']) > 25 * 1024 * 1024) throw new RuntimeException('WhatsApp-Datei ist größer als 25 MB.');
    $mime = strtolower(trim(explode(';', (string)($meta['mime_type'] ?? $response['content_type'] ?? 'application/octet-stream'))[0]));
    $allowed = ['image/jpeg','image/png','image/webp','image/heic','application/pdf','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document','application/vnd.ms-excel','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
    if (!in_array($mime, $allowed, true)) throw new RuntimeException('Dieser WhatsApp-Dateityp wird aus Sicherheitsgründen nicht übernommen.');
    return ['bytes'=>$response['body'],'mime'=>$mime];
}

function waSafeFileName(string $name, string $mime): string
{
    $extensions = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/heic'=>'heic','application/pdf'=>'pdf','application/msword'=>'doc','application/vnd.openxmlformats-officedocument.wordprocessingml.document'=>'docx','application/vnd.ms-excel'=>'xls','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'=>'xlsx'];
    $name = preg_replace('/[^A-Za-z0-9ÄÖÜäöüß._-]+/u', '-', basename(trim($name))) ?? '';
    if ($name === '' || $name === 'Dokument') $name = 'Unterlage.' . ($extensions[$mime] ?? 'bin');
    return 'WhatsApp_' . date('Y-m-d_His') . '_' . trim($name, '-_.');
}

function waHandleWebhook(): never
{
    $verifyToken = waEnv('WHATSAPP_VERIFY_TOKEN');
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $mode = (string)($_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '');
        $token = (string)($_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '');
        $challenge = (string)($_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '');
        if ($verifyToken !== '' && $mode === 'subscribe' && hash_equals($verifyToken, $token)) { header('Content-Type: text/plain'); echo $challenge; exit; }
        http_response_code(403); exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
    $raw = file_get_contents('php://input') ?: '';
    $secret = waEnv('WHATSAPP_APP_SECRET');
    $signature = (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '');
    $expected = 'sha256=' . hash_hmac('sha256', $raw, $secret);
    if ($secret === '' || $signature === '' || !hash_equals($expected, $signature)) { http_response_code(401); exit; }
    waEnsureSchema();
    $payload = json_decode($raw, true);
    foreach (($payload['entry'] ?? []) as $entry) foreach (($entry['changes'] ?? []) as $change) {
        $value = is_array($change['value'] ?? null) ? $change['value'] : [];
        $profile = waProfileByPhoneId((string)($value['metadata']['phone_number_id'] ?? ''));
        if (!$profile) continue;
        foreach (($value['messages'] ?? []) as $message) {
            $wamid = (string)($message['id'] ?? '');
            $sender = waNormalizePhone((string)($message['from'] ?? ''));
            if ($wamid === '' || $sender === '') continue;
            $type = (string)($message['type'] ?? 'unknown');
            $media = is_array($message[$type] ?? null) ? $message[$type] : [];
            $caption = trim((string)($media['caption'] ?? $message['text']['body'] ?? ''));
            $mediaId = in_array($type, ['image','document'], true) ? (string)($media['id'] ?? '') : '';
            $case = waResolveCase((string)$profile['key'], $sender, (string)($message['context']['id'] ?? ''));
            $row = ['wamid'=>$wamid,'profile'=>$profile['key'],'sender'=>$sender,'direction'=>'inbound','type'=>$type,'name'=>$media['filename']??null,'caption'=>$caption,'media'=>$mediaId,'folder'=>$case['folder_id']??null,'case_no'=>$case['case_no']??null,'contact_type'=>$case['contact_type']??null,'status'=>$case?'received':'unassigned'];
            if (!waRecordMessage($row)) continue;
            if ($mediaId === '' || !$case) continue;
            try {
                $download = waDownloadMedia($mediaId, (string)$profile['connection']['token']);
                $name = waSafeFileName((string)($media['filename'] ?? 'Dokument'), (string)$download['mime']);
                $driveId = waDriveUpload((string)$case['folder_id'], $name, (string)$download['mime'], (string)$download['bytes'], $caption);
                $stmt = db()->prepare("UPDATE whatsapp_messages SET original_name=:name,drive_file_id=:drive,status='stored',error_text=NULL WHERE wamid=:wamid");
                $stmt->execute([':name'=>$name,':drive'=>$driveId,':wamid'=>$wamid]);
            } catch (Throwable $error) {
                $stmt = db()->prepare("UPDATE whatsapp_messages SET status='failed',error_text=:error WHERE wamid=:wamid");
                $stmt->execute([':error'=>mb_substr($error->getMessage(),0,1000),':wamid'=>$wamid]);
                error_log('[whatsapp] ' . $error->getMessage());
            }
        }
    }
    http_response_code(200); header('Content-Type: text/plain'); echo 'EVENT_RECEIVED'; exit;
}

$action = (string)($_GET['action'] ?? 'status');
if ($action === 'webhook') waHandleWebhook();

commonHeaders();
$user = requireAuth();
if (!in_array((string)($user['role'] ?? ''), ['administrator','projektleiter','pruefer','sachverstaendiger'], true)) apiError(403, 'Keine Berechtigung.');

if ($action === 'menu_access') {
    $ownProfile = svnetUserProfile($user);
    apiJson(['ok'=>true,'visible'=>in_array($ownProfile, ['christian','marc','holger'], true),'profile'=>$ownProfile]);
}

try {
    waEnsureSchema();
    $profileKey = waTargetProfile($user);
    $profile = waProfiles()[$profileKey];
    $connection = waConnection($profileKey);
    if ($action === 'webhook_setup') {
        if ((string)($user['role'] ?? '') !== 'administrator') apiError(403, 'Nur Administratoren dürfen die Webhook-Konfiguration abrufen.');
        $verifyToken = waEnv('WHATSAPP_VERIFY_TOKEN');
        if ($verifyToken === '') apiError(503, 'Das WhatsApp-Verifizierungstoken ist noch nicht eingerichtet.');
        apiJson([
            'ok'=>true,
            'callback_url'=>'https://www.sv-netzwerk.eu/intern/api/whatsapp-case.php?action=webhook',
            'verify_token'=>$verifyToken,
        ]);
    }
    if ($action === 'signup_config') {
        $appId = waEnv('WHATSAPP_META_APP_ID');
        $configId = waEnv('WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID');
        if ($appId === '' || $configId === '' || waEnv('WHATSAPP_APP_SECRET') === '') apiError(503, 'Die zentrale Meta-Einrichtung ist noch nicht abgeschlossen.');
        apiJson(['ok'=>true,'app_id'=>$appId,'config_id'=>$configId,'graph_version'=>waEnv('WHATSAPP_GRAPH_VERSION','v25.0'),'profile'=>$profileKey,'name'=>$profile['name'],'number'=>$profile['number']]);
    }
    if ($action === 'complete_signup') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') apiError(405, 'POST erforderlich.');
        $body = requestBody();
        $code = trim((string)($body['code'] ?? ''));
        $phoneId = trim((string)($body['phone_number_id'] ?? ''));
        $wabaId = trim((string)($body['waba_id'] ?? ''));
        if ($code === '' || !preg_match('/^[0-9]{5,30}$/', $phoneId) || !preg_match('/^[0-9]{5,30}$/', $wabaId)) apiError(400, 'Meta hat keine vollständigen Verbindungsdaten geliefert.');
        $appId = waEnv('WHATSAPP_META_APP_ID');
        $appSecret = waEnv('WHATSAPP_APP_SECRET');
        if ($appId === '' || $appSecret === '') apiError(503, 'Die zentrale Meta-Einrichtung ist noch nicht abgeschlossen.');
        $version = waEnv('WHATSAPP_GRAPH_VERSION','v25.0');
        $tokenResponse = waHttp('GET', 'https://graph.facebook.com/'.rawurlencode($version).'/oauth/access_token?'.http_build_query(['client_id'=>$appId,'client_secret'=>$appSecret,'code'=>$code]));
        $tokenData = json_decode($tokenResponse['body'], true);
        $accessToken = (string)($tokenData['access_token'] ?? '');
        if ($tokenResponse['status'] !== 200 || $accessToken === '') throw new RuntimeException('Meta konnte die WhatsApp-Freigabe nicht bestätigen.');
        $numberData = waGraph('GET', rawurlencode($phoneId).'?fields=display_phone_number,verified_name,status,platform_type,is_on_biz_app', null, $accessToken);
        $displayNumber = waNormalizePhone((string)($numberData['display_phone_number'] ?? ''));
        if ($displayNumber === '' || !hash_equals(waNormalizePhone((string)$profile['number']), $displayNumber)) throw new RuntimeException('Die ausgewählte Nummer gehört nicht zum angemeldeten Bearbeiterprofil.');
        if (($numberData['is_on_biz_app'] ?? null) !== true) throw new RuntimeException('Meta hat Coexistence mit der bestehenden WhatsApp-Business-App nicht bestätigt. Die Nummer wurde nicht übernommen.');
        waGraph('POST', rawurlencode($wabaId).'/subscribed_apps', null, $accessToken);
        $stmt = db()->prepare("INSERT INTO whatsapp_profile_connections(profile_key,phone_number_id,waba_id,access_token_ciphertext,display_phone_number,verified_name,connected_by,connected_at,updated_at)
            VALUES(:profile,:phone_id,:waba,:token,:display,:verified,:by,NOW(),NOW())
            ON DUPLICATE KEY UPDATE phone_number_id=VALUES(phone_number_id),waba_id=VALUES(waba_id),access_token_ciphertext=VALUES(access_token_ciphertext),display_phone_number=VALUES(display_phone_number),verified_name=VALUES(verified_name),connected_by=VALUES(connected_by),connected_at=NOW(),updated_at=NOW()");
        $stmt->execute([':profile'=>$profileKey,':phone_id'=>$phoneId,':waba'=>$wabaId,':token'=>waEncryptToken($accessToken),':display'=>$displayNumber,':verified'=>(string)($numberData['verified_name']??''),':by'=>(string)($user['email']??'')]);
        apiJson(['ok'=>true,'profile'=>$profileKey,'number'=>$displayNumber,'connected'=>true]);
    }
    if ($action === 'status') {
        $configured = waConfigured($connection);
        $readiness = $configured ? waReadiness($connection) : ['ready'=>false,'phone_status'=>'','template_status'=>'','state'=>''];
        $connected = (bool)$readiness['ready'];
        $onboardingAvailable = waEnv('WHATSAPP_META_APP_ID')!=='' && waEnv('WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID')!=='' && waEnv('WHATSAPP_APP_SECRET')!=='';
        $metaPrepared = $connection['phone_id']!=='' && $connection['waba_id']!=='';
        // Stored phone/WABA ids only mean that the number was prepared in Meta.
        // They are not evidence of a Meta review and must not block Embedded Signup.
        $pendingReview = false;
        $metaBusinessApproved = $profileKey === 'christian';
        $metaCoexistenceConnected = $profileKey === 'christian';
        $state = $configured
            ? (string)$readiness['state']
            : ($onboardingAvailable
                ? ($metaCoexistenceConnected ? 'Meta-Konto und Coexistence verbunden · Portalzugriff wird geprüft' : 'Meta-Coexistence noch nicht verbunden')
                : ($metaCoexistenceConnected ? 'Meta-Konto und Coexistence verbunden · Portal-App noch nicht vollständig eingerichtet' : 'Portal-App noch nicht mit Meta verbunden'));
        apiJson(['ok'=>true,'profile'=>$profileKey,'name'=>$profile['name'],'number'=>$profile['number'],'connected'=>$connected,'configured'=>$configured,'meta_prepared'=>$metaPrepared,'meta_business_verified'=>$metaBusinessApproved,'meta_account_status'=>$metaBusinessApproved?'APPROVED':'UNKNOWN','meta_coexistence_connected'=>$metaCoexistenceConnected,'pending_review'=>$pendingReview,'onboarding_available'=>$onboardingAvailable&&!$metaCoexistenceConnected,'phone_status'=>$readiness['phone_status'],'template_status'=>$readiness['template_status'],'state'=>$state]);
    }
    if ($action === 'recent') {
        $stmt = db()->prepare("SELECT wamid,sender_phone,direction,message_type,original_name,caption,folder_id,case_no,contact_type,status,error_text,received_at FROM whatsapp_messages WHERE profile_key=:p ORDER BY received_at DESC LIMIT 30");
        $stmt->execute([':p'=>$profileKey]);
        apiJson(['ok'=>true,'profile'=>$profileKey,'messages'=>$stmt->fetchAll()]);
    }
    if ($action === 'assign_message') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') apiError(405, 'POST erforderlich.');
        $body = requestBody();
        $folderId = trim((string)($body['folder_id'] ?? ''));
        $wamid = trim((string)($body['wamid'] ?? ''));
        $caseNo = trim((string)($body['case_no'] ?? ''));
        requireCaseFolderAccess($folderId, $user);
        if ($wamid === '') apiError(400, 'WhatsApp-Nachricht fehlt.');
        $stmt = db()->prepare("SELECT * FROM whatsapp_messages WHERE wamid=:wamid AND profile_key=:profile AND direction='inbound' LIMIT 1");
        $stmt->execute([':wamid'=>$wamid,':profile'=>$profileKey]);
        $message = $stmt->fetch();
        if (!is_array($message)) apiError(404, 'WhatsApp-Nachricht wurde für dieses Profil nicht gefunden.');
        $driveId = (string)($message['drive_file_id'] ?? '');
        $savedName = (string)($message['original_name'] ?? '');
        if ($driveId === '' && (string)($message['media_id'] ?? '') !== '') {
            $download = waDownloadMedia((string)$message['media_id'], (string)$connection['token']);
            $savedName = waSafeFileName((string)($message['original_name'] ?? 'Dokument'), (string)$download['mime']);
            $driveId = waDriveUpload($folderId, $savedName, (string)$download['mime'], (string)$download['bytes'], (string)($message['caption'] ?? ''));
        }
        $stmt = db()->prepare("UPDATE whatsapp_messages SET folder_id=:folder,case_no=:case_no,drive_file_id=:drive,original_name=:name,status=:status,error_text=NULL WHERE wamid=:wamid AND profile_key=:profile");
        $stmt->execute([':folder'=>$folderId,':case_no'=>$caseNo,':drive'=>$driveId!==''?$driveId:null,':name'=>$savedName!==''?$savedName:null,':status'=>$driveId!==''?'stored':'assigned',':wamid'=>$wamid,':profile'=>$profileKey]);
        waLinkCase($profileKey, (string)$message['sender_phone'], (string)($message['contact_type'] ?: 'kontakt'), $folderId, $caseNo, '', (string)($user['email'] ?? ''));
        apiJson(['ok'=>true,'wamid'=>$wamid,'folder_id'=>$folderId,'status'=>$driveId!==''?'stored':'assigned']);
    }
    if ($action === 'send_message') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') apiError(405, 'POST erforderlich.');
        if (!waConfigured($connection)) apiError(409, 'WhatsApp ist für dieses Bearbeiterprofil noch nicht über Meta-Coexistence verbunden.');
        $readiness = waReadiness($connection);
        if (!$readiness['ready']) apiError(409, 'WhatsApp ist bei Meta noch nicht versandbereit: ' . $readiness['state'] . '.');
        $body = requestBody();
        $phone = waNormalizePhone((string)($body['phone'] ?? ''));
        $message = trim((string)($body['message'] ?? ''));
        $folderId = trim((string)($body['folder_id'] ?? ''));
        $caseNo = mb_substr(trim((string)($body['case_no'] ?? '')), 0, 190);
        if ($phone === '') apiError(400, 'Bitte eine gültige WhatsApp-Rufnummer angeben.');
        if ($message === '') apiError(400, 'Bitte eine Nachricht eingeben.');
        if (mb_strlen($message) > 4096) apiError(400, 'Die WhatsApp-Nachricht darf höchstens 4.096 Zeichen enthalten.');
        if ($folderId !== '') requireCaseFolderAccess($folderId, $user);
        $wamid = waSendText($connection, $phone, $message);
        if ($folderId !== '') waLinkCase($profileKey, $phone, 'kontakt', $folderId, $caseNo, $wamid, (string)($user['email'] ?? ''));
        waRecordMessage(['wamid'=>$wamid,'profile'=>$profileKey,'sender'=>$phone,'direction'=>'outbound','type'=>'text','caption'=>$message,'folder'=>$folderId!==''?$folderId:null,'case_no'=>$caseNo!==''?$caseNo:null,'contact_type'=>'kontakt','status'=>'sent']);
        apiJson(['ok'=>true,'profile'=>$profileKey,'phone'=>$phone,'wamid'=>$wamid,'case_no'=>$caseNo]);
    }
    if ($action === 'send_appointment') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') apiError(405, 'POST erforderlich.');
        if (!waConfigured($connection)) apiError(409, 'WhatsApp ist für dieses Bearbeiterprofil noch nicht über Meta-Coexistence verbunden.');
        $readiness = waReadiness($connection);
        if (!$readiness['ready']) apiError(409, 'WhatsApp ist bei Meta noch nicht versandbereit: ' . $readiness['state'] . '.');
        $body = requestBody();
        $folderId = trim((string)($body['folder_id'] ?? ''));
        requireCaseFolderAccess($folderId, $user);
        $caseNo = trim((string)($body['case_no'] ?? ''));
        $date = trim((string)($body['date'] ?? ''));
        $time = trim((string)($body['time'] ?? ''));
        $address = trim((string)($body['address'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time) || $address === '') apiError(400, 'Terminzeit oder Besichtigungsadresse fehlt.');
        $recipients = is_array($body['recipients'] ?? null) ? $body['recipients'] : [];
        if (!$recipients) apiError(400, 'Bitte VN oder Sanierer für WhatsApp auswählen.');
        $sent = [];
        foreach ($recipients as $recipient) {
            $type = in_array((string)($recipient['type'] ?? ''), ['vn','sanierer'], true) ? (string)$recipient['type'] : '';
            $phone = waNormalizePhone((string)($recipient['phone'] ?? ''));
            if ($type === '' || $phone === '') throw new RuntimeException('Eine ausgewählte WhatsApp-Rufnummer ist ungültig.');
            $wamid = waSendAppointment($profile, $connection, $phone, ['case_no'=>$caseNo!==''?$caseNo:'ohne Schaden-Nr.','date_time'=>$date.' · '.$time.' Uhr','address'=>$address]);
            waLinkCase($profileKey, $phone, $type, $folderId, $caseNo, $wamid, (string)($user['email'] ?? ''));
            waRecordMessage(['wamid'=>$wamid,'profile'=>$profileKey,'sender'=>$phone,'direction'=>'outbound','type'=>'template','caption'=>'Ortstermin '.$date.' '.$time,'folder'=>$folderId,'case_no'=>$caseNo,'contact_type'=>$type,'status'=>'sent']);
            $sent[] = ['type'=>$type,'phone'=>$phone,'wamid'=>$wamid];
        }
        apiJson(['ok'=>true,'profile'=>$profileKey,'sent'=>$sent]);
    }
    apiError(400, 'Unbekannte WhatsApp-Aktion.');
} catch (Throwable $error) {
    apiError(503, $error->getMessage());
}
